<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    /**
     * 輔助方法：統一成功回應格式
     * @param string $message
     * @param array $data
     * @param int $status
     * @return \Illuminate\Http\JsonResponse
     */
    protected function successResponse($message, $data = [], $status = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $status);
    }

    /**
     * 輔助方法：統一錯誤回應格式
     * @param string $message
     * @param int $status
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse($message, $status = 400)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message
        ], $status);
    }

    /**
     * 註冊新用戶
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // 直接為新註冊用戶生成 token
        $token = JWTAuth::fromUser($user);

        return $this->successResponse('註冊成功', ['user' => $user, 'token' => $token], 201);
    }

    /**
     * 用戶登入並返回 JWT 令牌
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return $this->errorResponse('無效的憑證', 401);
            }
        } catch (JWTException $e) {
            return $this->errorResponse('無法創建令牌', 500);
        }

        return $this->successResponse('登入成功', ['token' => $token, 'user' => Auth::user()], 200);
    }

    /**
     * 登出用戶（使 JWT 令牌失效）
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->successResponse('成功登出');
        } catch (JWTException $e) {
            return $this->errorResponse('登出失敗', 500);
        }
    }

    /**
     * 獲取認證用戶資訊
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return $this->successResponse('用戶資訊獲取成功', ['user' => Auth::user()]);
    }

    /**
     * 刷新 JWT 令牌
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        try {
            $token = JWTAuth::refresh();
            return $this->successResponse('令牌刷新成功', ['token' => $token]);
        } catch (JWTException $e) {
            return $this->errorResponse('令牌刷新失敗', 500);
        }
    }
}
