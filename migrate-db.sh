#!/bin/bash
set -e

# 阿里云 RDS 连接信息
DB_HOST="rm-t4nj0hs35if3vcvj7lo.mysql.singapore.rds.aliyuncs.com"
DB_PORT="3306"
DB_NAME="taiyangip_web"
DB_USER="dms_user_szy"
DB_PASS="Bwq@2026_szy"

if [ -z "$1" ]; then
  echo "用法: $0 <backup.sql.zip>"
  echo "示例: $0 sunipip_uk_managemt.sql.zip"
  exit 1
fi

FILE="$1"

if [ ! -f "$FILE" ]; then
  echo "错误: 文件 $FILE 不存在"
  exit 1
fi

echo "=== 解压 $FILE ==="
TMPDIR=$(mktemp -d)
unzip -o "$FILE" -d "$TMPDIR"

SQL_FILE=$(find "$TMPDIR" -name "*.sql" -type f | head -1)
if [ -z "$SQL_FILE" ]; then
  echo "错误: zip 中未找到 .sql 文件"
  rm -rf "$TMPDIR"
  exit 1
fi

echo "=== 找到 SQL 文件: $(basename "$SQL_FILE") ==="
echo "=== 文件大小: $(du -h "$SQL_FILE" | cut -f1) ==="
echo "=== 目标: $DB_HOST / $DB_NAME ==="
echo ""
read -p "确认导入？(y/n) " confirm
if [ "$confirm" != "y" ]; then
  echo "已取消"
  rm -rf "$TMPDIR"
  exit 0
fi

echo "=== 开始导入 ==="
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_FILE"

echo "=== 导入完成 ==="
echo "=== 验证表数量 ==="
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema='$DB_NAME';"

rm -rf "$TMPDIR"
echo "=== 清理完成 ==="
