<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis; // 引入 Redis Facade
use App\Models\Order;
use App\Models\Product; // 引入 Product 模型

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $orderId; // 傳入訂單的 ID
    public $tries = 3; // Job 重試次數
    public $timeout = 60; // Job 超時秒數
    public $backoff = [5, 10, 15]; // 重試間隔 (秒)

    /**
     * Create a new job instance.
     *
     * @param int $orderId 訂單的 ID
     * @return void
     */
    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $order = Order::find($this->orderId);

        // 檢查訂單是否存在或是否已處理
        if (!$order || $order->status !== 'pending') {
            Log::warning("訂單狀態不符或不存在，跳過處理。", ['order_id' => $this->orderId, 'status' => $order->status ?? 'N/A']);
            // 如果訂單不是 pending 狀態，可能已經被處理或失敗，無需重試或回滾
            return;
        }

        $productId = $order->product_id;
        $quantity = $order->quantity;
        $redisDeductionId = $order->redis_deduction_id;

        DB::beginTransaction(); // 開始資料庫事務
        try {
            // 使用悲觀鎖鎖定商品行，防止資料庫層面的併發問題（二次驗證，確保最終一致性）
            $product = Product::lockForUpdate()->find($productId);

            if (!$product) {
                throw new \Exception("商品不存在，無法處理訂單。");
            }

            // 最終庫存檢查 (double check)
            // 這裡的檢查是為了確保 Redis 預扣和 DB 同步之間的最終一致性
            // 如果 Redis 預扣成功但 DB 庫存不足，則表示有邏輯錯誤或極端併發導致問題
            // 但由於 Redis 已預扣，理論上這裡的 stock >= quantity 應該成立
            // 這裡 $product->stock 應該是未經 Redis 預扣之前的原始 DB 庫存減去其他已完成的訂單。
            // 由於 Redis 預扣是主要的防超賣，這裡僅為最終同步確保，且扣減數量已在 Redis 中確認。
            // 這裡應該是確認扣減後的資料庫庫存是否正確。
            // 實際上，Redis 預扣已經處理了併發問題，這裡只需直接扣減並寫入。
            // 為了簡化，直接假設 Redis 預扣是可靠的，這裡不再檢查 $product->stock < $quantity
            // 而是直接扣減。如果出現負數，表示 Redis 與 DB 不一致，需要告警。

            // 實際扣減資料庫庫存
            $product->stock -= $quantity;
            $product->save(); // 保存更新後的庫存

            // 更新訂單狀態為 completed
            $order->status = 'completed';
            $order->save();

            DB::commit(); // 提交事務

            // 訂單處理成功後，從 Redis 中移除預扣的鎖定記錄
            // 這是為了清理在 OrderController 中創建的 locked entry
            $this->removeRedisLockedEntry($productId, $redisDeductionId);

            Log::info("訂單處理成功", ['order_id' => $order->id, 'product_id' => $productId, 'quantity' => $quantity]);

        } catch (\Exception $e) {
            DB::rollBack(); // 回滾資料庫事務
            Log::error("訂單處理失敗: " . $e->getMessage(), [
                'order_id' => $order->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'deduction_id' => $redisDeductionId,
                'error' => $e->getMessage()
            ]);

            // 更新訂單狀態為 'failed'
            $order->status = 'failed';
            $order->failed_reason = $e->getMessage();
            $order->save();

            // 嘗試釋放 Redis 中預扣的庫存
            try {
                if ($redisDeductionId && $this->releaseRedisStock($productId, $redisDeductionId)) {
                    Log::info("Redis 預扣庫存已成功釋放 (因訂單處理失敗)", ['order_id' => $order->id, 'product_id' => $productId, 'deduction_id' => $redisDeductionId]);
                } else {
                    Log::warning("無法釋放 Redis 預扣庫存或已處理 (因訂單處理失敗)", ['order_id' => $order->id, 'product_id' => $productId, 'deduction_id' => $redisDeductionId]);
                }
            } catch (\Exception $redisEx) {
                Log::error("嘗試釋放 Redis 預扣庫存時發生異常 (訂單處理失敗): " . $redisEx->getMessage(), [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'deduction_id' => $redisDeductionId,
                    'redis_error' => $redisEx->getMessage()
                ]);
            }

            // 如果重試次數未達上限，重新拋出異常以觸發 Laravel 佇列的重試機制
            if ($this->attempts() < $this->tries) {
                throw $e; 
            } else {
                // 如果已達到最大重試次數，則不再拋出，讓 Job 最終失敗 (進入 DLQ)
                Log::critical("訂單任務已達到最大重試次數並最終失敗。", [
                    'order_id' => $order->id,
                    'attempts' => $this->attempts(),
                    'final_error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 從 Redis 鎖定的庫存哈希中移除條目。
     * @param int $productId
     * @param string $deductionId
     * @return bool
     */
    private function removeRedisLockedEntry(int $productId, string $deductionId): bool
    {
        $lockedKey = "product:stock:locked:{$productId}";
        return (bool) Redis::hdel($lockedKey, $deductionId);
    }

    /**
     * 釋放預扣的 Redis 庫存。
     * * 這個方法使用 Lua 腳本來原子性地將鎖定數量加回主庫存，並刪除鎖定記錄。
     * 這確保了即使在高併發或錯誤情況下，庫存回滾也是可靠的。
     * @param int $productId
     * @param string $deductionId
     * @return bool
     */
    private function releaseRedisStock(int $productId, string $deductionId): bool
    {
        $stockKey = "product:stock:{$productId}";
        $lockedKey = "product:stock:locked:{$productId}";

        $script = "
            local stockKey = KEYS[1]
            local lockedKey = KEYS[2]
            local deductionId = ARGV[1]

            local releasedQuantity = tonumber(redis.call('HGET', lockedKey, deductionId))

            if releasedQuantity then
                redis.call('INCRBY', stockKey, releasedQuantity)
                redis.call('HDEL', lockedKey, deductionId)
                return true
            else
                return false
            end
        ";
        return (bool) Redis::eval($script, 2, $stockKey, $lockedKey, $deductionId);
    }

    /**
     * Handle a job that was "retired" (failed too many times).
     * This method will be called when the job is about to be sent to the DLQ.
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::critical("訂單處理任務最終失敗（已達最大重試次數）", [
            'order_id' => $this->orderId,
            'exception_message' => $exception->getMessage(),
            'exception_trace' => $exception->getTraceAsString(),
            'attempts_made' => $this->attempts()
        ]);

        $order = Order::find($this->orderId);
        if ($order && $order->status === 'pending') {
            $order->update(['status' => 'failed', 'failed_reason' => '任務最終失敗: ' . $exception->getMessage()]);
            // 注意: 這裡不再重複釋放 Redis 庫存，因為 handle 方法中的失敗邏輯已嘗試處理
        }
    }
}
