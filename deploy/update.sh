#!/usr/bin/env bash
# 日常更新部署（代码已拉到服务器后执行）
# 用法：
#   cd /path/to/workspace-api
#   bash deploy/update.sh
#   bash deploy/update.sh --seed            # 同步栏目/记录类型种子
#   bash deploy/update.sh --with-dev        # composer 含 dev（一般生产不要）

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DO_SEED=0
WITH_DEV=0
for arg in "$@"; do
  case "$arg" in
    --seed) DO_SEED=1 ;;
    --with-dev) WITH_DEV=1 ;;
    -h|--help)
      sed -n '2,10p' "$0"
      exit 0
      ;;
  esac
done

echo "==> 项目目录: $ROOT"

if [[ ! -f .env ]]; then
  echo "ERROR: 未找到 .env"
  exit 1
fi

echo "==> 维护模式"
php artisan down --retry=60 || true

if [[ "$WITH_DEV" -eq 1 ]]; then
  composer install --optimize-autoloader --no-interaction
else
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "==> migrate --force"
php artisan migrate --force

if [[ "$DO_SEED" -eq 1 ]]; then
  echo "==> db:seed --force（幂等 updateOrCreate）"
  php artisan db:seed --force
fi

echo "==> storage:link"
php artisan storage:link --force 2>/dev/null || php artisan storage:link || true

echo "==> 重建缓存"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache 2>/dev/null || true

echo "==> 退出维护模式"
php artisan up

echo ""
echo "更新完成。"
