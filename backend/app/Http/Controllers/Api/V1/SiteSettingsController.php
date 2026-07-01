<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteSettingsController extends Controller
{
    /**
     * GET /site-info  (公开，无需登录)
     * 返回网站名称和 logo，供两个前端布局使用
     */
    public function info(): JsonResponse
    {
        $logoPath = SystemConfig::get('site.logo');
        $hasLogo = $logoPath && Storage::disk('public')->exists($logoPath);
        $faviconPath = SystemConfig::get('site.favicon');
        $hasFavicon = $faviconPath && Storage::disk('public')->exists($faviconPath);

        $qrPath = SystemConfig::get('support.qr_image');
        $hasQr = $qrPath && Storage::disk('public')->exists($qrPath);

        return $this->success([
            'site_name' => SystemConfig::get('site.name', 'SuniPIP'),
            'site_logo' => $hasLogo ? Storage::disk('public')->url($logoPath) : null,
            'site_favicon' => $hasFavicon ? Storage::disk('public')->url($faviconPath) : null,
            'store_banner' => $this->buildStoreBanner(),
            'float_contact' => $this->buildFloatContact(),
            'support_wechat' => SystemConfig::get('support.wechat'),
            'support_phone' => SystemConfig::get('support.phone'),
            'support_qr_image' => $hasQr ? Storage::disk('public')->url($qrPath) : null,
            'self_refund_enabled' => (bool) SystemConfig::get('customer.self_refund_enabled', false),
            'partnership_contact_image' => $this->buildPartnershipContactImage(),
            'vip_detail_image' => $this->resolveImage('partnership.vip_detail_image'),
        ]);
    }

    /**
     * 组装店铺 banner 配置，给客户面板 /store 页面顶部展示
     *   - enabled: 是否展示
     *   - promises[]: 承诺文案行
     *   - buttons[]: { label, type: link|image, url, image_url }
     */
    private function buildStoreBanner(): array
    {
        $raw = SystemConfig::get('store.banner');
        $decoded = $raw ? json_decode($raw, true) : null;
        if (!is_array($decoded)) $decoded = [];

        $buttons = [];
        foreach ($decoded['buttons'] ?? [] as $b) {
            $imagePath = $b['image_path'] ?? null;
            $imageUrl = $b['image_url'] ?? null;
            if ($imagePath && (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://'))) {
                $imageUrl = $imagePath;
            } elseif ($imagePath) {
                $imageUrl = Storage::disk('public')->exists($imagePath) ? Storage::disk('public')->url($imagePath) : $imageUrl;
            }
            $buttons[] = [
                'label'     => $b['label'] ?? '',
                'type'      => $b['type'] ?? 'link',
                'url'       => $b['url'] ?? '',
                'image_url' => $imageUrl,
            ];
        }

        // 兼容旧版 flat string[] 格式 → 转为 string[][]
        $promises = $decoded['promises'] ?? [];
        if (!empty($promises) && isset($promises[0]) && is_string($promises[0])) {
            $promises = [$promises];
        }

        return [
            'enabled'  => (bool) ($decoded['enabled'] ?? false),
            'title'    => $decoded['title'] ?? '',
            'subtitle' => $decoded['subtitle'] ?? '',
            'promises' => $promises,
            'buttons'  => $buttons,
        ];
    }

    /**
     * GET /settings/site  (管理员)
     */
    public function show(): JsonResponse
    {
        $logoPath = SystemConfig::get('site.logo');
        $hasLogo = $logoPath && Storage::disk('public')->exists($logoPath);
        $faviconPath = SystemConfig::get('site.favicon');
        $hasFavicon = $faviconPath && Storage::disk('public')->exists($faviconPath);

        return $this->success([
            'site.name' => SystemConfig::get('site.name', 'SuniPIP'),
            'site.logo' => $hasLogo ? Storage::disk('public')->url($logoPath) : null,
            'site.logo_path' => $logoPath,
            'site.favicon' => $hasFavicon ? Storage::disk('public')->url($faviconPath) : null,
            'store.forward_enabled' => (bool) SystemConfig::get('store.forward_enabled', false),
        ]);
    }

    /**
     * PUT /settings/site  (管理员)
     */
    public function update(Request $request): JsonResponse
    {
        $body = $request->json()->all();

        if (array_key_exists('site.name', $body)) {
            $name = $body['site.name'];
            if ($name && mb_strlen($name) > 50) {
                return $this->error('站名不能超过50个字符', 422);
            }
            SystemConfig::set('site.name', $name, 'string', 'site', '网站名称');
        }

        if (array_key_exists('store.forward_enabled', $body)) {
            SystemConfig::set('store.forward_enabled', $body['store.forward_enabled'] ? '1' : '0', 'boolean', 'store', '客户自助购买直连');
        }

        return $this->success(null, '设置已保存');
    }

    /**
     * POST /settings/site/logo  (管理员)
     * 上传 logo 图片
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,gif,svg,webp|max:2048', // 最大 2MB
        ]);

        $file = $request->file('logo');

        // 删除旧 logo
        $oldPath = SystemConfig::get('site.logo');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        // 保存新 logo — 固定文件名避免积累垃圾
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = $file->storeAs('site', 'logo.' . $ext, 'public');

        SystemConfig::set('site.logo', $path, 'string', 'site', '网站Logo路径');

        return $this->success([
            'logo_url' => Storage::disk('public')->url($path),
        ], 'Logo 已上传');
    }

    /**
     * DELETE /settings/site/logo  (管理员)
     * 删除 logo，恢复默认
     */
    public function deleteLogo(): JsonResponse
    {
        $path = SystemConfig::get('site.logo');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        SystemConfig::set('site.logo', '', 'string', 'site', '网站Logo路径');

        return $this->success(null, 'Logo 已删除');
    }

    /**
     * POST /settings/site/favicon
     */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $request->validate([
            'favicon' => 'required|file|mimes:png,svg,ico|max:512',
        ]);

        $file = $request->file('favicon');

        $oldPath = SystemConfig::get('site.favicon');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs('site', 'favicon.' . $ext, 'public');

        SystemConfig::set('site.favicon', $path, 'string', 'site', '网站图标路径');

        return $this->success([
            'favicon_url' => Storage::disk('public')->url($path),
        ], '图标已上传');
    }

    /**
     * DELETE /settings/site/favicon
     */
    public function deleteFavicon(): JsonResponse
    {
        $path = SystemConfig::get('site.favicon');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        SystemConfig::set('site.favicon', '', 'string', 'site', '网站图标路径');

        return $this->success(null, '图标已删除');
    }

    /**
     * GET /settings/store-banner  (管理员)
     * 返回原始配置 + 图片的完整 URL（用于编辑界面预览）
     */
    public function getStoreBanner(): JsonResponse
    {
        $raw = SystemConfig::get('store.banner');
        $decoded = $raw ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [
                'enabled'  => false,
                'promises' => [],
                'buttons'  => [],
            ];
        }

        // 兼容旧版 flat string[] → string[][]
        $promises = $decoded['promises'] ?? [];
        if (!empty($promises) && isset($promises[0]) && is_string($promises[0])) {
            $promises = [$promises];
        }
        $decoded['promises'] = $promises;

        foreach ($decoded['buttons'] ?? [] as &$b) {
            $path = $b['image_path'] ?? null;
            $b['image_url'] = $path ? Storage::disk('public')->url($path) : null;
        }
        unset($b);

        return $this->success($decoded);
    }

    /**
     * PUT /settings/store-banner  (管理员)
     * Body: { enabled, promises: [[str,...], ...], buttons: [{label,type,url,image_path}] }
     * promises 为二维数组，每个内层数组是一行承诺条目
     */
    public function updateStoreBanner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'  => 'nullable|boolean',
            'title'    => 'nullable|string|max:100',
            'subtitle' => 'nullable|string|max:500',
            'promises' => 'nullable|array',
            'promises.*' => 'nullable|array',
            'promises.*.*' => 'nullable|string|max:200',
            'buttons'  => 'nullable|array|max:8',
            'buttons.*.label' => 'nullable|string|max:50',
            'buttons.*.type'  => 'nullable|string|in:link,image',
            'buttons.*.url'   => 'nullable|string|max:500',
            'buttons.*.image_path' => 'nullable|string|max:500',
            'buttons.*.image_url'  => 'nullable|string|max:500',
        ]);

        // 每行：trim + 丢弃空串，再丢弃空行
        $promises = [];
        foreach ($data['promises'] ?? [] as $row) {
            $cleaned = array_values(array_filter(array_map(fn($p) => trim((string) $p), $row), fn($v) => $v !== ''));
            if (!empty($cleaned)) $promises[] = $cleaned;
        }

        // 校验按钮 + 保留 image_path 字段
        $buttons = [];
        foreach ($data['buttons'] ?? [] as $b) {
            $label = trim((string) ($b['label'] ?? ''));
            if ($label === '') continue;
            $buttons[] = [
                'label'      => $label,
                'type'       => in_array($b['type'] ?? 'link', ['link','image']) ? $b['type'] : 'link',
                'url'        => trim((string) ($b['url'] ?? '')),
                'image_path' => $b['image_path'] ?? null,
                'image_url'  => $b['image_url'] ?? null,
            ];
        }

        $payload = [
            'enabled'  => (bool) ($data['enabled'] ?? false),
            'title'    => trim((string) ($data['title'] ?? '')),
            'subtitle' => trim((string) ($data['subtitle'] ?? '')),
            'promises' => $promises,
            'buttons'  => $buttons,
        ];

        SystemConfig::set('store.banner', json_encode($payload, JSON_UNESCAPED_UNICODE), 'json', 'store', '店铺顶部横幅配置');

        return $this->success($payload, '已保存');
    }

    /**
     * POST /settings/store-banner/upload-image
     * 上传按钮 hover 图片（通常是客服微信 / 企微二维码）
     * Body: form-data image
     * Returns: { path, url }
     */
    public function uploadBannerImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:4096',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        // 随机文件名避免覆盖
        $path = $file->storeAs(
            'store-banner',
            'btn_' . date('YmdHis') . '_' . \Illuminate\Support\Str::random(6) . '.' . $ext,
            'public'
        );

        return $this->success([
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
        ], '图片已上传');
    }

    // ========== 合作页联系图 ==========

    private function buildPartnershipContactImage(): ?string
    {
        return $this->resolveImage('partnership.contact_image');
    }

    private function resolveImage(string $configKey): ?string
    {
        $value = SystemConfig::get($configKey);
        if (!$value) return null;
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        if (Storage::disk('public')->exists($value)) {
            return Storage::disk('public')->url($value);
        }
        return null;
    }

    public function getPartnershipContact(): JsonResponse
    {
        $path = SystemConfig::get('partnership.contact_image');
        $hasImage = $path && Storage::disk('public')->exists($path);

        return $this->success([
            'image_path' => $path,
            'image_url'  => $hasImage ? Storage::disk('public')->url($path) : null,
        ]);
    }

    public function uploadPartnershipContactImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:4096',
        ]);

        $file = $request->file('image');

        $oldPath = SystemConfig::get('partnership.contact_image');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = $file->storeAs(
            'partnership',
            'contact_' . date('YmdHis') . '_' . \Illuminate\Support\Str::random(6) . '.' . $ext,
            'public'
        );

        SystemConfig::set('partnership.contact_image', $path, 'string', 'site', '合作页联系客户经理图片');

        return $this->success([
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
        ], '图片已上传');
    }

    public function deletePartnershipContactImage(): JsonResponse
    {
        $path = SystemConfig::get('partnership.contact_image');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        SystemConfig::set('partnership.contact_image', '', 'string', 'site', '合作页联系客户经理图片');

        return $this->success(null, '图片已删除');
    }

    // ========== 通用图片配置（支持上传文件或填写 URL） ==========

    public function getImageConfig(Request $request): JsonResponse
    {
        $key = $request->validate(['key' => 'required|string|in:partnership.contact_image,partnership.vip_detail_image'])['key'];
        $value = SystemConfig::get($key);
        $isUrl = $value && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'));

        return $this->success([
            'key' => $key,
            'value' => $value,
            'mode' => $isUrl ? 'url' : 'upload',
            'resolved_url' => $this->resolveImage($key),
        ]);
    }

    public function saveImageConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|in:partnership.contact_image,partnership.vip_detail_image',
            'mode' => 'required|string|in:url,upload',
            'url' => 'required_if:mode,url|nullable|url|max:500',
            'image' => 'required_if:mode,upload|nullable|image|mimes:png,jpg,jpeg,gif,webp|max:4096',
        ]);

        $key = $data['key'];
        $oldValue = SystemConfig::get($key);
        if ($oldValue && !str_starts_with($oldValue, 'http') && Storage::disk('public')->exists($oldValue)) {
            Storage::disk('public')->delete($oldValue);
        }

        if ($data['mode'] === 'url') {
            SystemConfig::set($key, $data['url'], 'string', 'site');
            return $this->success(['resolved_url' => $data['url']], '已保存');
        }

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $dir = str_replace('.', '/', $key);
        $path = $file->storeAs(
            dirname($dir),
            basename($dir) . '_' . date('YmdHis') . '_' . \Illuminate\Support\Str::random(6) . '.' . $ext,
            'public'
        );
        SystemConfig::set($key, $path, 'string', 'site');

        return $this->success(['resolved_url' => Storage::disk('public')->url($path)], '图片已上传');
    }

    // ========== 悬浮联系按钮 ==========

    private function buildFloatContact(): array
    {
        $raw = SystemConfig::get('float_contact.buttons');
        $buttons = $raw ? json_decode($raw, true) : null;
        if (!is_array($buttons)) return [];

        return array_map(function ($b) {
            $imagePath = $b['image_path'] ?? null;
            $imageUrl = $b['image_url'] ?? null;
            if ($imagePath && (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://'))) {
                $imageUrl = $imagePath;
            } elseif ($imagePath) {
                $imageUrl = Storage::disk('public')->exists($imagePath) ? Storage::disk('public')->url($imagePath) : $imageUrl;
            }
            return [
                'label'      => $b['label'] ?? '',
                'subtitle'   => $b['subtitle'] ?? '',
                'icon_color' => $b['icon_color'] ?? 'blue',
                'type'       => $b['type'] ?? 'link',
                'url'        => $b['url'] ?? '',
                'copy_text'  => $b['copy_text'] ?? '',
                'image_url'  => $imageUrl,
            ];
        }, $buttons);
    }

    public function getFloatContact(): JsonResponse
    {
        $raw = SystemConfig::get('float_contact.buttons');
        $buttons = $raw ? json_decode($raw, true) : null;
        if (!is_array($buttons)) $buttons = [];

        foreach ($buttons as &$b) {
            $path = $b['image_path'] ?? null;
            if ($path && (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))) {
                $b['image_url'] = $path;
            } elseif ($path && Storage::disk('public')->exists($path)) {
                $b['image_url'] = Storage::disk('public')->url($path);
            } else {
                $b['image_url'] = $b['image_url'] ?? null;
            }
        }
        unset($b);

        return $this->success($buttons);
    }

    public function updateFloatContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buttons'              => 'nullable|array|max:10',
            'buttons.*.label'      => 'required|string|max:50',
            'buttons.*.subtitle'   => 'nullable|string|max:100',
            'buttons.*.icon_color' => 'nullable|string|in:blue,orange,dark,green,purple',
            'buttons.*.type'       => 'required|string|in:link,image,copy',
            'buttons.*.url'        => 'nullable|string|max:500',
            'buttons.*.copy_text'  => 'nullable|string|max:200',
            'buttons.*.image_path' => 'nullable|string|max:500',
            'buttons.*.image_url'  => 'nullable|string|max:500',
        ]);

        $buttons = [];
        foreach ($data['buttons'] ?? [] as $b) {
            $label = trim((string) ($b['label'] ?? ''));
            if ($label === '') continue;
            $buttons[] = [
                'label'      => $label,
                'subtitle'   => trim((string) ($b['subtitle'] ?? '')),
                'icon_color' => in_array($b['icon_color'] ?? 'blue', ['blue','orange','dark','green','purple']) ? $b['icon_color'] : 'blue',
                'type'       => in_array($b['type'] ?? 'link', ['link','image','copy']) ? $b['type'] : 'link',
                'url'        => trim((string) ($b['url'] ?? '')),
                'copy_text'  => trim((string) ($b['copy_text'] ?? '')),
                'image_path' => $b['image_path'] ?? null,
                'image_url'  => $b['image_url'] ?? null,
            ];
        }

        SystemConfig::set('float_contact.buttons', json_encode($buttons, JSON_UNESCAPED_UNICODE), 'json', 'site', '悬浮联系按钮配置');

        return $this->success($buttons, '已保存');
    }

    public function uploadFloatContactImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:4096',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = $file->storeAs(
            'float-contact',
            'fc_' . date('YmdHis') . '_' . \Illuminate\Support\Str::random(6) . '.' . $ext,
            'public'
        );

        return $this->success([
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
        ], '图片已上传');
    }
}
