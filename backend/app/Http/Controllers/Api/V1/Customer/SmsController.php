<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function sendCode(Request $request, SmsService $sms): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:30|regex:/^1[3-9]\d{9}$/',
            'type' => 'nullable|string|in:register,login,reset',
            'captcha_answer' => 'required|integer', // Simple math captcha answer
            'captcha_expected' => 'required|integer',
        ]);

        // Verify math captcha (simple anti-bot: a + b = ?)
        if ((int) $data['captcha_answer'] !== (int) $data['captcha_expected']) {
            return $this->error('验证码计算错误', 422);
        }

        $result = $sms->sendCode($data['phone'], $data['type'] ?? 'register', $request->ip());
        return $result['ok']
            ? $this->success(null, $result['message'])
            : $this->error($result['message'], 422);
    }

    /**
     * GET /customer/sms/captcha
     * Generate a simple math captcha for anti-bot
     */
    public function captcha(): JsonResponse
    {
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        return $this->success([
            'question' => "{$a} + {$b} = ?",
            'a' => $a,
            'b' => $b,
            'expected' => $a + $b,
        ]);
    }
}
