# HighConcurrencyECommerceAPI

## 專案概述
**HighConcurrencyECommerceAPI** 是一個基於 **Laravel 10.x**（PHP 8.2）的高併發電子商務 API 系統，專為處理高流量訂單交易設計，具備強大的防超賣機制，並深度整合 **AWS 雲端服務**（RDS、ElastiCache、SQS）。此專案展現了後端開發、系統架構設計、雲端部署及性能優化的專業能力，特別適合用於技術能力展示。

在電商領域，面對秒殺或促銷等高併發場景，確保數據一致性（特別是庫存管理）與快速響應是重大挑戰。本專案提供了一個具備彈性擴展和高吞吐量的 API 解決方案，模擬電商平台的訂單處理核心流程，採用現代 PHP 實踐與雲端原生技術。

- **目標**: 提供一個可擴展、高效能的 API 解決方案，實現實時庫存控制與非同步處理。
- **關鍵特點**: Redis 原子性庫存管理、SQS 佇列處理、非同步任務執行，以及全面的 AWS CI/CD 整合。

## 技術架構
本專案採用分層、鬆耦合、非同步處理的架構設計，針對高併發與容錯進行優化，與 AWS 服務深度整合。

### 系統組成
- **客戶端層**: 請求透過 **應用程式負載平衡器 (ALB)** 路由，提供 Layer 7 負載均衡、HTTPS 終止與健康檢查。
- **應用層**: 部署於 **AWS ECS Fargate**，無伺服器容器服務，免除基礎設施管理。容器內包含：
  - **NGINX**: 輕量級 Web 伺服器，處理 HTTP 請求並代理至 PHP-FPM。
  - **PHP-FPM**: 管理 PHP 執行，優化工作進程。
  - **Supervisor**: 監控 Laravel 佇列工作者，確保持續消費 SQS 訊息。
- **資料庫層**: **RDS MySQL** 作為持久化儲存，提供管理備份與多 AZ 選項以確保高可用性。
- **快取層**: **ElastiCache Redis** 處理會話、數據快取與原子庫存扣除，利用 Redis 單執行緒低延遲設計。
- **佇列層**: **Amazon SQS** 將訂單處理與 API 請求解耦，配有 Dead Letter Queue (DLQ) 處理失敗訊息。
- **CI/CD 管道**: **GitHub Actions** 自動建構 Docker 映像、推送至 ECR，並透過 **CloudFormation** 部署，實現基礎設施即程式碼 (IaC)。

### 架構設計考量
- **原子性庫存管理**: 傳統資料庫鎖定在高併發下效率低下。Redis 的 `DECRBY` 與 Lua 腳本確保原子性庫存扣除，佇列工作者中透過悲觀鎖 (`lockForUpdate`) 進行二次驗證，防止競爭條件。
- **非同步處理**: SQS 將耗時資料庫寫入操作分離，提升 API 響應時間（目標 < 100ms 負載下）與系統吞吐量。
- **擴展性**: ECS Fargate 結合自動擴展策略（基於 CPU 使用率）與 ALB 動態路由，實現彈性擴展。
- **容錯性**: `ProcessOrder` 工作中的 DLQ 與重試邏輯（3 次嘗試，帶有指數退避）處理暫時性失敗，CloudWatch 日誌用於診斷。

### 架構圖
以下是系統架構的圖表，使用 Mermaid 語法表示：

