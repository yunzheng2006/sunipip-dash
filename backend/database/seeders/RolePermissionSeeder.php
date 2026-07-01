<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ========================================
        // 权限定义 (按模块分组)
        // ========================================
        $permissions = [

            // --- 后台用户管理 ---
            'user.view',
            'user.create',
            'user.edit',
            'user.delete',
            'user.assign_role',
            'user.set_auto_approve',

            // --- 客户管理 ---
            'customer.view',
            'customer.view_all',
            'customer.create',
            'customer.edit',
            'customer.delete',
            'customer.topup',
            'customer.change_sales',
            'customer.view_verification',
            'customer.reset_verification',

            // --- IP资产管理 ---
            'ip.view',
            'ip.create',
            'ip.edit',
            'ip.delete',
            'ip.import',
            'ip.assign',
            'ip.unassign',

            // --- 资产组管理 ---
            'asset_group.view',
            'asset_group.create',
            'asset_group.edit',
            'asset_group.delete',

            // --- 订阅管理 ---
            'subscription.view',
            'subscription.create',
            'subscription.renew',
            'subscription.cancel',
            'subscription.refund',
            'subscription.transfer',
            'subscription.edit_price',
            'subscription.update_expiry',
            'subscription.submit_approval',

            // --- 审批 ---
            'approval.view',
            'approval.review',

            // --- 计费/定价 ---
            'pricing.view',
            'pricing.manage',
            'pricing.view_cost',
            'transaction.view',

            // --- 业绩检索 ---
            'performance.view',
            'performance.manage',
            'performance.view_hard_cost',

            // --- Spark / IPIPV API ---
            'spark.view',
            'spark.manage',
            'spark.view_stock',

            // --- 转发管理 ---
            'forward.view',
            'forward.manage',

            // --- 通知/Webhook ---
            'notification.view',
            'webhook.view',
            'webhook.manage',
            'webhook.test',

            // --- 充值/退款 ---
            'payment.gateway_refund',

            // --- 系统设置 ---
            'setting.manage',

            // --- 仪表盘 ---
            'dashboard.view',

            // --- 数据看板 ---
            'analytics.view',

            // --- 操作日志 ---
            'activity_log.view',
            'activity_log.view_all',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // ========================================
        // 角色定义
        // ========================================

        // 1. 超级管理员 - 全部权限
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
        $superAdmin->syncPermissions(Permission::where('guard_name', 'api')->get());

        // 2. 技术管理员 - 除用户角色外的全部权限
        $techAdmin = Role::firstOrCreate(['name' => 'tech_admin', 'guard_name' => 'api']);
        $techAdmin->syncPermissions(
            Permission::where('guard_name', 'api')
                ->whereNotIn('name', ['user.assign_role'])
                ->get()
        );

        // 3. 运营管理员 - 业务+系统权限
        $opsAdmin = Role::firstOrCreate(['name' => 'ops_admin', 'guard_name' => 'api']);
        $opsAdmin->syncPermissions(
            Permission::where('guard_name', 'api')
                ->whereNotIn('name', ['user.assign_role', 'user.delete'])
                ->get()
        );

        // 4. 经理 - 管理团队+审批+查看全局数据
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'api']);
        $manager->syncPermissions([
            'customer.view', 'customer.view_all', 'customer.create', 'customer.edit', 'customer.topup',
            'customer.change_sales', 'customer.view_verification',
            'ip.view', 'ip.assign', 'ip.unassign',
            'asset_group.view',
            'subscription.view', 'subscription.create', 'subscription.renew',
            'subscription.cancel', 'subscription.refund', 'subscription.transfer',
            'subscription.edit_price', 'subscription.update_expiry',
            'approval.view', 'approval.review',
            'pricing.view', 'pricing.view_cost',
            'transaction.view',
            'payment.gateway_refund',
            'spark.view', 'spark.manage', 'spark.view_stock',
            'forward.view', 'forward.manage',
            'notification.view',
            'dashboard.view',
            'activity_log.view',
            'performance.view', 'performance.manage', 'performance.view_hard_cost',
            'analytics.view',
            'user.view', 'user.set_auto_approve',
        ]);

        // 5. 管理员 (旧, 兼容) - 同 ops_admin
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin->syncPermissions(
            Permission::where('guard_name', 'api')
                ->whereNotIn('name', ['user.assign_role'])
                ->get()
        );

        // 6. 业务员(staff) - 客户/IP/订阅日常操作
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'api']);
        $staff->syncPermissions([
            'customer.view', 'customer.create', 'customer.edit',
            'ip.view',
            'asset_group.view',
            'subscription.view', 'subscription.submit_approval',
            'subscription.renew', 'subscription.update_expiry',
            'approval.view',
            'pricing.view',
            'transaction.view',
            'spark.view_stock',
            'forward.view',
            'notification.view',
            'dashboard.view',
        ]);

        // 7. 销售(sales) - 最小权限，提交审批
        $sales = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'api']);
        $sales->syncPermissions([
            'customer.view', 'customer.create', 'customer.edit',
            'ip.view',
            'asset_group.view',
            'subscription.view', 'subscription.submit_approval',
            'approval.view',
            'pricing.view',
            'spark.view_stock',
            'forward.view',
            'notification.view',
            'dashboard.view',
        ]);

        // 8. 代理商 - 只看自己名下
        $agent = Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'api']);
        $agent->syncPermissions([
            'customer.view', 'customer.create',
            'ip.view',
            'subscription.view', 'subscription.renew',
            'pricing.view',
            'dashboard.view',
        ]);

        // 9. 客户角色 (客户自助面板)
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'api']);
    }
}
