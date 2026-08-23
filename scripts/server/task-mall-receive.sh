#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly APP_DIR="/www/wwwroot/task-mall-platform"
readonly ENV_FILE="$APP_DIR/infra/.env.preview"
readonly RELEASES_DIR="/www/wwwroot/task-mall-releases"
readonly CURRENT_LINK="/www/wwwroot/task-mall-current"
readonly PUBLIC_BASE_URL="https://rwsc.jxgxnxs.cn"
readonly BACKUP_DIR="/www/backups/task-mall"
readonly STATE_DIR="/var/lib/task-mall"
readonly LOCK_FILE="/var/lock/task-mall-production-deploy.lock"
readonly MAX_BUNDLE_BYTES=536870912
readonly MIN_FREE_KB=5242880
readonly INCOMING_REF="refs/task-mall/incoming"
readonly RELEASE_RECORD="$APP_DIR/.git/task-mall-production-release"

export PUBLIC_BASE_URL

fail() {
  echo "部署拒绝：$*" >&2
  exit 1
}

require_free_space() {
  local path=$1
  local label=$2
  local available_kb

  available_kb=$(df -Pk "$path" | awk 'NR == 2 { print $4 }')
  [[ "$available_kb" =~ ^[0-9]+$ ]] || fail "无法读取 $label 的可用空间"
  [ "$available_kb" -ge "$MIN_FREE_KB" ] || fail "$label 可用空间不足 5 GiB"
}

read_env_value() {
  local key=$1
  local values
  local value

  mapfile -t values < <(awk -v key="$key" '
    index($0, key "=") == 1 { print substr($0, length(key) + 2) }
  ' "$ENV_FILE")
  [ "${#values[@]}" -eq 1 ] || fail "环境文件必须且只能定义一次 $key"
  value=${values[0]%$'\r'}
  case "$value" in
    \"*\"|\'*\') value=${value:1:${#value}-2} ;;
  esac
  [ -n "$value" ] || fail "$key 不能为空"
  printf '%s\n' "$value"
}

for command_name in docker git flock curl sha256sum df awk; do
  command -v "$command_name" >/dev/null 2>&1 || fail "服务器缺少命令 $command_name"
done

original_command=${SSH_ORIGINAL_COMMAND:-}
if [[ ! "$original_command" =~ ^deploy[[:space:]]([0-9a-f]{40})$ ]]; then
  fail "仅允许 deploy <40位提交号>"
fi
readonly RELEASE_SHA=${BASH_REMATCH[1]}

exec 9>"$LOCK_FILE"
flock -n 9 || fail "已有生产部署正在执行"

[ -d "$APP_DIR/.git" ] || fail "项目目录不是 Git 仓库：$APP_DIR"
[ -f "$ENV_FILE" ] || fail "缺少线上环境文件：$ENV_FILE"
[ "$(git -C "$APP_DIR" symbolic-ref --quiet --short HEAD)" = "main" ] || fail "线上仓库必须位于 main 分支"

for key_name in WECHAT_PAYMENT_PRIVATE_KEY_FILE WECHAT_PAYMENT_PLATFORM_CERT_FILE; do
  secret_path=$(read_env_value "$key_name")
  case "$secret_path" in
    /*) ;;
    *) fail "$key_name 必须使用仓库外绝对路径" ;;
  esac
  case "$secret_path" in
    "$APP_DIR"/*|"$RELEASES_DIR"/*) fail "$key_name 不能位于 Git 仓库或发布目录内" ;;
  esac
  [ -f "$secret_path" ] || fail "$key_name 指向的文件不存在"
done

bundle_file=$(mktemp /tmp/task-mall-release.XXXXXX.bundle)
backup_temp=''
record_temp=''
cleanup() {
  rm -f "$bundle_file"
  if [ -n "$backup_temp" ]; then
    rm -f "$backup_temp"
  fi
  if [ -n "$record_temp" ]; then
    rm -f "$record_temp"
  fi
}
trap cleanup EXIT INT TERM

head -c $((MAX_BUNDLE_BYTES + 1)) > "$bundle_file"
bundle_size=$(wc -c < "$bundle_file")
[ "$bundle_size" -gt 0 ] || fail "收到的发布包为空"
[ "$bundle_size" -le "$MAX_BUNDLE_BYTES" ] || fail "发布包超过 512 MiB 限制"

git -C "$APP_DIR" bundle verify "$bundle_file" >/dev/null
git -C "$APP_DIR" fetch --force "$bundle_file" \
  refs/heads/task-mall-release:"$INCOMING_REF"

resolved_sha=$(git -C "$APP_DIR" rev-parse "$INCOMING_REF^{commit}")
[ "$resolved_sha" = "$RELEASE_SHA" ] || fail "发布包提交号与请求不一致"

current_sha=$(git -C "$APP_DIR" rev-parse HEAD)
previous_release=$current_sha
previous_dir=$APP_DIR
if [ -f "$RELEASE_RECORD" ]; then
  recorded_release=$(cat "$RELEASE_RECORD")
  if [[ "$recorded_release" =~ ^[0-9a-f]{40}$ ]]; then
    previous_release=$recorded_release
    previous_dir="$RELEASES_DIR/$previous_release"
    [ -d "$previous_dir" ] || fail "缺少上次成功版本目录：$previous_dir"
    [ "$(git -C "$previous_dir" rev-parse HEAD)" = "$previous_release" ] || \
      fail "上次成功版本目录与记录不一致"
  fi
fi

git -C "$APP_DIR" merge-base --is-ancestor "$previous_release" "$RELEASE_SHA" || \
  fail "目标提交不是上次成功版本的快进更新"

install -d -m 700 "$STATE_DIR"
rollback_override="$STATE_DIR/rollback-$previous_release.yaml"
cat > "$rollback_override" <<EOF
services:
  api:
    image: task-mall-api:$previous_release
  queue:
    image: task-mall-api:$previous_release
  scheduler:
    image: task-mall-api:$previous_release
  nginx:
    image: task-mall-web:$previous_release
EOF

while IFS= read -r -d '' release_path; do
  normalized_path=${release_path#./}
  lower_path=${normalized_path,,}
  case "$lower_path" in
    .secrets|.secrets/*|*/.secrets|*/.secrets/*|*.pem|*.key|*.p12|*.pfx|*.crt|*.cer|*.kdbx|*.dump|*.sql|*.bak|*.backup|id_rsa|*/id_rsa|id_ed25519|*/id_ed25519)
      fail "目标提交包含禁止进入生产包的敏感路径：$normalized_path"
      ;;
    .env|*/.env|.env.*|*/.env.*)
      case "$lower_path" in
        .env.example|*/.env.example) ;;
        *) fail "目标提交包含禁止进入生产包的环境文件：$normalized_path" ;;
      esac
      ;;
  esac
