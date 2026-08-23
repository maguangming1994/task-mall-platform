# 腾讯云轻量服务器部署操作单

适用架构：腾讯云轻量应用服务器 + 宝塔 Linux 面板 + Docker Compose。

## 一、上线前准备

- 一个已解析到服务器公网 IP 的域名；没有域名时可先用 IP 验证，但微信登录、微信支付和小程序真机必须使用已备案的 HTTPS 域名。
- 服务器 SSH 登录信息，通常为 `root` 和临时密码，或 SSH 私钥。
- 宝塔面板登录地址与临时账号。
- 腾讯云 COS 或七牛云对象存储信息。
- 微信小程序、公众号、微信支付、短信服务的正式凭据。
- 微信支付商户私钥与平台证书文件。

> 所有密钥只写入服务器上的 `infra/.env.production` 或 `/opt/task-mall/shared/secrets/`，禁止提交 Git。

## 二、服务器基础设置

1. 在腾讯云防火墙只放行 `22`、`80`、`443`，宝塔面板端口只允许自己的固定 IP 访问。
2. 在宝塔的软件商店安装 Nginx；Docker 和 Docker Compose 使用系统包或宝塔 Docker 管理器安装。
3. 不放行 `5432`、`6379`、`8080`，这些端口只在服务器内部使用。
4. 创建目录：

   ```bash
   mkdir -p /opt/task-mall/shared/secrets
   chmod 700 /opt/task-mall/shared/secrets
   ```

## 三、获取项目并生成配置

```bash
cd /opt
git clone https://github.com/maguangming1994/task-mall-platform.git task-mall
cd /opt/task-mall
cp infra/.env.production.example infra/.env.production
chmod 600 infra/.env.production
```

编辑 `infra/.env.production`，把 `example.com`、全部 `replace-with-*` 和空白的正式服务参数替换掉。至少确认：

- `APP_URL`、`VITE_H5_PUBLIC_URL` 为同一个 HTTPS 域名；
- 四组用户端和后台安全密钥彼此不同，长度不少于 32 位；
- PostgreSQL 与 Redis 使用不同的强密码；
- `CORS_ALLOWED_ORIGINS` 只有自己的 HTTPS 域名；
- 微信、支付、短信、对象存储和文件扫描配置不是本地模拟模式；
- 运营主体、客服、隐私联系人和正式协议状态已经填写。

将证书上传到：

```text
/opt/task-mall/shared/secrets/wechat_merchant_key.pem
/opt/task-mall/shared/secrets/wechat_platform_cert.pem
```

并执行：

```bash
chmod 600 /opt/task-mall/shared/secrets/*.pem
```

## 四、首次部署

```bash
cd /opt/task-mall
chmod +x scripts/deploy-production.sh
./scripts/deploy-production.sh
```

部署脚本会幂等写入 RBAC 角色、协议、售后原因和系统配置定义。随后创建首位后台管理员：

```bash
docker compose --env-file infra/.env.production -f infra/compose.production.yaml \
  exec api php artisan admin:create first-admin --role=super_admin --name="平台管理员"
```

命令会在终端中安全询问不少于 12 位且包含字母和数字的密码。

## 五、宝塔域名与 HTTPS

1. 宝塔中新建 PHP 纯静态站点，绑定正式域名。
2. 申请并强制启用 Let's Encrypt HTTPS。
3. 站点反向代理目标填写 `http://127.0.0.1:8080`。
4. 高级配置参考 `infra/nginx/baota-reverse-proxy-snippet.conf`。
5. 验证：

   ```text
   https://你的域名/
   https://你的域名/admin/
   https://你的域名/api/v1/health
   https://你的域名/api/v1/health/ready
   ```

## 六、微信平台配置

- 小程序 request、uploadFile、downloadFile 合法域名配置为同一个 HTTPS 域名。
- 微信支付回调地址与 `.env.production` 完全一致。
- 微信公众号网页授权域名填写根域名，不带协议和路径。
- 上线前轮换任何曾经出现在聊天、截图或 Git 历史中的 AppSecret、APIv3 密钥和商户密钥。

## 七、日常更新

先备份数据库，再更新：

```bash
cd /opt/task-mall
git pull --ff-only origin main
./scripts/deploy-production.sh
```

查看服务：

```bash
docker compose --env-file infra/.env.production -f infra/compose.production.yaml ps
docker compose --env-file infra/.env.production -f infra/compose.production.yaml logs --tail=200
```

PostgreSQL 每日备份后应加密上传 COS，并定期在隔离环境做恢复演练。
