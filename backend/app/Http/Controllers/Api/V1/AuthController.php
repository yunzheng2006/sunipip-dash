<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $key = 'admin-login:' . $request->ip() . ':' . $request->username;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return $this->error("登录尝试过于频繁，请 {$seconds} 秒后再试", 429);
        }

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60);
            return $this->error('用户名或密码错误', 401);
        }

        RateLimiter::clear($key);

        if ($user->status !== 1) {
            return $this->error('账户已被禁用', 403);
        }

        // token ability 'admin' 用于区分管理后台 token 和 customer 门户 token
        $token = $user->createToken('auth-token', ['admin'])->plainTextToken;

        // 自动生成邀请码（如果还没有）
        if (!$user->invite_code) {
            $user->invite_code = strtoupper(Str::random(6));
            $user->save();
        }

        return $this->success([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'invite_code' => $user->invite_code,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ], '登录成功');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // 自动生成邀请码（如果还没有）
        if (!$user->invite_code) {
            $user->invite_code = strtoupper(Str::random(6));
            $user->save();
        }

        $maxDiscount = null;
        foreach ($user->roles as $role) {
            $settings = is_string($role->settings) ? json_decode($role->settings, true) : ($role->settings ?? []);
            $roleMax = $settings['max_discount_percent'] ?? null;
            if ($roleMax !== null) {
                $maxDiscount = $maxDiscount === null ? $roleMax : min($maxDiscount, $roleMax);
            }
        }

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'invite_code' => $user->invite_code,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'max_discount_percent' => $maxDiscount,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, '已退出登录');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:6|max:100',
        ]);

        $user = $request->user();

        if (!Hash::check($request->old_password, $user->password)) {
            return $this->error('原密码错误', 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        // 撤销其他 token，强制其他设备重新登录
        try {
            $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();
        } catch (\Throwable) {
            // ignore
        }

        return $this->success(null, '密码修改成功');
    }
}