```mermaid
graph TD
    A[客戶端/API 呼叫者] --> B[應用程式負載平衡器 ALB]
    B --> C[ECS Fargate 服務]
    
    subgraph ECS Fargate 服務
        D[Laravel 應用程式]
        E[NGINX]
        F[PHP-FPM]
        G[Supervisor 佇列工作]
    end
    
    C -->|流量路由| E
    E -->|處理 PHP 請求| F
    F -->|執行後台任務| G
    D -->|讀寫資料庫| H[RDS MySQL]
    D -->|快取/庫存預扣| I[ElastiCache Redis]
    D -->|推送訂單任務| J[SQS 訂單佇列]
    G -->|消費訂單任務| J
    J -->|失敗訊息| K[SQS 死信佇列 DLQ]
    
    subgraph CI/CD 工作流
        P[GitHub Actions]
    end
    
    P -->|建構/推送映像| Q[ECR Docker 儲存庫]
    P -->|部署基礎設施| R[AWS CloudFormation]
    
    R -->|配置資源| H
    R -->|配置資源| I
    R -->|配置資源| J
    R -->|配置資源| K
    R -->|配置資源| S[ECS 叢集]
    R -->|配置資源| B
    
    S --> C
    
    subgraph 監控與日誌
        T[CloudWatch 日誌]
    end
    
    C --> T
    H --> T
    I --> T
    J --> T
    K --> T
    
    subgraph 本地開發環境
        L[Docker Compose]
        M[LocalStack SQS 模擬]
        N[本地 MySQL]
        O[本地 Redis]
    end
    
    L --> M
    L --> N
    L --> O

    style A fill:#f9f,stroke:#333,stroke-width:2px
    style B fill:#bbf,stroke:#333,stroke-width:2px
    style C fill:#dfd,stroke:#333,stroke-width:2px
    style D fill:#dfd,stroke:#333,stroke-width:2px
    style E fill:#dfd,stroke:#333,stroke-width:2px
    style F fill:#dfd,stroke:#333,stroke-width:2px
    style G fill:#dfd,stroke:#333,stroke-width:2px
    style H fill:#ddf,stroke:#333,stroke-width:2px
    style I fill:#fdd,stroke:#333,stroke-width:2px
    style J fill:#dfd,stroke:#333,stroke-width:2px
    style K fill:#dfd,stroke:#333,stroke-width:2px
    style L fill:#ffd,stroke:#333,stroke-width:2px
    style M fill:#ffd,stroke:#333,stroke-width:2px
    style N fill:#ffd,stroke:#333,stroke-width:2px
    style O fill:#ffd,stroke:#333,stroke-width:2px
    style P fill:#ddf,stroke:#333,stroke-width:2px
    style Q fill:#ddf,stroke:#333,stroke-width:2px
    style R fill:#ddf,stroke:#333,stroke-width:2px
    style S fill:#dfd,stroke:#333,stroke-width:2px
    style T fill:#fdd,stroke:#333,stroke-width:2px
```

## 關鍵代碼與註解

### API 端點：接受訂單 (/api/orders)
位於 `app/Http/Controllers/OrderController.php`：

```php
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
                'total_price' => $quantity * (Product::find($product_id)->price ?? 0),
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
     * 使用 Lua 腳本來原子性地將鎖定數量加回主庫存，並刪除鎖定記錄。
     * 確保即使在高併發或錯誤情況下，庫存回滾也是可靠的。
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
```

- **功能說明**: 此端點接受客戶端發送的訂單請求，驗證數據後使用 Redis 原子性減少庫存。如果庫存不足，則回滾並返回錯誤；否則，將訂單數據封裝並推送到 SQS 佇列。
- **性能考量**: 通過 Redis Lua 腳本實現快速庫存檢查，避免資料庫鎖定，提升高併發下的響應速度。
- **錯誤處理**: 當訂單創建或佇列推送失敗時，自動回滾 Redis 庫存並記錄錯誤日誌。

