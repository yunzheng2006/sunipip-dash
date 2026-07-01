<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * 客户自助面板 - 认证控制器
 *
 * 全部使用 Sanctum token ability = ['customer']，与管理后台 ['admin'] token 隔离。
 */
class AuthController extends Controller
{
    /**
     * POST /customer/auth/register
     * 开放自助注册（支持手机验证码 + 邀请码 + 企业信息）
     */
    public function register(Request $request): JsonResponse
    {
        // Check if registration is enabled
        if (!\App\Models\SystemConfig::get('registration.enabled', true)) {
            return $this->error('注册已关闭', 403);
        }

        $data = $request->validate([
            'phone' => 'required|string|max:30',
            'sms_code' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:100|confirmed',
            'customer_name' => 'required|string|max:100',
            'company_name' => 'nullable|string|max:200',
            'is_company' => 'nullable|boolean',
            'business_license' => 'nullable|string|max:100',
            'invite_code' => 'nullable|string|max:20',
            'ref_code' => 'nullable|string|max:20',
        ], [
            'phone.required' => '请填写手机号',
            'sms_code.required' => '请输入验证码',
            'password.confirmed' => '两次密码输入不一致',
        ]);

        // 手机号即账号 — 检查是否已注册
        $phone = $data['phone'];
        if (Customer::where('username', $phone)->orWhere('phone', $phone)->exists()) {
            return $this->error('该手机号已注册，请直接登录', 422);
        }

        // 释放软删除记录占用的唯一索引（重命名而非硬删，避免外键约束冲突）
        Customer::onlyTrashed()
            ->where(fn ($q) => $q->where('username', $phone)->orWhere('phone', $phone))
            ->each(function ($c) {
                $c->update([
                    'username' => $c->username . '_del_' . $c->id,
                    'phone' => null,
                    'email' => null,
                ]);
            });

        $customerName = trim($data['customer_name']);
        Customer::onlyTrashed()->where('customer_name', $customerName)
            ->each(fn ($c) => $c->update(['customer_name' => $c->customer_name . '_del_' . $c->id]));
        if (Customer::where('customer_name', $customerName)->exists()) {
            return $this->error('客户名称「' . $customerName . '」已存在，请换一个', 422);
        }
        $data['customer_name'] = $customerName;

        // 验证短信验证码（注册必须验证）
        $sms = app(\App\Services\SmsService::class);
        if (!$sms->verifyCode($phone, $data['sms_code'], 'register')) {
            return $this->error('验证码错误或已过期', 422);
        }

        // Resolve invite code -> sales_person
        $salesPerson = null;
        $invitedBy = null;
        if (!empty($data['invite_code'])) {
            $inviter = \App\Models\User::where('invite_code', $data['invite_code'])->first();
            if (!$inviter) {
                return $this->error('邀请码无效', 422);
            }
            $salesPerson = $inviter->name;
            $invitedBy = $inviter->id;
        }

        // Resolve customer referral code
        $referredByCustomer = null;
        if (!empty($data['ref_code'])) {
            $referrer = Customer::where('referral_code', $data['ref_code'])->where('status', 1)->first();
            if ($referrer) {
                $referredByCustomer = $referrer->id;
                if (!$invitedBy) {
                    if ($referrer->invited_by) {
                        $invitedBy = $referrer->invited_by;
                        $salesPerson = $referrer->sales_person ?: \App\Models\User::find($referrer->invited_by)?->name;
                    } elseif ($referrer->sales_person) {
                        $salesPerson = $referrer->sales_person;
                        $invitedBy = \App\Models\User::where('name', $referrer->sales_person)->value('id');
                    }
                }
            }
        }

        $customer = new Customer([
            'username' => $phone,
            'password' => $data['password'],
            'customer_name' => $data['customer_name'],
            'phone' => $phone,
            'company_name' => $data['company_name'] ?? null,
            'is_company' => $data['is_company'] ?? false,
            'business_license' => $data['business_license'] ?? null,
            'sales_person' => $salesPerson,
            'invited_by' => $invitedBy,
            'invite_code_used' => $data['invite_code'] ?? null,
            'referred_by_customer' => $referredByCustomer,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);
        $customer->balance = \App\Models\SystemConfig::get('registration.default_balance', 0);
        $customer->status = 1;

        // 通过邀请链接注册的客户自动开通中转权限（后台可控开关）
        if (($invitedBy || $referredByCustomer) && \App\Models\SystemConfig::get('registration.invite_auto_forward', true)) {
            $customer->forward_certified = true;
            $customer->forward_certified_at = now();
        }

        $customer->save();

        $token = $customer->createToken('customer-portal', ['customer'], now()->addDays(7))->plainTextToken;

        return $this->success([
            'token' => $token,
            'customer' => $this->presentCustomer($customer),
        ], '注册成功，欢迎使用 SuniPIP');
    }

    /**
     * POST /customer/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // 登录限流：同一 IP + username 每分钟最多 5 次
        $key = 'customer-login:' . $request->ip() . ':' . $request->username;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'username' => "登录尝试过于频繁，请 {$seconds} 秒后再试",
            ]);
        }

        // 支持手机号或用户名登录
        $login = $request->username;
        $customer = Customer::where('username', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$customer || !Hash::check($request->password, $customer->password)) {
            RateLimiter::hit($key, 60);
            return $this->error('手机号或密码错误', 401);
        }

        if ((int) $customer->status !== 1) {
            return $this->error('账号已被禁用，请联系管理员', 403);
        }

        RateLimiter::clear($key);

        $customer->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $customer->createToken('customer-portal', ['customer'], now()->addDays(7))->plainTextToken;

        return $this->success([
            'token' => $token,
            'customer' => $this->presentCustomer($customer),
        ], '登录成功');
    }


    /**
     * POST /customer/auth/login-sms
     * 验证码登录（免密码）
     */
    public function loginBySms(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:30',
            'sms_code' => 'required|string|size:6',
        ]);