done < <(git -C "$APP_DIR" ls-tree -r --name-only -z "$RELEASE_SHA")

compose() {
  PRODUCTION_ENV_FILE="$ENV_FILE" RELEASE_VERSION="$previous_release" docker compose \
    --env-file "$ENV_FILE" \
    -f "$previous_dir/infra/compose.production.yaml" \
    "$@"
}

echo "[server 1/5] 校验当前生产配置"
compose config --quiet
require_free_space /www "/www"
docker_root=$(docker info --format '{{.DockerRootDir}}')
[ -d "$docker_root" ] || fail "Docker 数据目录不存在：$docker_root"
require_free_space "$docker_root" "Docker 数据目录"

for service in api nginx; do
  container_id=$(compose ps -q "$service")
  [ -n "$container_id" ] || fail "缺少正在运行的上一版容器：$service"
  expected_image="task-mall-api:$previous_release"
  if [ "$service" = "nginx" ]; then
    expected_image="task-mall-web:$previous_release"
  fi
  tagged_image_id=$(docker image inspect --format '{{.Id}}' "$expected_image" 2>/dev/null) || \
    fail "缺少上一版回滚镜像：$expected_image"
  running_image_id=$(docker inspect --format '{{.Image}}' "$container_id")
  [ "$running_image_id" = "$tagged_image_id" ] || \
    fail "上一版回滚镜像与当前容器不一致：$service"
done

echo "[server 2/5] 备份 PostgreSQL"
install -d -m 700 "$BACKUP_DIR"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="$BACKUP_DIR/pre-deploy-$timestamp-$RELEASE_SHA.dump"
backup_temp="$backup_file.tmp"
# shellcheck disable=SC2016 # Variables expand inside the PostgreSQL container.
compose exec -T postgres sh -ec \
  'PGPASSWORD="$POSTGRES_PASSWORD" exec pg_dump --format=custom --no-owner --no-acl --username="$POSTGRES_USER" "$POSTGRES_DB"' \
  > "$backup_temp"
