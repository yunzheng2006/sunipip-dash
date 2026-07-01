<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * 权限模块分组定义（必须与 SetupPermissions 中的权限一致）
     */
    private array $permissionModules = [
        'dashboard' => [
            'label' => '仪表盘',
            'permissions' => [
                'dashboard.view' => '查看仪表盘',
            ],
        ],
        'customer' => [
            'label' => '客户管理',
            'permissions' => [
                'customer.view' => '查看客户（仅自己名下）',
                'customer.view_all' => '查看所有客户（跨销售）',
                'customer.create' => '创建客户',
                'customer.edit' => '编辑客户资料 / 推荐人 / 重置密码',
                'customer.delete' => '删除客户',
                'customer.topup' => '客户充值 / 余额调整',
                'customer.change_sales' => '修改业务归属人',
                'customer.view_verification' => '查看实名认证信息',
                'customer.reset_verification' => '重置 / 人工审核实名',
            ],
        ],
        'ip' => [
            'label' => 'IP 资产',
            'permissions' => [
                'ip.view' => '查看 IP 列表 / 统计',
                'ip.create' => '添加 IP / 批量添加',
                'ip.edit' => '编辑 IP / 移动分组 / 测试池管理',
                'ip.delete' => '删除 IP / 释放 IP',
                'ip.import' => '批量导入 IP',
                'ip.assign' => '分配 IP 给客户 / 测试池分配',
                'ip.unassign' => '取消分配',
            ],
        ],
        'asset_group' => [
            'label' => '资产组 / IP 组',
            'permissions' => [
                'asset_group.view' => '查看资产组 / IP 组',
                'asset_group.create' => '创建资产组 / IP 组',
                'asset_group.edit' => '编辑 / 合并资产组',
                'asset_group.delete' => '删除资产组 / IP 组',
            ],
        ],
        'subscription' => [
            'label' => '订阅管理',
            'permissions' => [
                'subscription.view' => '查看订阅列表 / 详情 / 修改备注',
                'subscription.create' => '直接创建订单（无需审批）/ 转测试',
                'subscription.submit_approval' => '提交订单（需审批流程）',
                'subscription.renew' => '续费订阅 / 批量续费',
                'subscription.cancel' => '取消订阅',
                'subscription.refund' => '退订（退款到余额 / 选择是否释放上游）',
                'subscription.transfer' => '订阅划转（转移到其他客户）',
                'subscription.edit_price' => '修改订阅价格 / 挂载转发',
                'subscription.update_expiry' => '修改到期时间',
            ],
        ],
        'approval' => [
            'label' => '审批中心',
            'permissions' => [
                'approval.view' => '查看审批列表 / 撤回自己的审批',
                'approval.review' => '审批通过 / 驳回',
            ],
        ],
        'spark' => [
            'label' => '上游 API（Spark / IPIPV）',
            'permissions' => [
                'spark.view' => '查看上游订单 / 余额 / 地区',
                'spark.view_stock' => '查看上游库存 / 产品列表',
                'spark.manage' => '上游开通 / 续费 / 释放 / 匹配 / 同步',
            ],
        ],
        'billing' => [
            'label' => '财务与定价',
            'permissions' => [
                'pricing.view' => '查看定价规则 / VIP 等级 / 推广佣金',
                'pricing.view_cost' => '查看成本价',
                'pricing.manage' => '管理定价 / 特批价 / VIP / 佣金发放',
                'pricing.set_discount' => '自主设置客户折扣（受角色最大折扣限制）',
                'transaction.view' => '查看交易流水 / 财务总览 / 充值订单',
                'payment.gateway_refund' => '原路退款（Alipay 退回支付渠道）',
            ],
        ],
        'forward' => [
            'label' => '转发中转',
            'permissions' => [
                'forward.view' => '查看中转套餐',
                'forward.manage' => '管理中转套餐（增删改）',
            ],
        ],
        'notification' => [
            'label' => '通知与 Webhook',
            'permissions' => [
                'notification.view' => '查看通知发送日志',
                'webhook.view' => '查看 Webhook 配置 / 事件类型',
                'webhook.manage' => '管理 Webhook（增删改）',
                'webhook.test' => '测试 Webhook 推送',
            ],
        ],
        'performance' => [
            'label' => '业绩管理',
            'permissions' => [
                'performance.view' => '查看业绩数据 / 手动业绩记录',
                'performance.manage' => '添加 / 删除手动业绩',
                'performance.view_hard_cost' => '查看硬成本数据（IP硬成本 / 中转硬成本 / 总硬成本）',
            ],
        ],
        'analytics' => [
            'label' => '数据看板',
            'permissions' => [
                'analytics.view' => '查看营销 / 定价 / 产品数据分析',
            ],
        ],
        'router' => [
            'label' => '软路由管理',
            'permissions' => [
                'router.view'       => '查看设备列表 / 详情 / 事件日志',
                'router.create'     => '添加设备到库存',
                'router.edit'       => '编辑设备 / 生成安装令牌 / 推送配置',
                'router.delete'     => '停用 / 删除设备',
                'router.bind'       => '绑定 / 解绑客户',
                'router.wg_manage'  => '管理 WireGuard 服务器',
            ],
        ],
        'user' => [
            'label' => '团队管理',
            'permissions' => [
                'user.view' => '查看后台用户列表',
                'user.create' => '创建后台用户',
                'user.edit' => '编辑用户 / 重置密码 / 管理邀请码',
                'user.delete' => '删除后台用户',
                'user.assign_role' => '分配 / 管理角色权限',
                'user.set_auto_approve' => '设置销售自动审批权限',
            ],
        ],
        'system' => [
            'label' => '系统设置',
            'permissions' => [
                'setting.manage' => '管理所有系统设置（支付/短信/面板/DNS/飞书等）',
                'activity_log.view' => '查看操作日志（仅自己）',
                'activity_log.view_all' => '查看所有操作日志 / 清理日志',
            ],
        ],
    ];

    /**
     * 权限依赖关系：key 依赖于 value 数组中的所有权限
     * 包含同模块层级依赖和跨模块业务依赖
     */
    private array $permissionDependencies = [
        // 客户管理 - 层级依赖
        'customer.view_all'          => ['customer.view'],
        'customer.create'            => ['customer.view'],
        'customer.edit'              => ['customer.view'],
        'customer.delete'            => ['customer.view'],
        'customer.topup'             => ['customer.view'],
        'customer.change_sales'      => ['customer.view', 'customer.view_all'],
        'customer.view_verification' => ['customer.view'],
        'customer.reset_verification'=> ['customer.view', 'customer.view_verification'],

        // IP 资产 - 层级 + 跨模块
        'ip.create'   => ['ip.view'],
        'ip.edit'     => ['ip.view'],
        'ip.delete'   => ['ip.view'],
        'ip.import'   => ['ip.view', 'ip.create'],
        'ip.assign'   => ['ip.view', 'customer.view'],
        'ip.unassign' => ['ip.view'],

        // 资产组
        'asset_group.create' => ['asset_group.view'],
        'asset_group.edit'   => ['asset_group.view'],
        'asset_group.delete' => ['asset_group.view'],

        // 订阅管理 - 跨模块业务依赖
        'subscription.create'          => ['subscription.view', 'customer.view', 'pricing.view'],
        'subscription.submit_approval' => ['subscription.view', 'customer.view', 'pricing.view'],
        'subscription.renew'           => ['subscription.view'],
        'subscription.cancel'          => ['subscription.view'],
        'subscription.refund'          => ['subscription.view'],
        'subscription.transfer'        => ['subscription.view', 'customer.view'],
        'subscription.edit_price'      => ['subscription.view', 'pricing.view'],
        'subscription.update_expiry'   => ['subscription.view'],

        // 审批
        'approval.review' => ['approval.view'],

        // 上游 API
        'spark.view_stock' => ['spark.view'],
        'spark.manage'     => ['spark.view', 'spark.view_stock'],

        // 财务
        'pricing.view_cost' => ['pricing.view'],
        'pricing.manage'    => ['pricing.view'],
        'pricing.set_discount' => ['pricing.view', 'customer.view'],
        'payment.gateway_refund' => ['transaction.view'],

        // 业绩
        'performance.manage' => ['performance.view'],
        'performance.view_hard_cost' => ['performance.view'],

        // 转发
        'forward.manage' => ['forward.view'],

        // Webhook
        'webhook.manage' => ['webhook.view'],
        'webhook.test'   => ['webhook.view'],

        // 团队
        'user.create'           => ['user.view'],
        'user.edit'             => ['user.view'],
        'user.delete'           => ['user.view'],
        'user.assign_role'      => ['user.view'],
        'user.set_auto_approve' => ['user.view'],

        // 软路由
        'router.create'    => ['router.view'],
        'router.edit'      => ['router.view'],
        'router.delete'    => ['router.view'],
        'router.bind'      => ['router.view', 'customer.view'],
        'router.wg_manage' => ['router.view'],

        // 系统
        'activity_log.view_all' => ['activity_log.view'],
    ];

    /**
     * 角色中文名映射
     */
    private array $roleLabels = [
        'super_admin' => '超级管理员',
        'tech_admin' => '技术管理员',
        'ops_admin' => '运营管理员',
        'admin' => '管理员',
        'manager' => '客户经理',
        'staff' => '业务员',
        'sales' => '销售',
        'agent' => '代理商',
        'user' => '客户',
    ];

    /**
     * 列出所有角色
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('permissions')->get();

        // 手动查 model_has_roles 获取用户数（避开 Spatie 多态关联的默认 User 模型问题）
        $userCounts = \DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $data = $roles->map(fn($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'label' => $this->roleLabels[$r->name] ?? $r->name,
            'guard_name' => $r->guard_name,
            'permissions_count' => $r->permissions_count,
            'users_count' => (int) ($userCounts[$r->id] ?? 0),
            'is_system' => in_array($r->name, ['super_admin', 'tech_admin', 'ops_admin', 'admin', 'manager', 'staff', 'sales', 'agent', 'user']),
        ]);

        return $this->success($data);
    }

    /**
     * 角色详情（含权限列表）
     */
    public function show(Role $role): JsonResponse
    {
        $permissions = $role->permissions->pluck('name')->toArray();
        $settings = is_string($role->settings) ? json_decode($role->settings, true) : ($role->settings ?? []);

        return $this->success([
            'id' => $role->id,
            'name' => $role->name,
            'label' => $this->roleLabels[$role->name] ?? $role->name,
            'permissions' => $permissions,
            'settings' => $settings,
            'is_system' => in_array($role->name, ['super_admin', 'tech_admin', 'ops_admin', 'manager', 'sales']),
        ]);
    }

    /**
     * 创建角色
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50|unique:roles,name|regex:/^[a-z_]+$/',
            'label' => 'nullable|string|max:50',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'api',
        ]);

        if (!empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return $this->success($role, '角色创建成功');
    }

    /**
     * 更新角色权限
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'super_admin') {
            return $this->error('超级管理员权限不允许修改', 403);
        }

        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
            'settings' => 'nullable|array',
            'settings.max_discount_percent' => 'nullable|integer|min:10|max:99',
        ]);

        $role->syncPermissions($data['permissions']);

        if (array_key_exists('settings', $data)) {
            $role->settings = json_encode($data['settings'] ?? []);
            $role->save();
        }

        return $this->success($role->load('permissions'), '权限更新成功');
    }

    /**
     * 删除角色
     */
    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, ['super_admin', 'tech_admin', 'ops_admin', 'manager', 'sales'])) {
            return $this->error('系统内置角色不允许删除', 403);
        }

        $userCount = \DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', \App\Models\User::class)
            ->count();

        if ($userCount > 0) {
            return $this->error('该角色下还有用户，无法删除', 422);
        }

        $role->delete();

        return $this->success(null, '角色已删除');
    }

    /**
     * 获取所有权限（按模块分组，只返回数据库中实际存在的）
     */
    public function allPermissions(): JsonResponse
    {
        $dbPermissions = Permission::where('guard_name', 'api')->pluck('name')->toArray();

        // 只保留数据库中存在的权限
        $modules = [];
        foreach ($this->permissionModules as $key => $module) {
            $filtered = [];
            foreach ($module['permissions'] as $perm => $label) {
                if (in_array($perm, $dbPermissions)) {
                    $filtered[$perm] = $label;
                }
            }
            if (!empty($filtered)) {
                $modules[$key] = [
                    'label' => $module['label'],
                    'permissions' => $filtered,
                ];
            }
        }

        // 只保留依赖中涉及到实际存在的权限
        $deps = [];
        foreach ($this->permissionDependencies as $perm => $requires) {
            if (in_array($perm, $dbPermissions)) {
                $deps[$perm] = array_values(array_filter($requires, fn($r) => in_array($r, $dbPermissions)));
            }
        }

        return $this->success([
            'modules' => $modules,
            'dependencies' => $deps,
        ]);
    }
}
