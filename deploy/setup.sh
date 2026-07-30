#!/usr/bin/env bash
# 首次部署（服务器上 .env 已写好后执行）
# 用法：
#   cd /path/to/workspace-api
#   bash deploy/setup.sh
#   bash deploy/setup.sh --no-seed          # 不跑种子
#   bash deploy/setup.sh --force-key        # 强制重新生成 APP_KEY（会作废已有加密数据）

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

NO_SEED=0
FORCE_KEY=0
for arg in "$@"; do
  case "$arg" in
    --no-seed) NO_SEED=1 ;;
    --force-key) FORCE_KEY=1 ;;
    -h|--help)
      sed -n '2,10p' "$0"
      exit 0
      ;;
  esac
done

echo "==> 项目目录: $ROOT"

if [[ ! -f .env ]]; then
  echo "ERROR: 未找到 .env，请先配置后再跑本脚本"
  exit 1
fi

if [[ ! -d vendor ]]; then
  echo "==> composer install --no-dev"
  composer install --no-dev --optimize-autoloader --no-interaction
else
  echo "==> composer install（已有 vendor，同步依赖）"
  composer install --no-dev --optimize-autoloader --no-interaction
fi

# APP_KEY
CURRENT_KEY="$(grep -E '^APP_KEY=' .env | head -1 | cut -d= -f2- || true)"
if [[ -z "$CURRENT_KEY" || "$FORCE_KEY" -eq 1 ]]; then
  echo "==> php artisan key:generate"
  php artisan key:generate --force
else
  echo "==> APP_KEY 已存在，跳过（需要重生成请加 --force-key）"
fi

echo "==> 目录权限 storage / bootstrap/cache"
mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/public bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

echo "==> php artisan storage:link"
php artisan storage:link --force 2>/dev/null || php artisan storage:link || true

echo "==> php artisan migrate --force"
php artisan migrate --force

if [[ "$NO_SEED" -eq 0 ]]; then
  echo "==> php artisan db:seed --force（人生阶段 + 栏目/记录类型）"
  php artisan db:seed --force
else
  echo "==> 跳过 seed（--no-seed）"
fi

echo "==> 清理并重建缓存"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache 2>/dev/null || true
php artisan event:cache 2>/dev/null || true

echo ""
echo "部署完成。"
echo "建议检查："
echo "  1) APP_URL / DB_* / ALIOSS_* 是否正确"
echo "  2) Web 根目录指向 public/"
echo "  3) 健康检查: curl -I \$APP_URL/api/v1/..."
echo "  4) 日常更新请用: bash deploy/update.sh"
