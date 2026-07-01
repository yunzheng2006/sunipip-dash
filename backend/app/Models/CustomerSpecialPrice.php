<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSpecialPrice extends Model
{
    protected $fillable = [
        'customer_id', 'country_code', 'area_code', 'city_code', 'product_id',
        'special_price', 'forward_price_video', 'forward_price_live_mobile', 'forward_price_live_pc',
        'discount_percent_static', 'discount_percent_video',
        'approved_by', 'remark', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'special_price' => 'decimal:2',
            'forward_price_video' => 'decimal:2',
            'forward_price_live_mobile' => 'decimal:2',
            'forward_price_live_pc' => 'decimal:2',
            'discount_percent_static' => 'decimal:2',
            'discount_percent_video' => 'decimal:2',
            'is_active' => 'integer',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public static function findPrice(int $customerId, array $product): ?float
    {
        return self::findTrace($customerId, $product, 'special_price')['value'];
    }

    /**
     * 中转特批价：按模块类型查找对应列
     */
    public static function findForwardPrice(int $customerId, array $product, ?string $module = 'video'): ?float
    {
        $column = self::forwardPriceColumn($module);
        return self::findTrace($customerId, $product, $column)['value'];
    }

    /**
     * 按模块查找折扣：static → discount_percent_static, video → discount_percent_video
     */
    public static function findDiscountPercent(int $customerId, array $product, string $module = 'static'): ?float
    {
        $column = self::discountColumn($module);
        return $column ? self::findTrace($customerId, $product, $column)['value'] : null;
    }

    /**
     * 返回 IP 价、中转价和折扣（按模块区分折扣列）
     */
    public static function findPriceTrace(int $customerId, array $product, ?string $forwardModule = null): array
    {
        $ip   = self::findTrace($customerId, $product, 'special_price');

        $discColumn = self::discountColumn($forwardModule);
        $nullTrace = ['value' => null, 'rule_id' => null, 'hit_scope' => null, 'trace' => []];
        $disc = $discColumn ? self::findTrace($customerId, $product, $discColumn) : $nullTrace;

        $fwdColumn = self::forwardPriceColumn($forwardModule);
        $fwd = self::findTrace($customerId, $product, $fwdColumn);

        return [
            'price'              => $ip['value'],
            'forward_price'      => $fwd['value'],
            'discount_percent'   => $disc['value'],
            'rule_id'            => $ip['rule_id'],
            'hit_scope'          => $ip['hit_scope'],
            'trace'              => $ip['trace'],
            'forward_rule_id'    => $fwd['rule_id'],
            'forward_hit_scope'  => $fwd['hit_scope'],
            'discount_rule_id'   => $disc['rule_id'],
        ];
    }

    /**
     * 通用优先级查找：按 product > city > area > country > 客户全局 找
     */
    public static function findTrace(int $customerId, array $product, string $priceField): array
    {
        $countryCode = self::norm($product['country_code'] ?? '');
        $areaCode    = self::norm($product['area_code']    ?? '');
        $cityCode    = self::norm($product['city_code']    ?? '');
        $productId   = self::norm($product['product_id']   ?? '');

        $scopes = [];

        if ($productId !== null) {
            $scopes[] = ['label' => 'A product', 'cond' => ['product_id' => $productId]];
        }
        if ($countryCode !== null && $cityCode !== null) {
            $scopes[] = ['label' => 'B city', 'cond' => [
                'country_code' => $countryCode,
                'city_code'    => $cityCode,
                'product_id'   => false,
            ]];
        }
        if ($countryCode !== null && $areaCode !== null) {
            $scopes[] = ['label' => 'C area', 'cond' => [
                'country_code' => $countryCode,
                'area_code'    => $areaCode,
                'product_id'   => false,
                'city_code'    => false,
            ]];
        }
        if ($countryCode !== null) {
            $scopes[] = ['label' => 'D country', 'cond' => [
                'country_code' => $countryCode,
                'area_code'    => false,
                'city_code'    => false,
                'product_id'   => false,
            ]];
        }
        $scopes[] = ['label' => 'E customer-wide', 'cond' => [
            'country_code' => false,
            'area_code'    => false,
            'city_code'    => false,
            'product_id'   => false,
        ]];

        $trace = [];
        foreach ($scopes as $s) {
            $query = static::where('customer_id', $customerId)
                ->where('is_active', 1)
                ->whereNotNull($priceField);
            foreach ($s['cond'] as $k => $v) {
                if ($v === false) {
                    $query->where(function ($q) use ($k) {
                        $q->whereNull($k)->orWhere($k, '');
                    });
                } else {
                    $query->where($k, $v);
                }
            }
            $rule = $query->orderByDesc('id')->first();
            $trace[] = [
                'scope'  => $s['label'],
                'cond'   => $s['cond'],
                'hit_id' => $rule?->id,
            ];
            if ($rule) {
                return [
                    'value'     => (float) $rule->{$priceField},
                    'rule_id'   => $rule->id,
                    'hit_scope' => $s['label'],
                    'trace'     => $trace,
                ];
            }
        }

        return ['value' => null, 'rule_id' => null, 'hit_scope' => null, 'trace' => $trace];
    }

    private static function forwardPriceColumn(?string $module): string
    {
        return match ($module) {
            'live_mobile' => 'forward_price_live_mobile',
            'live_pc'     => 'forward_price_live_pc',
            default       => 'forward_price_video',
        };
    }

    private static function discountColumn(?string $module): ?string
    {
        return match ($module) {
            'video'         => 'discount_percent_video',
            'live_mobile',
            'live_pc'       => null,
            default         => 'discount_percent_static',
        };
    }

    private static function norm($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
