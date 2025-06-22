<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis; // 引入 Redis Facade
use App\Jobs\ProcessOrder;
use App\Models\Product; // 確保引入 Product 模型
use App\Models\Order; // 確保引入 Order 模型
use Illuminate\Support\Str; // 用於生成 UUID
use App\Http\Controllers\Controller; // 引入基礎控制器，假設它有 successResponse/errorResponse
use Illuminate\Support\Facades\Log; // for logging

class OrderController extends Controller
{
    /**
     * 提交訂單
     * 使用 Redis 進行原子性庫存預扣，防止超賣
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function placeOrder(Request $request)
    {
        // 1. 驗證請求數據
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product_id = $request->input('product_id');
        $quantity = $request->input('quantity');
        $user_id = auth()->id(); // 獲取當前認證用戶 ID
        $order_uuid = (string) Str::uuid(); // 生成唯一訂單 UUID

        // 2. Redis 原子性庫存預扣 (核心防超賣邏輯)
        $redisStockKey = "product:stock:{$product_id}";
        $redisLockedStockKey = "product:stock:locked:{$product_id}";
        $redisDeductionId = (string) Str::uuid(); // 為本次預扣生成唯一 ID

        // 嘗試從資料庫載入並設定初始庫存到 Redis (如果 Redis 中沒有)
        if (Redis::get($redisStockKey) === null) {
            $product = Product::find($product_id);
            if (!$product) {
                return $this->errorResponse('商品不存在或已下架', 404);
            }
            // 使用 SETNX 確保只有一個請求能設定初始值，避免多個請求同時從 DB 載入
            if (Redis::setnx($redisStockKey, $product->stock)) {
                Log::info("Initialized Redis stock for product {$product_id} with {$product->stock} from DB.");
            } else {
                // 如果設定失敗，表示已有其他請求設定，等待片刻後重試或直接獲取
                // 這裡簡化處理，直接繼續，因為後面會 DECRBY
            }
        }

        // 使用 Lua Script 進行原子性扣除和鎖定
        $script = "
            local stockKey = KEYS[1]
            local lockedKey = KEYS[2]
            local quantity = tonumber(ARGV[1])
            local deductionId = ARGV[2]

            local currentStock = tonumber(redis.call('GET', stockKey) or '0')

            if currentStock >= quantity then
                redis.call('DECRBY', stockKey, quantity)
                redis.call('HINCRBY', lockedKey, deductionId, quantity) -- 將預扣數量記錄到 locked key
                return deductionId
            else
                return false
            end
        ";
        $deductionResult = Redis::eval($script, 2, $redisStockKey, $redisLockedStockKey, $quantity, $redisDeductionId);

        if ($deductionResult === false) {
            return $this->errorResponse('庫存不足，訂單提交失敗', 400);
        }

        // 3. 創建訂單並設置狀態為 pending
        // 將 redis_deduction_id 存入訂單，以便 Job 處理失敗時回滾
        try {
            $order = Order::create([
                'order_id' => $order_uuid, // 使用 UUID
                'user_id' => $user_id,
                'product_id' => $product_id,
                'quantity' => $quantity,
                'total_price' => $quantity * (Product::find($product_id)->price ?? 0), // 這裡再次獲取價格，也可以從 $product 取得
                'status' => 'pending',
                'redis_deduction_id' => $redisDeductionId, // 保存 Redis 預扣 ID
            ]);
        } catch (\Exception $e) {
            // 如果訂單創建失敗，需要釋放 Redis 中預扣的庫存
            $this->releaseRedisStock($product_id, $redisDeductionId);
            Log::error("建立臨時訂單失敗，已嘗試釋放 Redis 預扣庫存: " . $e->getMessage(), [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'quantity' => $quantity,
                'deduction_id' => $redisDeductionId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('訂單建立失敗，請重試', 500);
        }

        // 4. 將訂單推送到佇列異步處理
        // onQueue 確保 Job 發送到 SQS (透過 config/queue.php 的 sqs 連接)
        try {
            ProcessOrder::dispatch($order->id)->onQueue(config('queue.connections.sqs.queue'));
            Log::info("訂單已推入佇列", ['order_id' => $order->id, 'redis_deduction_id' => $redisDeductionId]);
        } catch (\Exception $e) {
            // 如果推入佇列失敗，則更新訂單狀態為 failed，並釋放 Redis 庫存
            $order->update(['status' => 'failed', 'failed_reason' => '佇列推送失敗']);
            $this->releaseRedisStock($product_id, $redisDeductionId);
            Log::error("推送訂單任務到佇列失敗: " . $e->getMessage(), ['order_id' => $order->id]);
            return $this->errorResponse('訂單處理系統忙碌，請稍後重試', 503);
        }

        // 5. 立即回覆，減少用戶等待時間
        return $this->successResponse('訂單已提交，正在處理中', ['order_uuid' => $order_uuid, 'status' => 'pending'], 202);
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
}
