<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $newPermissions = [
            'approval.view',
            'approval.review',
            'subscription.submit_approval',
            'forward.view',
            'forward.manage',
            'pricing.view_cost',
            'customer.change_sales',
        ];

        foreach ($newPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        // 清理从未使用的权限
        $unused = [
            'customer.view_all', 'customer.view_balance', 'customer.view_own',
            'ip.view_all', 'ip.export', 'ip.view_credentials',
            'order.view', 'order.view_all', 'order.create', 'order.cancel', 'order.view_own',
            'subscription.view_all', 'subscription.view_own',
            'transaction.view_all', 'transaction.export',
            'dashboard.view_revenue', 'dashboard.view_full',
            'setting.view',
        ];
        Permission::whereIn('name', $unused)->where('guard_name', 'api')->delete();

        // 确保角色存在
        $newRoles = ['sales', 'manager', 'tech_admin', 'ops_admin'];
        foreach ($newRoles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
        }

        // 给 super_admin 同步全部权限
        $superAdmin = Role::where('name', 'super_admin')->where('guard_name', 'api')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::where('guard_name', 'api')->get());
        }

        // tech_admin / ops_admin / admin 同步几乎全部权限
        foreach (['tech_admin', 'ops_admin', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            if ($role) {
                $role->syncPermissions(
                    Permission::where('guard_name', 'api')
                        ->whereNotIn('name', ['user.assign_role'])
                        ->get()
                );
            }
        }

        // manager
        $manager = Role::where('name', 'manager')->where('guard_name', 'api')->first();
        if ($manager) {
            $manager->syncPermissions([
                'customer.view', 'customer.create', 'customer.edit', 'customer.topup',
                'customer.change_sales',
                'ip.view', 'ip.assign', 'ip.unassign',
                'asset_group.view',
                'subscription.view', 'subscription.create', 'subscription.renew',
                'subscription.cancel', 'subscription.edit_price', 'subscription.update_expiry',
                'approval.view', 'approval.review',
                'pricing.view', 'pricing.view_cost',
                'transaction.view',
                'spark.view', 'spark.manage', 'spark.view_stock',
                'forward.view', 'forward.manage',
                'notification.view',
                'dashboard.view',
                'activity_log.view',
            ]);
        }

        // staff
        $staff = Role::where('name', 'staff')->where('guard_name', 'api')->first();
        if ($staff) {
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
        }

        // sales
        $sales = Role::where('name', 'sales')->where('guard_name', 'api')->first();
        if ($sales) {
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
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // 不可逆
    }
};
