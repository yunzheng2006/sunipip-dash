<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SetupPermissions extends Command
{
    protected $signature = 'permissions:setup {--fresh : Delete all existing roles/permissions first}';
    protected $description = '设置权限角色体系（技术管理员/运营管理员/客户经理/销售）';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->warn('Clearing all existing permissions...');
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            Permission::query()->delete();
            Role::query()->delete();
        }

        // Define all permissions
        $permissions = [
            // 系统设置
            'setting.manage',
            // 用户管理
            'user.view', 'user.create', 'user.edit', 'user.delete', 'user.assign_role',
            // 客户管理
            'customer.view', 'customer.view_all', 'customer.create', 'customer.edit', 'customer.delete', 'customer.topup',
            'customer.change_sales',
            'customer.view_verification', 'customer.reset_verification',
            // IP 资产
            'ip.view', 'ip.create', 'ip.edit', 'ip.delete', 'ip.import', 'ip.assign', 'ip.unassign',
            // 资产组/IP组
            'asset_group.view', 'asset_group.create', 'asset_group.edit', 'asset_group.delete',
            // 订阅
            'subscription.view', 'subscription.create', 'subscription.renew', 'subscription.cancel',
            'subscription.refund', 'subscription.transfer',
            'subscription.edit_price', 'subscription.update_expiry',
            'subscription.submit_approval',
            // 审批
            'approval.view', 'approval.review',
            // Spark
            'spark.view', 'spark.view_stock', 'spark.manage',
            // 财务
            'pricing.view', 'pricing.view_cost', 'pricing.manage', 'pricing.set_discount',
            'transaction.view',
            // 转发
            'forward.view', 'forward.manage',
            // Webhook/通知
            'webhook.view', 'webhook.manage', 'webhook.test', 'notification.view',
            // 日志
            'activity_log.view', 'activity_log.view_all',
            // 仪表盘
            'dashboard.view',
            // 数据看板
            'analytics.view',
            // 业绩
            'performance.view',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }
        $this->info('Created ' . count($permissions) . ' permissions');

        // Define roles with permissions
        $roles = [
            'super_admin' => $permissions, // All permissions

            'tech_admin' => [
                'setting.manage',
                'user.view', 'user.create', 'user.edit', 'user.delete', 'user.assign_role',
                'customer.view', 'customer.view_all',
                'ip.view',
                'asset_group.view',
                'subscription.view',
                'spark.view', 'spark.view_stock', 'spark.manage',
                'forward.view', 'forward.manage',
                'webhook.view', 'webhook.manage', 'webhook.test', 'notification.view',
                'activity_log.view', 'activity_log.view_all',
                'transaction.view',
                'dashboard.view',
            ],

            'ops_admin' => [
                'customer.view', 'customer.view_all', 'customer.create', 'customer.edit', 'customer.topup', 'customer.change_sales',
                'ip.view', 'ip.create', 'ip.edit', 'ip.delete', 'ip.import', 'ip.assign', 'ip.unassign',
                'user.view',
                'asset_group.view', 'asset_group.create', 'asset_group.edit',
                'subscription.view', 'subscription.create', 'subscription.renew', 'subscription.cancel',
                'subscription.refund', 'subscription.transfer',
                'subscription.edit_price', 'subscription.update_expiry',
                'approval.view', 'approval.review',
                'spark.view', 'spark.view_stock', 'spark.manage',
                'pricing.view', 'pricing.view_cost', 'pricing.manage',
                'transaction.view',
                'forward.view', 'forward.manage',
                'webhook.view', 'notification.view',
                'activity_log.view', 'activity_log.view_all',
                'dashboard.view',
            ],

            'manager' => [
                'customer.view', 'customer.view_all', 'customer.create', 'customer.edit', 'customer.topup',
                'ip.view', 'ip.create', 'ip.edit', 'ip.delete', 'ip.import', 'ip.assign', 'ip.unassign',
                'asset_group.view', 'asset_group.create', 'asset_group.edit',
                'subscription.view', 'subscription.create', 'subscription.renew', 'subscription.cancel',
                'subscription.refund', 'subscription.transfer',
                'subscription.edit_price', 'subscription.update_expiry',
                'approval.view', 'approval.review',
                'spark.view', 'spark.view_stock', 'spark.manage',
                'pricing.view', 'pricing.view_cost',
                'transaction.view',
                'forward.view', 'forward.manage',
                'activity_log.view',
                'dashboard.view',
            ],

            'sales' => [
                'customer.view', 'customer.create', 'customer.edit',
                'ip.view',
                'asset_group.view',
                'subscription.view', 'subscription.submit_approval',
                'approval.view',
                'spark.view', 'spark.view_stock',
                'pricing.view',
                'forward.view',
                'notification.view',
                'activity_log.view',
                'dashboard.view',
            ],

            'admin' => array_filter($permissions, fn($p) => $p !== 'user.assign_role'),

            'staff' => [
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
            ],

            'agent' => [
                'customer.view', 'customer.create',
                'ip.view',
                'subscription.view', 'subscription.renew',
                'pricing.view',
                'dashboard.view',
            ],
        ];

        // 客户角色（无后台权限）
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'api']);

        foreach ($roles as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            $role->syncPermissions($perms);
            $this->info("  Role '{$roleName}' → " . count($perms) . " permissions");
        }

        // Ensure existing super_admin users keep their role
        $superAdmins = \App\Models\User::role('super_admin')->get();
        if ($superAdmins->isEmpty()) {
            // Assign super_admin to user #1 if no super_admins exist
            $firstUser = \App\Models\User::find(1);
            if ($firstUser) {
                $firstUser->syncRoles(['super_admin']);
                $this->warn("Assigned super_admin role to user #{$firstUser->id} ({$firstUser->name})");
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->info('Permission setup complete!');
        return 0;
    }
}