        $phone = $request->phone;

        // 验证短信验证码
        $sms = app(\App\Services\SmsService::class);
        if (!$sms->verifyCode($phone, $request->sms_code, 'login')) {
            return $this->error('验证码错误或已过期', 422);
        }

        $customer = Customer::where('username', $phone)
            ->orWhere('phone', $phone)
            ->first();

        if (!$customer) {
            return $this->error('该手机号未注册', 404);
        }

        if ((int) $customer->status !== 1) {
            return $this->error('账号已被禁用，请联系管理员', 403);
        }

        $customer->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $customer->createToken('customer-portal', ['customer'], now()->addDays(7))->plainTextToken;

        return $this->success([
            'token' => $token,
            'customer' => $this->presentCustomer($customer),
        ], '登录成功');
    }

    /**
     * GET /customer/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success($this->presentCustomer($request->user()));
    }

    /**
     * POST /customer/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, '已退出登录');
    }

    /**
     * PUT /customer/auth/password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $customer = $request->user();

        $key = 'customer-change-pwd:' . $customer->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return $this->error("操作过于频繁，请 {$seconds} 秒后再试", 429);
        }

        if (!Hash::check($request->old_password, $customer->password)) {
            RateLimiter::hit($key, 300);
            return $this->error('原密码错误', 422);
        }

        RateLimiter::clear($key);

        $customer->update(['password' => $request->new_password]); // hashed by cast

        // 可选：撤销其他 token，强制其他设备重新登录
        $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->id)->delete();

        return $this->success(null, '密码修改成功');
    }

    /**
     * 统一返回给前端的 customer 结构（不含敏感字段）
     */
    private function presentCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'username' => $customer->username,
            'customer_name' => $customer->customer_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company_name' => $customer->company_name,
            'balance' => (float) $customer->balance,
            'commission_balance' => (float) $customer->commission_balance,
            'status' => $customer->status,
            'auto_renew_default' => (bool) $customer->auto_renew_default,
            'sms_expiry_notify' => (bool) $customer->sms_expiry_notify,
            'forward_certified' => (bool) $customer->forward_certified,
            'referral_rate' => (float) \App\Models\SystemConfig::get('referral.rate', 5),
            'sales_person' => $customer->sales_person,
            'created_at' => $customer->created_at,
        ];
    }
}
