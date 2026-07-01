<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\SmsProvider;
use App\Models\SystemConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send verification code
     */
    public function sendCode(string $phone, string $type = 'register', ?string $ip = null): array
    {
        // Rate limiting: max 10 per day per phone
        $recentCount = SmsLog::where('phone', $phone)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($recentCount >= 10) {
            return ['ok' => false, 'message' => '今日发送次数已达上限'];
        }

        $lastSent = SmsLog::where('phone', $phone)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
        if ($lastSent) {
            return ['ok' => false, 'message' => '请60秒后再试'];
        }

        // 单号码每小时上限 5 条（与阿里云流控对齐，避免浪费 API 调用）
        $hourCount = SmsLog::where('phone', $phone)
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($hourCount >= 5) {
            return ['ok' => false, 'message' => '该号码发送过于频繁，请1小时后再试'];
        }

        // IP rate limit: max 10 per hour per IP
        if ($ip) {
            $ipCount = SmsLog::where('ip', $ip)->where('created_at', '>=', now()->subHour())->count();
            if ($ipCount >= 10) {
                return ['ok' => false, 'message' => '操作过于频繁'];
            }
        }

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $provider = SmsProvider::where('is_active', 1)->orderBy('sort')->first();

        if (!$provider) {
            return ['ok' => false, 'message' => '短信服务未配置'];
        }

        $log = SmsLog::create([
            'phone' => $phone,
            'code' => $code,
            'type' => $type,
            'provider' => $provider->type,
            'status' => 'pending',
            'ip' => $ip,
            'expires_at' => now()->addMinutes(5),
        ]);

        $maxRetries = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $result = $this->sendViaProvider($provider, $phone, $code);
                $log->update([
                    'status' => 'sent',
                    'biz_id' => $result['biz_id'] ?? null,
                    'error' => $attempt > 1 ? "第{$attempt}次重试成功" : null,
                ]);
                return ['ok' => true, 'message' => '验证码已发送'];
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning("SMS send attempt {$attempt}/{$maxRetries} failed", [
                    'phone' => $phone, 'error' => $e->getMessage(),
                ]);
                if ($attempt < $maxRetries) {
                    usleep(500_000 * $attempt); // 0.5s, 1s 递增间隔
                }
            }
        }

        $log->update(['status' => 'failed', 'error' => "重试{$maxRetries}次均失败: " . $lastError->getMessage()]);
        Log::error('SMS send failed after retries', ['phone' => $phone, 'error' => $lastError->getMessage()]);
        return ['ok' => false, 'message' => '发送失败，请稍后再试'];
    }

    /**
     * Verify code
     */
    public function verifyCode(string $phone, string $code, string $type = 'register'): bool
    {
        $log = SmsLog::where('phone', $phone)
            ->where('code', $code)
            ->where('type', $type)
            ->where('status', 'sent')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$log) return false;

        $log->update(['status' => 'verified', 'verified_at' => now()]);
        return true;
    }

    /**
     * 发送到期提醒短信
     */
    public function sendExpirySms(string $phone, int $count): array
    {
        $provider = SmsProvider::where('is_active', 1)->whereNotNull('expiry_template_code')->orderBy('sort')->first();

        if (!$provider) {
            return ['ok' => false, 'message' => '到期提醒短信模板未配置'];
        }

        $log = SmsLog::create([
            'phone' => $phone,
            'code' => (string) $count,
            'type' => 'expiry_notify',
            'provider' => $provider->type,
            'status' => 'pending',
        ]);

        try {
            $result = $this->sendTemplateViaProvider($provider, $phone, $provider->expiry_template_code, ['X' => (string) $count]);
            $log->update(['status' => 'sent', 'biz_id' => $result['biz_id'] ?? null]);
            return ['ok' => true, 'message' => '到期提醒已发送'];
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Log::error('Expiry SMS send failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => '发送失败: ' . $e->getMessage()];
        }
    }

    private function sendTemplateViaProvider(SmsProvider $provider, string $phone, string $templateCode, array $params): array
    {
        return match ($provider->type) {
            'aliyun' => $this->sendAliyunTemplate($provider, $phone, $templateCode, $params),
            default => throw new \RuntimeException("不支持的短信服务: {$provider->type}"),
        };
    }

    private function sendAliyunTemplate(SmsProvider $provider, string $phone, string $templateCode, array $templateParam): array
    {
        $provider->makeVisible('config');
        $config = $provider->config;
        $accessKeyId = $config['access_key_id'] ?? '';
        $accessKeySecret = $config['access_key_secret'] ?? '';
        $signName = $config['sign_name'] ?? '';

        if (!$accessKeyId || !$accessKeySecret || !$signName || !$templateCode) {
            throw new \RuntimeException('阿里云短信配置不完整');
        }

        $params = [
            'AccessKeyId' => $accessKeyId,
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'PhoneNumbers' => $phone,
            'RegionId' => 'cn-hangzhou',
            'SignName' => $signName,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'TemplateCode' => $templateCode,
            'TemplateParam' => json_encode($templateParam),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
        ];

        ksort($params);
        $queryString = '';
        foreach ($params as $k => $v) {
            $queryString .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
        }
        $queryString = substr($queryString, 1);

        $stringToSign = 'GET&' . rawurlencode('/') . '&' . rawurlencode($queryString);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $params['Signature'] = $signature;

        $response = Http::timeout(15)->get('https://dysmsapi.aliyuncs.com', $params);
        $data = $response->json();

        if (($data['Code'] ?? '') !== 'OK') {
            throw new \RuntimeException('阿里云短信错误: ' . ($data['Message'] ?? json_encode($data)));
        }

        return ['biz_id' => $data['BizId'] ?? null];
    }

    private function sendViaProvider(SmsProvider $provider, string $phone, string $code): array
    {
        return match ($provider->type) {
            'aliyun' => $this->sendAliyun($provider, $phone, $code),
            default => throw new \RuntimeException("不支持的短信服务: {$provider->type}"),
        };
    }

    private function sendAliyun(SmsProvider $provider, string $phone, string $code): array
    {
        $config = $provider->config;
        $accessKeyId = $config['access_key_id'] ?? '';
        $accessKeySecret = $config['access_key_secret'] ?? '';
        $signName = $config['sign_name'] ?? '';
        $templateCode = $config['template_code'] ?? '';

        if (!$accessKeyId || !$accessKeySecret || !$signName || !$templateCode) {
            throw new \RuntimeException('阿里云短信配置不完整');
        }

        // Build Alibaba Cloud API v1 signature (RPC style)
        $params = [
            'AccessKeyId' => $accessKeyId,
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'PhoneNumbers' => $phone,
            'RegionId' => 'cn-hangzhou',
            'SignName' => $signName,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'TemplateCode' => $templateCode,
            'TemplateParam' => json_encode(['code' => $code]),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
        ];

        ksort($params);
        $queryString = '';
        foreach ($params as $k => $v) {
            $queryString .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
        }
        $queryString = substr($queryString, 1); // remove leading &

        $stringToSign = 'GET&' . rawurlencode('/') . '&' . rawurlencode($queryString);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $params['Signature'] = $signature;

        $response = Http::timeout(15)->get('https://dysmsapi.aliyuncs.com', $params);
        $data = $response->json();

        if (($data['Code'] ?? '') !== 'OK') {
            throw new \RuntimeException('阿里云短信错误: ' . ($data['Message'] ?? json_encode($data)));
        }

        return ['biz_id' => $data['BizId'] ?? null];
    }
}
