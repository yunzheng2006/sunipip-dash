<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // 仅创建默认超级管理员, 其他用户通过后台手动创建
        $superAdmin = User::create([
            'username' => 'admin',
            'password' => Hash::make('admin123456'),
            'name' => '超级管理员',
            'phone' => null,
            'email' => 'admin@sunipip.com',
            'status' => 1,
        ]);
        $superAdmin->assignRole('super_admin');
    }
}
