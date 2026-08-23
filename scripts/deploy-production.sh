#!/usr/bin/env sh

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/infra/compose.production.yaml"
ENV_FILE="${PRODUCTION_ENV_FILE:-$ROOT_DIR/infra/.env.production}"

if [ ! -f "$ENV_FILE" ]; then
  echo "缺少生产配置：$ENV_FILE"
  echo "请先复制 infra/.env.production.example，并填写正式域名和密钥。"
  exit 1
fi

compose() {
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

echo "[1/8] 校验生产配置"
compose config --quiet

echo "[2/8] 拉取并构建生产镜像"
compose build --pull

echo "[3/8] 启动 PostgreSQL 与 Redis"
compose up -d postgres redis

echo "[4/8] 执行数据库迁移"
compose run --rm api php artisan migrate --force

echo "[5/8] 写入角色、协议、售后原因和系统配置定义"
compose run --rm api php artisan db:seed --force

echo "[6/8] 启动 API、队列、调度器和 Web"
compose up -d --remove-orphans

echo "[7/8] 生成 Laravel 生产缓存"
compose exec -T api php artisan optimize

echo "[8/8] 检查服务状态"
compose ps

attempt=0
until curl -fsS http://127.0.0.1:8080/gateway-health >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "网关健康检查未通过，请执行：docker compose --env-file '$ENV_FILE' -f '$COMPOSE_FILE' logs --tail=200"
    exit 1
  fi
  sleep 2
done

curl -fsS http://127.0.0.1:8080/gateway-health
echo
echo "部署完成。请继续在宝塔中启用 HTTPS，并访问 / 与 /admin/。"
