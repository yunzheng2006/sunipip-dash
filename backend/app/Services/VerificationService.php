<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SystemConfig;
use App\Models\VerificationProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VerificationService
{
    private const ALIYUN_ENDPOINT = 'https://cloudauth.aliyuncs.com';
    private const ALIYUN_API_VERSION = '2019-03-07';

    public function isRequired(): bool
    {
        return (bool) SystemConfig::get('verification.required', false);
    }

    public function isVerified(Customer $customer): bool
    {
        return in_array($customer->verified_type, ['personal', 'enterprise']);
    }

    // ========== 新版人脸核身流程 ==========

    /**
     * 发起人脸核身（获取验证URL）
     */
    public function initFaceVerification(Customer $customer, string $name, string $idCard): array
    {
        $provider = $this->getActiveProvider('tencent_face');
        if (!$provider) {
            throw new \RuntimeException('腾讯云人脸核身服务未配置，请联系管理员');
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $redirectUrl = 'https://user.sunipip.com/verification/complete';

        $params = [
            'RuleId' => $creds['rule_id'] ?? '',
            'RedirectUrl' => $redirectUrl,
            'IdCard' => $idCard,
            'Name' => $name,
        ];

        $result = $this->callTencentApi($creds, 'DetectAuth', $params, 'faceid');

        return [
            'url' => $result['Url'] ?? '',
            'biz_token' => $result['BizToken'] ?? '',
        ];
    }

    /**
     * 确认人脸核身结果
     */
    public function confirmFaceVerification(Customer $customer, string $bizToken): array
    {
        $provider = $this->getActiveProvider('tencent_face');
        if (!$provider) {
            return ['success' => false, 'message' => '腾讯云人脸核身服务未配置，请联系管理员'];
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $params = [
            'BizToken' => $bizToken,
            'RuleId' => $creds['rule_id'] ?? '',
        ];

        try {
            $result = $this->callTencentApi($creds, 'GetDetectInfoEnhanced', $params, 'faceid');

            $text = $result['Text'] ?? [];
            $errCode = $text['ErrCode'] ?? null;
            $name = $text['Name'] ?? '';
            $idCard = $text['IdCard'] ?? '';

            if ($errCode === null) {
                return ['success' => false, 'message' => '人脸验证尚未完成，请先在微信中完成验证'];
            }

            $isSuccess = $errCode === 0 || $errCode === '0';

            if ($isSuccess) {
                $maskedId = $idCard ? (substr($idCard, 0, 4) . '****' . substr($idCard, -4)) : '';

                $customer->update([
                    'verified_type' => 'personal',
                    'verified_at' => now(),
                    'verified_name' => $name,
                    'verified_id_number' => $maskedId,
                ]);

                return ['success' => true, 'message' => '人脸核身认证成功'];
            }

            $errMsg = $text['ErrMsg'] ?? '人脸验证未通过';
            return ['success' => false, 'message' => $errMsg];
        } catch (\Throwable $e) {
            Log::error('Face verification confirm failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '验证结果查询失败: ' . $e->getMessage()];
        }
    }

    // ========== 新版企业认证流程 ==========

    /**
     * 营业执照OCR识别
     */
    public function ocrBusinessLicense(string $imageBase64): array
    {
        $provider = $this->getActiveProvider('tencent_ocr');
        if (!$provider) {
            throw new \RuntimeException('腾讯云OCR服务未配置，请联系管理员');
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $params = [
            'ImageBase64' => $imageBase64,
        ];

        $result = $this->callTencentApi($creds, 'BizLicenseOCR', $params, 'ocr');

        return [
            'name' => $result['Name'] ?? '',
            'credit_code' => $result['RegNum'] ?? '',
            'legal_person' => $result['Person'] ?? '',
            'address' => $result['Address'] ?? '',
            'capital' => $result['Capital'] ?? '',
            'type' => $result['Type'] ?? '',
            'business_scope' => $result['Business'] ?? '',
            'period' => $result['Period'] ?? '',
        ];
    }

    /**
     * 营业执照权威核验（企业四要素：企业名+信用代码+法人+证件号）
     * API: VerifyBizLicenseEnterprise4 (ocr.tencentcloudapi.com, 2018-11-19)
     * 仅验证企业信息，不最终写入认证状态（需法人扫脸后才完成）
     */
    public function verifyBusinessLicense(string $name, string $creditCode, string $legalPerson, string $idNum): array
    {
        $provider = $this->getActiveProvider('tencent_ocr');
        if (!$provider) {
            return ['success' => false, 'message' => '腾讯云OCR服务未配置，请联系管理员'];
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $params = [
            'CreditCode' => $creditCode,
            'EntName' => $name,
            'LrName' => $legalPerson,
            'IdNum' => $idNum,
        ];

        try {
            $result = $this->callTencentApi($creds, 'VerifyBizLicenseEnterprise4', $params, 'ocr');

            $statusCode = $result['StatusCode'] ?? 1;
            $verifyResult = $result['VerifyResult'] ?? 0;

            if ($statusCode == 1) {
                return ['success' => false, 'message' => '核验服务异常，请稍后重试'];
            }

            if ($verifyResult == 1) {
                return ['success' => true, 'message' => '企业信息核验通过，请继续完成法人人脸验证'];
            }

            $details = [];
            if (!($result['IsEntNameConsistent'] ?? true)) $details[] = '企业名称不一致';
            if (!($result['IsCreditCodeConsistent'] ?? true)) $details[] = '信用代码不一致';
            if (!($result['IsLrNameConsistent'] ?? true)) $details[] = '法人姓名不一致';
            if (!($result['IsIdNumConsistent'] ?? true)) $details[] = '法人身份证号不一致';
            $msg = $details ? implode('; ', $details) : '企业信息核验不通过';

            return ['success' => false, 'message' => $msg];
        } catch (\Throwable $e) {
            Log::error('Business license verification failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '企业核验失败: ' . $e->getMessage()];
        }
    }

    /**
     * 企业认证：发起法人人脸核身
     */
    public function initEnterpriseFaceVerification(string $legalPersonName, string $idNum): array
    {
        $provider = $this->getActiveProvider('tencent_face');
        if (!$provider) {
            throw new \RuntimeException('腾讯云人脸核身服务未配置，请联系管理员');
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $redirectUrl = 'https://user.sunipip.com/verification/complete';

        $params = [
            'RuleId' => $creds['rule_id'] ?? '',
            'RedirectUrl' => $redirectUrl,
            'IdCard' => $idNum,
            'Name' => $legalPersonName,
        ];

        $result = $this->callTencentApi($creds, 'DetectAuth', $params, 'faceid');

        return [
            'url' => $result['Url'] ?? '',
            'biz_token' => $result['BizToken'] ?? '',
        ];
    }

    /**
     * 企业认证：确认法人人脸核身结果，通过后写入企业认证
     */
    public function confirmEnterpriseFaceVerification(
        Customer $customer,
        string $bizToken,
        string $enterpriseName,
        string $creditCode,
        string $legalPerson
    ): array {
        $provider = $this->getActiveProvider('tencent_face');
        if (!$provider) {
            return ['success' => false, 'message' => '腾讯云人脸核身服务未配置'];
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $params = [
            'BizToken' => $bizToken,
            'RuleId' => $creds['rule_id'] ?? '',
        ];

        try {
            $result = $this->callTencentApi($creds, 'GetDetectInfoEnhanced', $params, 'faceid');

            $text = $result['Text'] ?? [];
            $errCode = $text['ErrCode'] ?? null;
            $name = $text['Name'] ?? '';
            $idCard = $text['IdCard'] ?? '';

            if ($errCode === null) {
                return ['success' => false, 'message' => '法人人脸验证尚未完成，请先在微信中完成验证'];
            }

            $isSuccess = $errCode === 0 || $errCode === '0';

            if ($isSuccess) {
                $maskedId = $idCard ? (substr($idCard, 0, 4) . '****' . substr($idCard, -4)) : '';

                $customer->update([
                    'verified_type' => 'enterprise',
                    'verified_at' => now(),
                    'verified_enterprise_name' => $enterpriseName,
                    'verified_credit_code' => strtoupper($creditCode),
                    'company_name' => $enterpriseName,
                    'verified_name' => $legalPerson,
                    'verified_id_number' => $maskedId,
                ]);

                return ['success' => true, 'message' => '企业认证成功'];
            }

            $errMsg = $text['ErrMsg'] ?? '法人人脸验证未通过';
            return ['success' => false, 'message' => $errMsg];
        } catch (\Throwable $e) {
            Log::error('Enterprise face verification confirm failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '法人人脸验证结果查询失败: ' . $e->getMessage()];
        }
    }

    /**
     * 轮询人脸核身结果（不抛异常，返回 pending/success/failed）
     */
    public function pollFaceResult(string $bizToken): array
    {
        $provider = $this->getActiveProvider('tencent_face');
        if (!$provider) {
            return ['status' => 'failed', 'message' => '服务未配置'];
        }
        $provider->makeVisible('credentials');
        $creds = $provider->credentials;

        $params = [
            'BizToken' => $bizToken,
            'RuleId' => $creds['rule_id'] ?? '',
        ];

        try {
            $result = $this->callTencentApi($creds, 'GetDetectInfoEnhanced', $params, 'faceid');
            $text = $result['Text'] ?? [];
            $errCode = $text['ErrCode'] ?? null;
            $name = $text['Name'] ?? '';
            $idCard = $text['IdCard'] ?? '';

            if ($errCode === null) {
                return ['status' => 'pending'];
            }

            $isSuccess = $errCode === 0 || $errCode === '0';

            if ($isSuccess) {
                return [
                    'status' => 'success',
                    'name' => $name,
                    'id_card' => $idCard,
                ];
            }

            $errMsg = $text['ErrMsg'] ?? '';
            if (str_contains($errMsg, '未完成') || str_contains($errMsg, '进行中')) {
                return ['status' => 'pending'];
            }

            return ['status' => 'failed', 'message' => $errMsg ?: '人脸验证未通过'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'VerificationNotComplete') || str_contains($msg, 'NotReady') || str_contains($msg, '未完成')) {
                return ['status' => 'pending'];
            }
            return ['status' => 'pending'];
        }
    }

    /**
     * 检查客户是否有已完成但未确认的 pending token，如有则自动完成认证
     * 返回 true 表示已自动完成认证，调用方应直接返回成功
     */
    public function tryCompletePendingVerification(Customer $customer): bool
    {
        if ($this->isVerified($customer)) {
            return true;
        }

        $token = $customer->pending_biz_token;
        if (!$token || !$customer->pending_verify_at) {
            return false;
        }

        if ($customer->pending_verify_at->diffInMinutes(now()) > 30) {
            return false;
        }

        $result = $this->pollFaceResult($token);

        if ($result['status'] !== 'success') {
            return false;
        }

        $name = $result['name'] ?? $customer->pending_verify_name ?? '';
        $idCard = $result['id_card'] ?? '';
        $maskedId = $idCard ? (substr($idCard, 0, 4) . '****' . substr($idCard, -4)) : '';

        $customer->update([
            'verified_type' => 'personal',
            'verified_at' => now(),
            'verified_name' => $name,
            'verified_id_number' => $maskedId,
            'pending_biz_token' => null,
            'pending_verify_name' => null,
            'pending_verify_id' => null,
            'pending_verify_at' => null,
        ]);

        Log::info('Auto-completed pending verification', [
            'customer_id' => $customer->id,
            'biz_token' => $token,
        ]);

        return true;
    }

    // ========== 旧版兼容方法（保留） ==========

    public function verifyPersonal(Customer $customer, string $realName, string $idNumber): array
    {
        $provider = $this->getActiveProvider();
        if (!$provider) {
            return ['success' => false, 'message' => '实名认证服务未配置，请联系管理员'];
        }

        try {
            $result = $this->callProviderApi($provider, $realName, $idNumber);

            if ($result['match']) {
                $maskedId = substr($idNumber, 0, 4) . '****' . substr($idNumber, -4);
                $customer->update([
                    'verified_type' => 'personal',
                    'verified_at' => now(),
                    'verified_name' => $realName,
                    'verified_id_number' => $maskedId,
                ]);
                return ['success' => true, 'message' => '实名认证成功'];
            }

            return ['success' => false, 'message' => $result['message']];
        } catch (\Throwable $e) {
            Log::error('Verification failed', ['driver' => $provider->driver, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '认证服务异常: ' . $e->getMessage()];
        }
    }

    public function verifyEnterprise(
        Customer $customer,
        string $enterpriseName,
        string $creditCode,
        string $legalPersonName,
        string $legalPersonId,
    ): array {
        $provider = $this->getActiveProvider();
        if (!$provider) {
            return ['success' => false, 'message' => '实名认证服务未配置，请联系管理员'];
        }

        try {
            $result = $this->callProviderApi($provider, $legalPersonName, $legalPersonId);
            if (!$result['match']) {
                return ['success' => false, 'message' => '法人' . $result['message']];
            }
        } catch (\Throwable $e) {
            Log::error('Enterprise verification: ID check failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '法人身份核验失败: ' . $e->getMessage()];
        }

        if (!preg_match('/^[0-9A-Z]{18}$/', strtoupper($creditCode))) {
            return ['success' => false, 'message' => '统一社会信用代码格式不正确（应为18位）'];
        }

        $maskedId = substr($legalPersonId, 0, 4) . '****' . substr($legalPersonId, -4);
        $customer->update([
            'verified_type' => 'enterprise',
            'verified_at' => now(),
            'verified_name' => $legalPersonName,
            'verified_id_number' => $maskedId,
            'verified_enterprise_name' => $enterpriseName,
            'verified_credit_code' => strtoupper($creditCode),
            'company_name' => $enterpriseName,
            'business_license' => strtoupper($creditCode),
        ]);

        return ['success' => true, 'message' => '企业认证成功'];
    }

    // ========== 测试方法 ==========

    public function testProvider(VerificationProvider $provider): array
    {
        $provider->makeVisible('credentials');

        return match ($provider->driver) {
            'aliyun' => $this->callAliyunApi($provider->credentials, '张三', '110101199001011234'),
            'tencent_face' => $this->testTencentFaceApi($provider->credentials),
            'tencent_ocr' => $this->testTencentOcrApi($provider->credentials),
            // Legacy support
            'tencent' => $this->testTencentFaceApi($provider->credentials),
            default => throw new \RuntimeException("未知驱动: {$provider->driver}"),
        };
    }

    public function testConnection(): array
    {
        $provider = $this->getActiveProvider();
        if (!$provider) {
            throw new \RuntimeException('未配置任何认证服务接口');
        }
        return $this->testProvider($provider);
    }

    // ========== 内部方法 ==========

    public function getActiveProvider(string $driver = null): ?VerificationProvider
    {
        $query = VerificationProvider::where('is_active', true);
        if ($driver) {
            $query->where('driver', $driver);
        }
        $provider = $query->first();
        $provider?->makeVisible('credentials');
        return $provider;
    }

    private function callProviderApi(VerificationProvider $provider, string $name, string $idNumber): array
    {
        return match ($provider->driver) {
            'aliyun' => $this->callAliyunApi($provider->credentials, $name, $idNumber),
            'tencent', 'tencent_face' => $this->callTencentIdVerify($provider->credentials, $name, $idNumber),
            default => throw new \RuntimeException("未知驱动: {$provider->driver}"),
        };
    }

    // ========== 阿里云 ==========

    private function callAliyunApi(array $credentials, string $name, string $idNumber): array
    {
        $accessKeyId = $credentials['access_key_id'] ?? '';
        $accessKeySecret = $credentials['access_key_secret'] ?? '';

        if (!$accessKeyId || !$accessKeySecret) {
            throw new \RuntimeException('阿里云 AccessKey 未配置');
        }

        $params = [
            'AccessKeyId' => $accessKeyId,
            'Action' => 'Id2MetaVerify',
            'Format' => 'JSON',
            'RegionId' => 'cn-shanghai',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => self::ALIYUN_API_VERSION,
            'ParamType' => 'normal',
            'IdentifyNum' => $idNumber,
            'UserName' => $name,
        ];

        ksort($params);
        $queryParts = [];
        foreach ($params as $k => $v) {
            $queryParts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $queryString = implode('&', $queryParts);
        $stringToSign = 'GET&' . rawurlencode('/') . '&' . rawurlencode($queryString);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $params['Signature'] = $signature;

        $response = Http::timeout(10)->get(self::ALIYUN_ENDPOINT, $params);
        $data = $response->json();

        Log::debug('Aliyun Id2MetaVerify response', [
            'http_status' => $response->status(),
            'code' => $data['Code'] ?? null,
        ]);

        $code = $data['Code'] ?? '';
        if (!$response->successful() || !in_array($code, ['200', 200])) {
            throw new \RuntimeException($data['Message'] ?? "API 错误 (code={$code})");
        }

        $bizCode = $data['ResultObject']['BizCode'] ?? null;

        return [
            'match' => $bizCode === '1',
            'message' => match ($bizCode) {
                '1' => '认证通过',
                '2' => '姓名与身份证号不匹配',
                '3' => '未查询到该身份信息',
                default => '认证查询异常（BizCode: ' . ($bizCode ?? 'null') . '）',
            },
            'request_id' => $data['RequestId'] ?? null,
        ];
    }

    // ========== 腾讯云 ==========

    private function testTencentFaceApi(array $credentials): array
    {
        $secretId = $credentials['secret_id'] ?? '';
        $secretKey = $credentials['secret_key'] ?? '';
        $ruleId = $credentials['rule_id'] ?? '';

        if (!$secretId || !$secretKey || !$ruleId) {
            throw new \RuntimeException('腾讯云 SecretId/SecretKey/RuleId 未完整配置');
        }

        $params = [
            'RuleId' => $ruleId,
            'RedirectUrl' => 'https://example.com/callback',
            'IdCard' => '110101199001011234',
            'Name' => '张三',
        ];

        try {
            $this->callTencentApi($credentials, 'DetectAuth', $params, 'faceid');
            return ['match' => true, 'success' => true, 'message' => 'API 连通正常'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'AuthFailure')) {
                throw new \RuntimeException('SecretId/SecretKey 认证失败');
            }
            if (str_contains($msg, 'RuleId')) {
                throw new \RuntimeException('RuleId 无效或未配置');
            }
            if (str_contains($msg, 'FailedOperation')) {
                return ['match' => true, 'success' => true, 'message' => 'API 连通正常（业务测试返回预期错误）'];
            }
            throw $e;
        }
    }

    private function testTencentOcrApi(array $credentials): array
    {
        $secretId = $credentials['secret_id'] ?? '';
        $secretKey = $credentials['secret_key'] ?? '';

        if (!$secretId || !$secretKey) {
            throw new \RuntimeException('腾讯云 SecretId/SecretKey 未完整配置');
        }

        // Use a minimal base64 image (1x1 white pixel PNG) to test API connectivity
        $tinyImage = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

        $params = [
            'ImageBase64' => $tinyImage,
        ];

        try {
            $this->callTencentApi($credentials, 'BizLicenseOCR', $params, 'ocr');
            return ['match' => true, 'success' => true, 'message' => 'API 连通正常'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'AuthFailure')) {
                throw new \RuntimeException('SecretId/SecretKey 认证失败');
            }
            // If it's a business error (image not recognized), the API itself is working
            if (str_contains($msg, 'FailedOperation') || str_contains($msg, 'ImageDecode') || str_contains($msg, 'OcrFailed')) {
                return ['match' => true, 'success' => true, 'message' => 'API 连通正常（业务测试返回预期错误）'];
            }
            throw $e;
        }
    }

    private function callTencentIdVerify(array $credentials, string $name, string $idNumber): array
    {
        $params = [
            'RuleId' => $credentials['rule_id'] ?? '',
            'RedirectUrl' => config('app.url', 'https://user.sunipip.com') . '/verification/callback',
            'IdCard' => $idNumber,
            'Name' => $name,
        ];

        $result = $this->callTencentApi($credentials, 'DetectAuth', $params, 'faceid');

        return [
            'match' => true,
            'message' => '已发起人脸核身，请在微信中完成验证',
            'url' => $result['Url'] ?? '',
            'biz_token' => $result['BizToken'] ?? '',
        ];
    }

    private function callTencentApi(array $credentials, string $action, array $params, string $service = 'faceid'): array
    {
        $secretId = $credentials['secret_id'] ?? '';
        $secretKey = $credentials['secret_key'] ?? '';

        if (!$secretId || !$secretKey) {
            throw new \RuntimeException('腾讯云 SecretId/SecretKey 未配置');
        }

        $host = "{$service}.tencentcloudapi.com";

        // Determine API version based on service
        $version = match ($service) {
            'faceid' => '2018-03-01',
            'ocr' => '2018-11-19',
            default => '2018-03-01',
        };

        $region = 'ap-guangzhou';
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);

        $payload = json_encode($params);

        // TC3-HMAC-SHA256 签名
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\nx-tc-action:" . strtolower($action) . "\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $hashedPayload = hash('sha256', $payload);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

        $credentialScope = "{$date}/{$service}/tc3_request";
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n{$hashedCanonicalRequest}";

        $secretDate = hash_hmac('sha256', $date, "TC3{$secretKey}", true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $response = Http::timeout(10)
            ->withHeaders([
                'Authorization' => $authorization,
                'Content-Type' => 'application/json; charset=utf-8',
                'Host' => $host,
                'X-TC-Action' => $action,
                'X-TC-Version' => $version,
                'X-TC-Timestamp' => (string) $timestamp,
                'X-TC-Region' => $region,
            ])
            ->withBody($payload, 'application/json; charset=utf-8')
            ->post("https://{$host}");

        $data = $response->json();

        $logData = $data;
        if (isset($logData['Response'])) {
            unset($logData['Response']['BestFrame'], $logData['Response']['VideoData']);
            if (isset($logData['Response']['DetectDetail'])) {
                $logData['Response']['DetectDetail'] = '[omitted]';
            }
        }
        Log::debug("Tencent {$service}/{$action} response", [
            'http_status' => $response->status(),
            'response' => $logData,
        ]);

        $respData = $data['Response'] ?? [];
        if (isset($respData['Error'])) {
            $errCode = $respData['Error']['Code'] ?? 'Unknown';
            $errMsg = $respData['Error']['Message'] ?? '未知错误';
            throw new \RuntimeException("[{$errCode}] {$errMsg}");
        }

        return $respData;
    }
}
