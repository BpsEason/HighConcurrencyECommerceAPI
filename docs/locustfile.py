from locust import HttpUser, task, between
import random

class ECommerceUser(HttpUser):
    wait_time = between(1, 2.5)
    host = "http://localhost:80" # 預設主機，運行 Locust 時可透過 --host 參數覆蓋 (改為 80 端口，對應 Nginx)

    # JWT 令牌和用戶 ID，會在登入後設定
    auth_token = None
    user_id = None
    email_counter = 0

    def on_start(self):
        """ 在每個 Locust 使用者啟動時執行一次 (或重試登入時) """
        self.register_and_login()
        
        # 初始化 Redis 庫存，如果還沒有（僅用於本地測試，確保 Locust 可以啟動庫存）
        # 實際生產環境中，Redis 庫存會由後端服務或排程任務管理
        # 由於 Locust 只能發送 HTTP 請求，這裡無法直接操作 Redis。
        # 假設產品 ID 1 和 2 總是有庫存的，或者在測試前已透過 Seeder 或其他方式設定好 Redis 庫存。
        pass

    def register_and_login(self):
        """ 執行用戶註冊並登入以獲取 JWT 令牌 """
        self.email_counter += 1
        email = f"testuser_{random.randint(10000, 99999)}@example.com"
        password = "password"

        # 嘗試註冊
        register_data = {
            "name": f"Test User {self.environment.runner.user_count}_{self.email_counter}",
            "email": email,
            "password": password,
            "password_confirmation": password
        }
        with self.client.post("/api/register", json=register_data, catch_response=True) as response:
            if response.status_code == 201:
                response.success("User registered")
                self.log_in(email, password) # 直接登入
            elif response.status_code == 422 and "The email has already been taken" in response.text:
                response.success("User already registered (expected)")
                self.log_in(email, password) # 如果註冊失敗，嘗試直接登入 (可能用戶已存在)
            else:
                response.failure(f"User registration failed: {response.text}")
                # 不再嘗試登入，直接讓這個用戶失敗

    def log_in(self, email, password):
        login_data = {
            "email": email,
            "password": password
        }
        with self.client.post("/api/login", json=login_data, catch_response=True) as response:
            if response.status_code == 200:
                self.auth_token = response.json()["access_token"]
                # self.user_id = response.json()["user"]["id"] if "user" in response.json() else None
                response.success("User logged in")
            else:
                self.auth_token = None
                response.failure(f"User login failed: {response.text}")

    @task(3) # 訂單提交任務的執行權重較高
    def place_order(self):
        if not self.auth_token:
            self.register_and_login() # Try to re-authenticate
            if not self.auth_token:
                self.environment.runner.quit() # 如果還沒有 token，退出此用戶
                return

        headers = {"Authorization": f"Bearer {self.auth_token}", "Content-Type": "application/json"}
        # 假設有兩個商品 ID 可供測試，實際應從資料庫中獲取
        product_id = random.choice([1, 2])
        quantity = random.randint(1, 5) # 隨機購買 1 到 5 個

        order_data = {
            "product_id": product_id,
            "quantity": quantity
        }

        with self.client.post("/api/orders", json=order_data, headers=headers, catch_response=True) as response:
            if response.status_code == 202: # 訂單已提交，正在處理中
                response.success(f"Order placed: Product {product_id}, Qty {quantity}")
            elif response.status_code == 400 and "庫存不足" in response.text:
                response.success(f"Order failed (stock insufficient, expected): Product {product_id}, Qty {quantity}")
            else:
                response.failure(f"Order failed with status {response.status_code}: {response.text}")

    @task(1)
    def get_user_info(self):
        if not self.auth_token:
            self.register_and_login() # Try to re-authenticate
            if not self.auth_token:
                self.environment.runner.quit()
                return

        headers = {"Authorization": f"Bearer {self.auth_token}"}
        # 使用 POST /api/me as per routes/api.php
        with self.client.post("/api/me", headers=headers, catch_response=True) as response:
            if response.status_code == 200:
                response.success("Fetched user info")
            else:
                response.failure(f"Failed to fetch user info: {response.text}")