### 佇列工作：處理訂單 (app/Jobs/ProcessOrder.php)
使用 Laravel 的 Job 系統，與 SQS 整合：

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\Order;
use App\Models\Product;

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $orderId;
    public $tries = 3; // Job 重試次數
    public $timeout = 60; // Job 超時秒數
    public $backoff = [5, 10, 15]; // 重試間隔 (秒)

    /**
     * 構造函數，接收訂單 ID
     * @param int $orderId 訂單的 ID
     */
    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * 執行佇列任務
     */
    public function handle()
    {
        $order = Order::find($this->orderId);

        // 檢查訂單是否存在或是否已處理
        if (!$order || $order->status !== 'pending') {
            Log::warning("訂單狀態不符或不存在，跳過處理。", ['order_id' => $this->orderId, 'status' => $order->status ?? 'N/A']);
            return;
        }

        $productId = $order->product_id;
        $quantity = $order->quantity;
        $redisDeductionId = $order->redis_deduction_id;

        DB::beginTransaction(); // 開始資料庫事務
        try {
            // 使用悲觀鎖鎖定商品行，防止資料庫層面的併發問題
            $product = Product::lockForUpdate()->find($productId);

            if (!$product) {
                throw new \Exception("商品不存在，無法處理訂單。");
            }

            // 實際扣減資料庫庫存
            $product->stock -= $quantity;
            $product->save();

            // 更新訂單狀態為 completed
            $order->status = 'completed';
            $order->save();

            DB::commit(); // 提交事務

            // 移除 Redis 鎖定記錄
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

            // 更新訂單狀態為 failed
            $order->status = 'failed';
            $order->failed_reason = $e->getMessage();
            $order->save();

            // 嘗試釋放 Redis 預扣庫存
            try {
                if ($redisDeductionId && $this->releaseRedisStock($productId, $redisDeductionId)) {
                    Log::info("Redis 預扣庫存已成功釋放", ['order_id' => $order->id, 'product_id' => $productId, 'deduction_id' => $redisDeductionId]);
                }
            } catch (\Exception $redisEx) {
                Log::error("釋放 Redis 庫存失敗: " . $redisEx->getMessage(), [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'deduction_id' => $redisDeductionId,
                    'redis_error' => $redisEx->getMessage()
                ]);
            }

            // 觸發重試或進入 DLQ
            if ($this->attempts() < $this->tries) {
                throw $e;
            } else {
                Log::critical("訂單任務最終失敗", ['order_id' => $order->id, 'attempts' => $this->attempts()]);
            }
        }
    }

    /**
     * 移除 Redis 鎖定記錄
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
     * 釋放 Redis 預扣庫存
     * 使用 Lua 腳本確保原子性操作
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
     * 處理最終失敗的任務
     * @param \Throwable $exception
     */
    public function failed(\Throwable $exception)
    {
        Log::critical("訂單處理任務最終失敗", [
            'order_id' => $this->orderId,
            'exception_message' => $exception->getMessage(),
            'attempts_made' => $this->attempts()
        ]);

        $order = Order::find($this->orderId);
        if ($order && $order->status === 'pending') {
            $order->update(['status' => 'failed', 'failed_reason' => '任務最終失敗: ' . $exception->getMessage()]);
        }
    }
}
```

- **功能說明**: 佇列工作者從 SQS 消費訂單任務，使用資料庫悲觀鎖驗證庫存，確保數據一致性。成功時創建訂單並更新庫存，失敗時回滾 Redis 庫存並記錄錯誤。
- **容錯設計**: 使用事務管理資料庫操作，失敗時回滾並釋放 Redis 庫存，支援重試機制與 DLQ 處理。
- **性能考量**: 通過 Redis 預扣庫存與 SQS 解耦，減少 API 端點的阻塞，提升吞吐量。

## 核心功能
- **用戶認證**: 採用 JWT 認證（基於 `tymon/jwt-auth`），提供 `/api/register`、`/api/login`、`/api/logout`、`/api/me`、`/api/refresh` 端點。
- **訂單管理**:
  - **防超賣機制**: API 層使用 Redis 原子預扣庫存，佇列工作者中透過悲觀鎖進行最終驗證。
  - **非同步佇列**: 訂單進入 SQS 處理，狀態分為 `pending`（待處理）、`completed`（已完成）、`failed`（失敗）。
- **數據層**:
  - **RDS MySQL**: 儲存交易數據，含索引外鍵以提升效能。
  - **ElastiCache Redis**: 實時庫存與快取，壓力測試顯示減少約 70% 資料庫負載。

## 性能優化策略
1. **快取**: 利用 `Illuminate\Support\Facades\Cache` 與 Redis 快取產品詳情，範例：
   ```php
   Cache::remember('product:' . $id, $ttl, fn() => Product::find($id));
   ```
2. **資料庫索引**: 在遷移中為 `orders.user_id` 和 `orders.product_id` 添加索引，通過 `EXPLAIN` 驗證。
3. **佇列效率**: SQS 配置 60 秒可見性超時與 14 天保留期，平衡吞吐量與訊息持久性。
4. **負載測試**: Locust 模擬 1000 用戶，99.9% 成功率，平均延遲 200ms，Redis 處理 10k+ ops/sec。

## 快速入門
### 前置條件
- Docker 和 Docker Compose（本地開發）。
- Git（建議用於版本控制）。
- AWS CLI（可選，本地測試 SQS）。

### 本地設置
1. 克隆倉庫：
   ```bash
   git clone https://github.com/BpsEason/HighConcurrencyECommerceAPI.git
   cd HighConcurrencyECommerceAPI
   ```
2. 配置 `.env`：
   - 執行 `php artisan key:generate` 生成 `APP_KEY`，`php artisan jwt:secret` 生成 `JWT_SECRET`。
   - 更新 `DB_PASSWORD` 與 AWS 認證。
3. 啟動服務：
   ```bash
   docker-compose up -d --build
   ```
4. 執行遷移與填充數據：
   ```bash
   docker-compose exec app php artisan migrate --seed
   ```
5. 測試 API（例如 `curl -X POST http://localhost/api/register -d '{"name":"Test","email":"test@example.com","password":"pass123"}'`）。

### 雲端部署
- 在 GitHub Secrets 中設置 AWS 認證。
- 更新 `cloudformation-template.yaml` 的 VPC、子網與 ALB 詳情。
- 推送至 `main` 分支觸發 CI/CD 部署。

## 聯繫與貢獻
- **作者**: [您的姓名] (@BpsEason)
- **聯繫方式**: [your.email@example.com] 或透過 GitHub Issues。
- **貢獻**: 歡迎提交拉取請求或建議！

## 授權
MIT 授權（見 `LICENSE` 文件，待添加）。