[ -s "$backup_temp" ] || fail "数据库备份为空"
compose exec -T postgres pg_restore --list < "$backup_temp" >/dev/null
mv "$backup_temp" "$backup_file"
backup_temp=''
sha256sum "$backup_file" > "$backup_file.sha256"

echo "[server 3/5] 准备独立发布目录 $RELEASE_SHA"
install -d -m 755 "$RELEASES_DIR"
release_dir="$RELEASES_DIR/$RELEASE_SHA"
if [ -e "$release_dir" ]; then
  [ -d "$release_dir" ] || fail "发布路径不是目录：$release_dir"
  [ "$(git -C "$release_dir" rev-parse HEAD)" = "$RELEASE_SHA" ] || \
    fail "现有发布目录提交号不一致"
else
  git -C "$APP_DIR" worktree add --detach "$release_dir" "$RELEASE_SHA"
fi
[ -f "$release_dir/scripts/deploy-production.sh" ] || fail "目标提交缺少生产部署脚本"

rollback_application() {
  local service
  local container_id
  local expected_image
  local actual_image

  if [ "$previous_release" = "$RELEASE_SHA" ]; then
    return 1
  fi
  if ! docker image inspect "task-mall-api:$previous_release" "task-mall-web:$previous_release" >/dev/null 2>&1; then
    return 1
  fi

  echo "尝试恢复上一版应用镜像：$previous_release" >&2
  PRODUCTION_ENV_FILE="$ENV_FILE" RELEASE_VERSION="$previous_release" docker compose \
    --env-file "$ENV_FILE" \
    -f "$previous_dir/infra/compose.production.yaml" \
    -f "$rollback_override" \
    up -d --no-build --remove-orphans --wait --wait-timeout 240 || return 1

  for service in api queue scheduler nginx; do
    container_id=$(PRODUCTION_ENV_FILE="$ENV_FILE" RELEASE_VERSION="$previous_release" docker compose \
      --env-file "$ENV_FILE" \
      -f "$previous_dir/infra/compose.production.yaml" \
      -f "$rollback_override" \
      ps -q "$service")
    [ -n "$container_id" ] || return 1
    expected_image="task-mall-api:$previous_release"
    if [ "$service" = "nginx" ]; then
      expected_image="task-mall-web:$previous_release"
    fi
    actual_image=$(docker inspect --format '{{.Config.Image}}' "$container_id")
    [ "$actual_image" = "$expected_image" ] || return 1
    [ "$(docker inspect --format '{{.State.Running}}' "$container_id")" = "true" ] || return 1
  done

  curl --fail --silent --show-error --max-time 10 \
    http://127.0.0.1:8080/api/v1/health/ready >/dev/null || return 1
  curl --fail --silent --show-error --max-time 10 \
    "$PUBLIC_BASE_URL/api/v1/health/ready" >/dev/null || return 1
}

echo "[server 4/5] 构建、迁移并更新服务"
if ! RELEASE_VERSION="$RELEASE_SHA" PRODUCTION_ENV_FILE="$ENV_FILE" \
  bash "$release_dir/scripts/deploy-production.sh"; then
  if rollback_application; then
    echo "新版本部署失败，应用已恢复到 $previous_release；数据库保持前向迁移状态。" >&2
  else
    echo "新版本部署失败，未找到可用的上一版镜像，请立即人工检查。" >&2
  fi
  exit 1
fi

echo "[server 5/5] 记录成功版本"
next_link="$CURRENT_LINK.next"
rm -f "$next_link"
ln -s "$release_dir" "$next_link"
mv -Tf "$next_link" "$CURRENT_LINK"
git -C "$APP_DIR" update-ref refs/task-mall/last-successful "$RELEASE_SHA"
git -C "$APP_DIR" update-ref -d "$INCOMING_REF"
record_temp="$RELEASE_RECORD.tmp.$$"
printf '%s\n' "$RELEASE_SHA" > "$record_temp"
mv "$record_temp" "$RELEASE_RECORD"
record_temp=''

echo "生产发布成功：$RELEASE_SHA"
echo "数据库备份：$backup_file"
