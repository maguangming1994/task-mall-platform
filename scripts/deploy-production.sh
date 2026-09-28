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

echo "[1/9] 校验生产 Compose 配置"
compose config --quiet

echo "[2/9] 拉取 GitHub 已构建的版本镜像：$RELEASE_VERSION"
bash "$ROOT_DIR/scripts/pull-production-images.sh"

echo "[3/9] 等待 PostgreSQL 与 Redis"
compose up -d --no-build --pull never --wait --wait-timeout 180 postgres redis

echo "[4/9] 执行前向数据库迁移"
compose run --rm --no-deps api php artisan migrate --force

if [ "${SYNC_CAMPUS_DATA_ON_DEPLOY:-false}" = "true" ]; then
  echo "[5/9] 同步全国高校公开名录"
  compose run --rm --no-deps api php artisan campuses:sync-directory

  echo "[6/9] 转存已核验的学校校徽"
  compose run --rm --no-deps api php artisan campuses:sync-branding
else
  echo "[5-6/9] 跳过高校目录和校徽全量同步（常规发布）"
fi

echo "[7/9] 启动 API、队列、调度器和 Web"
compose up -d --no-build --pull never --remove-orphans --wait --wait-timeout 240

echo "[8/9] 生成 Laravel 生产缓存"
compose exec -T api php artisan optimize

echo "[9/9] 验证全部服务与页面"
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

for base_url in http://127.0.0.1:8080 "$PUBLIC_BASE_URL"; do
  release_marker=$(curl --fail --silent --show-error --max-time 15 \
    "$base_url/release.json?commit=$RELEASE_VERSION")
  if [ "$release_marker" != "{\"release\":\"$RELEASE_VERSION\"}" ]; then
    echo "线上版本标记未匹配本次提交：$base_url (expected=$RELEASE_VERSION)" >&2
    exit 1
  fi
done

campus_smoke=$(curl --fail --silent --show-error --max-time 10 --get \
  --data-urlencode "keyword=北京大学" \
  "http://127.0.0.1:8080/api/v1/campuses")
case "$campus_smoke" in
  *'"code":"moe-4111010001"'*) ;;
  *)
    echo "高校公开列表校验失败：未找到北京大学。" >&2
    exit 1
    ;;
esac
case "$campus_smoke" in
  *'"emblem_url":null'*)
    echo "高校校徽校验失败：北京大学尚未配置校徽。" >&2
    exit 1
    ;;
  *'"emblem_url":"'*) ;;
  *)
    echo "高校校徽校验失败：公开列表没有返回校徽字段。" >&2
    exit 1
    ;;
esac

compose ps
echo "部署完成：$RELEASE_VERSION"
