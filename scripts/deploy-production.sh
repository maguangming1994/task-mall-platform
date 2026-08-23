#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/infra/compose.production.yaml"
ENV_FILE="${PRODUCTION_ENV_FILE:-$ROOT_DIR/infra/.env.production}"
RELEASE_VERSION="${RELEASE_VERSION:-$(git -C "$ROOT_DIR" rev-parse --verify HEAD)}"
PUBLIC_BASE_URL="${PUBLIC_BASE_URL:-https://rwsc.jxgxnxs.cn}"

case "$RELEASE_VERSION" in
  *[!0-9a-f]*|'')
    echo "RELEASE_VERSION 必须是小写十六进制提交号。" >&2
    exit 1
    ;;
esac

if [ ! -f "$ENV_FILE" ]; then
  echo "缺少生产配置：$ENV_FILE" >&2
  exit 1
fi

for command_name in docker curl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "缺少命令：$command_name" >&2
    exit 1
  fi
done

export RELEASE_VERSION

compose() {
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

wait_for_url() {
  local url=$1
  local label=$2
  local attempt=0

  until curl --fail --silent --show-error --max-time 10 "$url" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
      echo "$label 健康检查未通过：$url" >&2
      return 1
    fi
    sleep 2
  done
}

verify_service() {
  local service=$1
  local container_id
  local running
  local health

  container_id=$(compose ps -q "$service")
  if [ -z "$container_id" ]; then
    echo "服务没有容器：$service" >&2
    return 1
  fi

  running=$(docker inspect --format '{{.State.Running}}' "$container_id")
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id")
  if [ "$running" != "true" ] || { [ "$health" != "none" ] && [ "$health" != "healthy" ]; }; then
    echo "服务状态异常：$service (running=$running, health=$health)" >&2
    return 1
  fi
}

echo "[1/7] 校验生产 Compose 配置"
compose config --quiet

echo "[2/7] 构建版本镜像：$RELEASE_VERSION"
compose build --pull api nginx

echo "[3/7] 等待 PostgreSQL 与 Redis"
compose up -d --wait --wait-timeout 180 postgres redis

echo "[4/7] 执行前向数据库迁移"
compose run --rm --no-deps api php artisan migrate --force

echo "[5/7] 启动 API、队列、调度器和 Web"
compose up -d --remove-orphans --wait --wait-timeout 240

echo "[6/7] 生成 Laravel 生产缓存"
compose exec -T api php artisan optimize

echo "[7/7] 验证全部服务与页面"
for service in postgres redis api queue scheduler nginx; do
  verify_service "$service"
done

wait_for_url "http://127.0.0.1:8080/gateway-health" "Nginx 网关"
wait_for_url "http://127.0.0.1:8080/api/v1/health/ready" "API readiness"
wait_for_url "http://127.0.0.1:8080/" "移动端"
wait_for_url "http://127.0.0.1:8080/admin/" "管理后台"
wait_for_url "$PUBLIC_BASE_URL/api/v1/health/ready" "公网 API readiness"
wait_for_url "$PUBLIC_BASE_URL/" "公网移动端"
wait_for_url "$PUBLIC_BASE_URL/admin/" "公网管理后台"

compose ps
echo "部署完成：$RELEASE_VERSION"
