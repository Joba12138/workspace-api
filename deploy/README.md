# workspace-api 服务器部署说明

前提：代码已放到服务器，`.env` 已写好（数据库、APP_URL、OSS 等）。

## 首次部署

```bash
cd /path/to/workspace-api
chmod +x deploy/*.sh
bash deploy/setup.sh
```

会依次执行：

1. `composer install --no-dev`
2. `php artisan key:generate`（仅当 `APP_KEY` 为空）
3. `storage:link`
4. `migrate --force`
5. `db:seed --force`（人生阶段 + 恋爱/宝宝等栏目与记录类型）
6. `config/route/view` 缓存

可选参数：

```bash
bash deploy/setup.sh --no-seed      # 不跑种子
bash deploy/setup.sh --force-key    # 强制重生成 APP_KEY（慎用）
```

## 日常发版

```bash
# git pull / 上传代码之后
bash deploy/update.sh

# 栏目/记录类型有变更时同步种子
bash deploy/update.sh --seed
```

## 常用单条命令（排障）

```bash
# 生成应用密钥（.env 里 APP_KEY 为空时）
php artisan key:generate --force

# 只跑迁移
php artisan migrate --force

# 只跑种子
php artisan db:seed --force
# 或单独：
php artisan db:seed --class=LifeStageSeeder --force
php artisan db:seed --class=CatalogSeeder --force

# Sanctum token 表（若迁移里已有可忽略）
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate --force

# 本地上传兜底需要公网可访问 storage
php artisan storage:link --force

# 清缓存 / 重建
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

# 看路由
php artisan route:list --path=api
```

## Nginx 要点

- 网站根目录：`.../workspace-api/public`
- PHP 走 php-fpm
- 若前端跨域，需在 Nginx 或 Laravel 中间件配 CORS（按你现网域名）

## `.env` 生产建议

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的api域名

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=workspace
DB_USERNAME=...
DB_PASSWORD=...

# 会话/缓存可用 database 或 redis
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# OSS（已配则可走直传；未配会走本地 upload）
ALIOSS_ACCESS_ID=...
ALIOSS_ACCESS_KEY=...
ALIOSS_BUCKET=...
ALIOSS_ENDPOINT=oss-cn-beijing.aliyuncs.com
ALIOSS_SSL=true
ALIOSS_IS_CNAME=false
```

## 健康检查示例

```bash
curl -sS "$APP_URL/api/v1/..."   # 未登录接口可测 auth/login 是否通
php artisan about
```
