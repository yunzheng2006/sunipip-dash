<?php
namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VerificationController extends Controller
{
    public function __construct(protected VerificationService $verification) {}

    public function status(Request $request): JsonResponse
    {
        $customer = $request->user();

        $hasPending = $customer->pending_biz_token
            && $customer->pending_verify_at
            && $customer->pending_verify_at->diffInMinutes(now()) < 30;

        return $this->success([
            'required' => $this->verification->isRequired(),
            'verified' => $this->verification->isVerified($customer),
            'verified_type' => $customer->verified_type,
            'verified_at' => $customer->verified_at,
            'verified_name' => $customer->verified_name ? mb_substr($customer->verified_name, 0, 1) . '**' : null,
            'verified_enterprise' => $customer->verified_enterprise_name,
            'has_pending' => $hasPending,
            'pending_name' => $hasPending ? $customer->pending_verify_name : null,
        ]);
    }

    /**
     * GET /customer/verification/info
     * 实名认证详情页（隐私化处理）
     */
    public function info(Request $request): JsonResponse
    {
        $customer = $request->user();
        $verified = $this->verification->isVerified($customer);

        $data = [
            'verified' => $verified,
            'verified_type' => $customer->verified_type,
            'verified_at' => $customer->verified_at?->toDateTimeString(),
        ];

        if ($verified) {
            $name = $customer->verified_name ?? '';
            $maskedName = mb_strlen($name) > 1
                ? mb_substr($name, 0, 1) . str_repeat('*', mb_strlen($name) - 1)
                : $name;

            $data['verified_name'] = $maskedName;
            $data['verified_id_number'] = $customer->verified_id_number;

            if ($customer->verified_type === 'enterprise') {
                $data['verified_enterprise_name'] = $customer->verified_enterprise_name;
                $data['verified_credit_code'] = $customer->verified_credit_code;
                $data['verified_license_image'] = $customer->verified_license_image
                    ? Storage::disk('public')->url($customer->verified_license_image)
                    : null;
            }
        }

        return $this->success($data);
    }

    /**
     * POST /customer/verification/upgrade-enterprise/ocr
     * 个人→企业升级 Step 1: OCR营业执照
     */
    public function upgradeOcr(Request $request): JsonResponse
    {
        $customer = $request->user();

        if ($customer->verified_type !== 'personal') {
            return $this->error('仅个人认证账户可升级为企业认证', 422);
        }

        $request->validate(['image' => 'required|string']);

        try {
            $result = $this->verification->ocrBusinessLicense($request->input('image'));
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /customer/verification/upgrade-enterprise/verify
     * 个人→企业升级 Step 2: 四要素核验 + 发起法人扫脸
     */
    public function upgradeVerify(Request $request): JsonResponse
    {
        $customer = $request->user();

        if ($customer->verified_type !== 'personal') {
            return $this->error('仅个人认证账户可升级为企业认证', 422);
        }

        $data = $request->validate([
            'enterprise_name' => 'required|string|max:200',
            'credit_code' => 'required|string|max:30',
            'legal_person_name' => 'required|string|max:50',
            'legal_person_id' => 'required|string|size:18',
            'license_image' => 'required|string',
        ]);

        $result = $this->verification->verifyBusinessLicense(
            $data['enterprise_name'],
            $data['credit_code'],
            $data['legal_person_name'],
            $data['legal_person_id'],
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        // Save license image
        $imageData = base64_decode($data['license_image']);
        $path = 'verification/license/' . $customer->id . '_' . time() . '.jpg';
        Storage::disk('public')->put($path, $imageData);
        $customer->update(['verified_license_image' => $path]);

        try {
            $faceResult = $this->verification->initEnterpriseFaceVerification(
                $data['legal_person_name'],
                $data['legal_person_id'],
            );
            return $this->success([
                'verified_biz' => true,
                'url' => $faceResult['url'],
                'biz_token' => $faceResult['biz_token'],
                'message' => $result['message'],
            ]);
        } catch (\Throwable $e) {
            return $this->error('企业信息核验通过，但发起法人人脸验证失败: ' . $e->getMessage(), 422);
        }
    }

    /**
     * POST /customer/verification/upgrade-enterprise/poll
     * 个人→企业升级: 轮询法人人脸核身状态
     */
    public function upgradePoll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'biz_token' => 'required|string',
            'enterprise_name' => 'required|string|max:200',
            'credit_code' => 'required|string|max:30',
            'legal_person_name' => 'required|string|max:50',
        ]);

        $customer = $request->user();

        if ($customer->verified_type === 'enterprise') {
            return $this->success(['status' => 'success']);
        }

        $result = $this->verification->pollFaceResult($data['biz_token']);

        if ($result['status'] === 'success') {
            $idCard = $result['id_card'] ?? '';
            $maskedId = $idCard ? (substr($idCard, 0, 4) . '****' . substr($idCard, -4)) : '';

            $customer->update([
                'verified_type' => 'enterprise',
                'verified_at' => now(),
                'verified_enterprise_name' => $data['enterprise_name'],
                'verified_credit_code' => strtoupper($data['credit_code']),
                'company_name' => $data['enterprise_name'],
                'verified_name' => $data['legal_person_name'],
                'verified_id_number' => $maskedId,
            ]);
        }

        return $this->success($result);
    }

    /**
     * POST /customer/verification/personal/init
     * 个人认证：发起人脸核身
     */
    public function initPersonal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'real_name' => 'required|string|max:50',
            'id_number' => 'required|string|size:18',
        ]);

        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->error('您已完成实名认证', 422);
        }

        if ($this->verification->tryCompletePendingVerification($customer)) {
            return $this->success(['already_verified' => true], '之前的验证已通过，认证已完成');
        }

        try {
            $result = $this->verification->initFaceVerification($customer, $data['real_name'], $data['id_number']);

            $customer->update([
                'pending_biz_token' => $result['biz_token'] ?? null,
                'pending_verify_name' => $data['real_name'],
                'pending_verify_id' => $data['id_number'],
                'pending_verify_at' => now(),
            ]);

            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /customer/verification/personal/confirm
     * 个人认证：确认人脸核身结果
     */
    public function confirmPersonal(Request $request): JsonResponse
    {
        $data = $request->validate(['biz_token' => 'required|string']);

        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->error('您已完成实名认证', 422);
        }

        $result = $this->verification->confirmFaceVerification($customer, $data['biz_token']);

        return $result['success']
            ? $this->success(['verified_type' => 'personal'], $result['message'])
            : $this->error($result['message'], 422);
    }

    /**
     * POST /customer/verification/personal/poll
     * 个人认证：轮询人脸核身状态
     */
    public function pollPersonal(Request $request): JsonResponse
    {
        $data = $request->validate(['biz_token' => 'required|string']);

        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->success(['status' => 'success']);
        }

        $result = $this->verification->pollFaceResult($data['biz_token']);

        if ($result['status'] === 'success') {
            $name = $result['name'] ?? '';
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
        }

        return $this->success($result);
    }

    /**
     * POST /customer/verification/personal/resume
     * 恢复上次未完成的人脸核身（用存储的 BizToken 重新发起轮询）
     */
    public function resumePersonal(Request $request): JsonResponse
    {
        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->success(['status' => 'already_verified']);
        }

        if (!$customer->pending_biz_token || !$customer->pending_verify_at) {
            return $this->error('没有待完成的验证', 422);
        }

        if ($customer->pending_verify_at->diffInMinutes(now()) > 30) {
            $customer->update([
                'pending_biz_token' => null,
                'pending_verify_name' => null,
                'pending_verify_id' => null,
                'pending_verify_at' => null,
            ]);
            return $this->error('验证已过期，请重新发起', 422);
        }

        $result = $this->verification->pollFaceResult($customer->pending_biz_token);

        if ($result['status'] === 'success') {
            $name = $result['name'] ?? '';
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
        }

        return $this->success(array_merge($result, [
            'biz_token' => $customer->pending_biz_token,
        ]));
    }

    /**
     * POST /customer/verification/enterprise/ocr
     * 企业认证 Step 1：OCR识别营业执照
     */
    public function ocrLicense(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|string']);

        try {
            $result = $this->verification->ocrBusinessLicense($request->input('image'));
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /customer/verification/enterprise/verify
     * 企业认证 Step 2：四要素核验（企业名+信用代码+法人+身份证号）
     */
    public function verifyEnterprise(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enterprise_name' => 'required|string|max:200',
            'credit_code' => 'required|string|max:30',
            'legal_person_name' => 'required|string|max:50',
            'legal_person_id' => 'required|string|size:18',
            'license_image' => 'nullable|string',
        ]);

        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->error('您已完成实名认证', 422);
        }

        if ($this->verification->tryCompletePendingVerification($customer)) {
            return $this->success(['already_verified' => true], '之前的验证已通过，认证已完成');
        }

        $result = $this->verification->verifyBusinessLicense(
            $data['enterprise_name'],
            $data['credit_code'],
            $data['legal_person_name'],
            $data['legal_person_id'],
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        if (!empty($data['license_image'])) {
            $imageData = base64_decode($data['license_image']);
            $path = 'verification/license/' . $customer->id . '_' . time() . '.jpg';
            Storage::disk('public')->put($path, $imageData);
            $customer->update(['verified_license_image' => $path]);
        }

        // 四要素通过，发起法人人脸核身
        try {
            $faceResult = $this->verification->initEnterpriseFaceVerification(
                $data['legal_person_name'],
                $data['legal_person_id'],
            );

            $customer->update([
                'pending_biz_token' => $faceResult['biz_token'] ?? null,
                'pending_verify_name' => $data['legal_person_name'],
                'pending_verify_id' => $data['legal_person_id'],
                'pending_verify_at' => now(),
            ]);

            return $this->success([
                'verified_biz' => true,
                'url' => $faceResult['url'],
                'biz_token' => $faceResult['biz_token'],
                'message' => $result['message'],
            ]);
        } catch (\Throwable $e) {
            return $this->error('企业信息核验通过，但发起法人人脸验证失败: ' . $e->getMessage(), 422);
        }
    }

    /**
     * POST /customer/verification/enterprise/confirm
     * 企业认证 Step 3：确认法人人脸核身结果
     */
    public function confirmEnterprise(Request $request): JsonResponse
    {
        $data = $request->validate([
            'biz_token' => 'required|string',
            'enterprise_name' => 'required|string|max:200',
            'credit_code' => 'required|string|max:30',
            'legal_person_name' => 'required|string|max:50',
        ]);

        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->error('您已完成实名认证', 422);
        }

        $result = $this->verification->confirmEnterpriseFaceVerification(
            $customer,
            $data['biz_token'],
            $data['enterprise_name'],
            $data['credit_code'],
            $data['legal_person_name'],
        );

        return $result['success']
            ? $this->success(['verified_type' => 'enterprise'], $result['message'])
            : $this->error($result['message'], 422);
    }

    /**
     * POST /customer/verification/enterprise/poll
     * 企业认证：轮询法人人脸核身状态
     */
    public function pollEnterprise(Request $request): JsonResponse
    {
        $data = $request->validate([
            'biz_token' => 'required|string',
            'enterprise_name' => 'required|string|max:200',
            'credit_code' => 'required|string|max:30',
            'legal_person_name' => 'required|string|max:50',
        ]);

        $customer = $request->user();

        if ($this->verification->isVerified($customer)) {
            return $this->success(['status' => 'success']);
        }

        $result = $this->verification->pollFaceResult($data['biz_token']);

        if ($result['status'] === 'success') {
            $idCard = $result['id_card'] ?? '';
            $maskedId = $idCard ? (substr($idCard, 0, 4) . '****' . substr($idCard, -4)) : '';

            $customer->update([
                'verified_type' => 'enterprise',
                'verified_at' => now(),
                'verified_enterprise_name' => $data['enterprise_name'],
                'verified_credit_code' => strtoupper($data['credit_code']),
                'company_name' => $data['enterprise_name'],
                'verified_name' => $data['legal_person_name'],
                'verified_id_number' => $maskedId,
            ]);
        }

        return $this->success($result);
    }
}
