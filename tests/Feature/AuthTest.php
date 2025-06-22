<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * 測試用戶註冊功能。
     *
     * @return void
     */
    public function testUserCanRegister()
    {
        $userData = [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/register', $userData);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'message',
                     'user' => ['id', 'name', 'email'],
                     'access_token',
                     'token_type',
                     'expires_in'
                 ])
                 ->assertJson([
                     'message' => '註冊成功'
                 ]);

        $this->assertDatabaseHas('users', ['email' => $userData['email']]);
    }

    /**
     * 測試用戶登入功能。
     *
     * @return void
     */
    public function testUserCanLogin()
    {
        $password = 'password123';
        $user = User::factory()->create([
            'password' => bcrypt($password),
        ]);

        $loginData = [
            'email' => $user->email,
            'password' => $password,
        ];

        $response = $this->postJson('/api/login', $loginData);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'message',
                     'access_token',
                     'token_type',
                     'expires_in'
                 ])
                 ->assertJson([
                     'message' => '登入成功'
                 ]);
    }

    /**
     * 測試使用無效憑證登入。
     *
     * @return void
     */
    public function testUserCannotLoginWithInvalidCredentials()
    {
        $user = User::factory()->create([
            'password' => bcrypt('correctpassword'),
        ]);

        $loginData = [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ];

        $response = $this->postJson('/api/login', $loginData);

        $response->assertStatus(401)
                 ->assertJson([
                     'message' => '電子郵件或密碼不正確'
                 ]);
    }

    /**
     * 測試認證用戶可以登出。
     *
     * @return void
     */
    public function testAuthenticatedUserCanLogout()
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $response = $this->postJson('/api/logout', [], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => '登出成功'
                 ]);
    }

    /**
     * 測試獲取認證用戶資訊。
     *
     * @return void
     */
    public function testAuthenticatedUserCanAccessMeEndpoint()
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $response = $this->postJson('/api/me', [], [ // Changed to POST as per api.php
            'Authorization' => 'Bearer ' . $token
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'id' => $user->id,
                     'name' => $user->name,
                     'email' => $user->email,
                 ]);
    }

    /**
     * 測試未認證用戶無法訪問受保護路由。
     *
     * @return void
     */
    public function testUnauthenticatedUserCannotAccessProtectedRoutes()
    {
        $response = $this->postJson('/api/me'); // Try to access protected route without token

        $response->assertStatus(401); // Should return unauthorized status
    }
}
