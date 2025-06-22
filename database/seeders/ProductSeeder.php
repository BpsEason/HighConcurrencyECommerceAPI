<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;
use Illuminate\Support\Facades\Redis; // 引入 Redis

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 清空 Redis 中可能的舊庫存數據
        Redis::del('product:stock:1');
        Redis::del('product:stock:2');
        Redis::del('product:stock:locked:1');
        Redis::del('product:stock:locked:2');

        Product::create([
            'name' => 'Sample Product A',
            'description' => 'High-quality sample product for testing.',
            'price' => 19.99,
            'stock' => 1000, // 初始庫存
        ]);

        Product::create([
            'name' => 'Sample Product B',
            'description' => 'Another high-quality product.',
            'price' => 29.99,
            'stock' => 500, // 初始庫存
        ]);

        // 注意：這裡不主動將庫存同步到 Redis，因為 OrderController 會在第一次訪問時載入。
        // 但為了 Locust 測試的順利進行，可以預先設定。
        Redis::set('product:stock:1', 1000);
        Redis::set('product:stock:2', 500);
    }
}
