# SuniPIP 管理平台 - 服务器部署指南

## 服务器信息

- **IP**: 142.249.115.15
- **域名**: admin.sunipip.uk
- **站点目录**: /www/wwwroot/admin.sunipip.uk
- **运行目录**: /www/wwwroot/admin.sunipip.uk/backend/public
- **面板**: 宝塔

## 环境要求

- PHP 8.2 (宝塔安装)
- MySQL 8.0 (宝塔安装)
- Nginx 1.26 (宝塔安装)
- Composer >= 2.0
- Node.js >= 18 (前端构建用, 后续)

### PHP 8.2 必装扩展

宝塔面板 → PHP 8.2 → 设置 → 安装扩展：

| 扩展 | 用途 |
|------|------|
| fileinfo | Laravel 文件处理 |
| opcache | PHP 性能优化 |
| bcmath | 精确金额计算 |
| zip | Excel导入导出 |
| gd | 二维码生成 |

### PHP 禁用函数

宝塔面板 → PHP 8.2 → 设置 → 禁用函数，移除以下函数：

`putenv` `proc_open` `proc_get_status` `symlink`

## 部署步骤

### 1. 创建 Laravel 项目骨架

```bash
# 安装 Composer (如果没有)
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# 在临时目录创建完整 Laravel 项目
cd /tmp
composer create-project laravel/laravel sunipip-temp

# 将 Laravel 骨架合并到站点目录 (不覆盖已有文件)
cp -rn sunipip-temp/* /www/wwwroot/admin.sunipip.uk/backend/
cp -rn sunipip-temp/.* /www/wwwroot/admin.sunipip.uk/backend/ 2>/dev/null
rm -rf sunipip-temp
```

### 2. 安装依赖

```bash
cd /www/wwwroot/admin.sunipip.uk/backend

# 安装 PHP 依赖
composer install --optimize-autoloader --no-dev

# 安装额外依赖
composer require spatie/laravel-permission
composer require maatwebsite/laravel-excel
composer require spatie/laravel-query-builder

# 发布 Spatie Permission 迁移和配置
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

### 3. 配置环境

```bash
cd /www/wwwroot/admin.sunipip.uk/backend
cp .env.example .env
php artisan key:generate

# 编辑 .env 设置数据库连接
vim .env
```

关键配置项：
- `APP_URL=https://admin.sunipip.uk`
- `DB_DATABASE=sunipip`
- `DB_USERNAME=your_db_user`
- `DB_PASSWORD=your_db_password`
- `SPARK_API_URL` / `SPARK_SUPPLIER_NO` / `SPARK_AES_KEY` (已预填)

### 4. 创建数据库

宝塔面板 → 数据库 → 添加数据库，或手动执行：

```sql
CREATE DATABASE sunipip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. 执行迁移和填充

```bash
cd /www/wwwroot/admin.sunipip.uk/backend
php artisan migrate
php artisan db:seed
php artisan storage:link
```

### 6. 设置权限

```bash
chown -R www:www /www/wwwroot/admin.sunipip.uk/backend/storage
chown -R www:www /www/wwwroot/admin.sunipip.uk/backend/bootstrap/cache
chmod -R 775 /www/wwwroot/admin.sunipip.uk/backend/storage
chmod -R 775 /www/wwwroot/admin.sunipip.uk/backend/bootstrap/cache
```

### 7. 验证

```bash
cd /www/wwwroot/admin.sunipip.uk/backend
php artisan migrate:status
php artisan tinker
>>> \App\Models\User::count()  # 应返回 1 (超级管理员)
>>> \Spatie\Permission\Models\Role::pluck('name')  # 应返回 [super_admin, admin, staff, agent, user]
```

### 8. Nginx 配置

宝塔创建站点后，修改 Nginx 配置：

```nginx
server {
    listen 80;
    server_name admin.sunipip.uk;

    root /www/wwwroot/admin.sunipip.uk/backend/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-82.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

> 注意：宝塔的 PHP socket 路径一般是 `/tmp/php-cgi-82.sock`，以实际为准。

### 9. 定时任务

```bash
crontab -e
# 添加一行:
* * * * * cd /www/wwwroot/admin.sunipip.uk/backend && php artisan schedule:run >> /dev/null 2>&1
```

### 10. 队列 Worker

```bash
sudo vim /etc/systemd/system/sunipip-worker.service
```

```ini
[Unit]
Description=SuniPIP Queue Worker
After=network.target

[Service]
User=www
Group=www
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /www/wwwroot/admin.sunipip.uk/backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable sunipip-worker
sudo systemctl start sunipip-worker
```

## 本地同步命令

已配置在 `~/.zshrc` 中：

```bash
sync_sunipipuk    # 将本地代码同步到服务器
```

## 默认账户

| 用户名 | 密码 | 角色 |
|--------|------|------|
| admin | admin123456 | 超级管理员 |

其他用户（管理员/业务员/代理商）通过后台手动创建。

**重要: 部署后请立即修改默认密码！**
