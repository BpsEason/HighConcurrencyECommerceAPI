<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Jobs\ProcessOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 清空 Redis 庫存，確保每次測試都從乾淨的狀態開始
        Redis::flushdb();
    }

    /**
     * 測試當庫存充足時，訂單 Job 能夠成功處理。
     *
     * @return void
     */
    public function testOrderJobProcessesSuccessfullyWhenStockIsSufficient()
    {
        // 創建測試用戶和產品
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 100, 'price' => 10.00]);
        // 在 Redis 中設定初始庫存
        Redis::set("product:stock:{$product->id}", $product->stock);

        $quantity = 10;
        $orderUuid = (string) Str::uuid();
        
        // 模擬 OrderController 中 Redis 預扣成功
        // 使用 Lua Script 進行原子性扣除和鎖定 (與 OrderController 中保持一致)
        $redisDeductionId = (string) Str::uuid();
        $script = "
            local stockKey = KEYS[1]
            local lockedKey = KEYS[2]
            local quantity = tonumber(ARGV[1])
            local deductionId = ARGV[2]

            local currentStock = tonumber(redis.call('GET', stockKey) or '0')

            if currentStock >= quantity then
                redis.call('DECRBY', stockKey, quantity)
                redis.call('HINCRBY', lockedKey, deductionId, quantity)
                return deductionId
            else
                return false
            end
        ";
        $deductionResult = Redis::eval($script, 2, "product:stock:{$product->id}", "product:stock:locked:{$product->id}", $quantity, $redisDeductionId);
        $this->assertNotFalse($deductionResult, "Redis deduction should succeed for test setup.");


        // 創建 pending 狀態的訂單，包含 Redis 預扣 ID
        $order = Order::create([
            'order_id' => $orderUuid,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'total_price' => $quantity * $product->price,
            'status' => 'pending',
            'redis_deduction_id' => $redisDeductionId,
        ]);

        // 調用 Job
        $job = new ProcessOrder($order->id);
        $job->handle();

        // 驗證資料庫庫存已扣除
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => $product->stock - $quantity,
        ]);

        // 驗證訂單已成功創建並標記為 completed
        $this->assertDatabaseHas('orders', [
            'order_id' => $orderUuid,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'status' => 'completed',
        ]);

        // 驗證 Redis locked entry 已被移除
        $this->assertFalse(Redis::hexists("product:stock:locked:{$product->id}", $redisDeductionId));
    }

    /**
     * 測試當資料庫庫存不足時（應急情況，Redis 預扣後 DB 層面再次驗證），訂單 Job 處理失敗並回滾 Redis 庫存。
     *
     * @return void
     */
    public function testOrderJobFailsAndRollsBackRedisWhenDBStockIsInsufficient()
    {
        Log::shouldReceive('error')->atLeast()->once(); // 預期會記錄錯誤

        // 創建測試用戶和產品，初始庫存為 50
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 50, 'price' => 10.00]);
        Redis::set("product:stock:{$product->id}", 40); // 模擬 Redis 庫存較少，導致 Job 處理時發現不足

        $quantity = 60; // 嘗試購買 60 件，但 Redis 預扣可能允許 (因為測試環境，但 Job 會失敗)
        $orderUuid = (string) Str::uuid();
        $redisDeductionId = (string) Str::uuid(); // 為本次預扣生成唯一 ID

        // 模擬 OrderController 中 Redis 預扣，但實際 DB 庫存可能更少
        // 這裡我們直接操作 Redis，模擬 API 層的扣減
        Redis::decrby("product:stock:{$product->id}", $quantity); // 將 Redis 庫存變為負數
        Redis::hset("product:stock:locked:{$product->id}", $redisDeductionId, $quantity);


        $order = Order::create([
            'order_id' => $orderUuid,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'total_price' => $quantity * $product->price,
            'status' => 'pending',
            'redis_deduction_id' => $redisDeductionId,
        ]);

        // 確保 Job 執行時會拋出異常 (即使被捕獲，也應該是拋出的)
        $this->expectException(\Exception::class); // Job 應該拋出異常

        // 調用 Job
        $job = new ProcessOrder($order->id);
        $job->handle();

        // 驗證資料庫庫存未變（因為事務回滾）
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 50, // 庫存應該保持不變
        ]);

        // 驗證訂單狀態變為 failed
        $this->assertDatabaseHas('orders', [
            'order_id' => $orderUuid,
            'status' => 'failed',
        ]);

        // 驗證 Redis 庫存已回滾 (從負數回滾到正確值)
        $this->assertEquals(50, Redis::get("product:stock:{$product->id}"));
        // 驗證 Redis locked entry 已被移除
        $this->assertFalse(Redis::hexists("product:stock:locked:{$product->id}", $redisDeductionId));
    }

    /**
     * 測試當產品不存在時，訂單 Job 處理失敗並回滾 Redis 庫存。
     *
     * @return void
     */
    public function testOrderJobFailsAndRollsBackRedisWhenProductDoesNotExist()
    {
        Log::shouldReceive('error')->atLeast()->once(); // 預期會記錄錯誤

        $user = User::factory()->create();
        $nonExistentProductId = 999;
        $quantity = 10;
        $orderUuid = (string) Str::uuid();
        $redisDeductionId = (string) Str::uuid();

        // 模擬 Redis 已預扣了庫存 (即使產品不存在，因為 API 層可能處理不嚴謹)
        Redis::set("product:stock:{$nonExistentProductId}", -10); // 模擬預扣到負數
        Redis::hset("product:stock:locked:{$nonExistentProductId}", $redisDeductionId, $quantity);

        $order = Order::create([
            'order_id' => $orderUuid,
            'user_id' => $user->id,
            'product_id' => $nonExistentProductId, // 不存在的商品
            'quantity' => $quantity,
            'total_price' => 100.00,
            'status' => 'pending',
            'redis_deduction_id' => $redisDeductionId,
        ]);

        // 確保 Job 執行時會拋出異常
        $this->expectException(\Exception::class);

        // 調用 Job
        $job = new ProcessOrder($order->id);
        $job->handle();

        // 驗證訂單狀態變為 failed
        $this->assertDatabaseHas('orders', [
            'order_id' => $orderUuid,
            'status' => 'failed',
        ]);

        // 驗證 Redis 庫存已回滾 (從負數回滾到 0)
        $this->assertEquals(0, Redis::get("product:stock:{$nonExistentProductId}"));
        // 驗證 Redis locked entry 已被移除
        $this->assertFalse(Redis::hexists("product:stock:locked:{$nonExistentProductId}", $redisDeductionId));
    }
}
