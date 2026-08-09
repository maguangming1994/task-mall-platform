# 安全、性能与恢复运行手册

## 已落地门禁

- 所有 API 响应携带请求 ID、禁止嗅探、禁止嵌入、无来源泄露、最小浏览器权限和 JSON 专用 CSP；认证、个人和后台接口强制 `no-store`。
- CORS 仅接受 `CORS_ALLOWED_ORIGINS` 中的明确来源，生产环境不得使用 `*`；HTTPS 生产响应启用一年期 HSTS。
- `/api/v1/health/live` 只验证进程存活；`/api/v1/health/ready` 在五秒缓存窗口内验证数据库、缓存、存储可写性和迁移表，不返回连接串或异常详情。
- 订单、退款、任务审核、消息投递、运营活动和举报队列已补充组合索引；管理后台按业务页懒加载，并按 Ant Design 组件拆包，单包低于 500 KB。
- `system:consistency-audit --strict` 每日检查库存预占、积分批次、订单/商品退款上限及滞留消息；异常时返回非零退出码。
- `system:recovery-drill --strict` 可逆验证数据库事务、缓存读写和本地存储读写，临时对象执行后立即清理。

## 压测基线

本机或已授权测试环境启动 API 后执行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/load-smoke.ps1 -Requests 500 -Concurrency 20 -MaxP95Ms 500
```

首期目标为核心只读 API p95 不超过 500 ms、错误率为 0。支付、领任务、结算、退款等写链路必须在隔离测试数据和明确授权下另做压测，禁止直接对生产环境压测。

## 恢复演练

1. 运行 `php artisan system:consistency-audit --strict` 并保存 JSON 结果。
2. 运行 `php artisan system:recovery-drill --strict` 验证运行依赖。
3. 恢复数据库后，先执行迁移，再执行 `privacy:replay-deletions`，防止已注销个人数据因旧备份恢复。
4. 恢复完成后依次验证 readiness、资产/库存一致性、订单与退款上限、定时任务和队列积压。
5. 未通过任何一项时保持流量关闭并回滚；不得跳过一致性审计直接开放支付。
