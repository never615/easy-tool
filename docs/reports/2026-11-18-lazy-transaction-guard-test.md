# 事务清理避免主动建连：test 验收

主要读者：开发、评审与测试环境维护人员。时间：2026-11-18，Asia/Shanghai。

## 本次变更

easy-tool `7d91c204d93e455e26d0bb171a659db9745158a2` 将队列循环和异常 render 的事务清理改为仅检查已解析 DatabaseManager 中已创建的连接。未使用数据库时不会为清理主动建连；已使用的默认及非默认连接仍逐层回滚未结束事务，回滚失败继续向上传播。

计划先于代码提交，见[PostgreSQL 连接治理计划](../plans/2026-11-18-postgres-connection-budget.md)。本次不更改 persistent、进程并发、业务数据库配置或 Redis funnel；无数据库迁移。宿主仅更新异常响应测试的 mock，提交 `9a572bb5` 保存于 `codex/timezone-input-test-fix`，运行修复全部来自 easy-tool develop。

## 测试与发布

| 验证 | 结果 |
| --- | --- |
| PHP 8.2.35 最小容器，事务清理回归 | 13 tests / 33 assertions 通过；原代码同套测试 6 项失败 |
| 原异常 JSON 响应和登录重定向用例 | 5 tests / 21 assertions 通过；以最小 Application 执行，未启动真实应用 kernel |
| test 发布前容器探针 | 队列和 Handler、DB manager 未解析/已解析共 4 种组合，均触发 1 次 Connection 创建和 PDO 打开尝试 |
| test 发布后容器探针 | 全部 LaravelS/Horizon 的 4 种组合均不创建 Connection、不打开 PDO |
| 运行版本 | 镜像后缀 `2026-11-18-23-16-18`；全部应用 Pod 的 easy-tool 引用和两处文件 SHA256 一致 |
| 滚动完成 | LaravelS 4/4、Horizon 1/1、Nginx 1/1；应用 Pod 无重启，四个 LaravelS 的 liveness/readiness 均为 200 |

Webhook 实际发送一次，HTTP 200 / accepted。未取得本次 Flow run ID 与阶段明细；发布成功判断依据 Kubernetes、新镜像和实际源码验证。容器守卫探针使用最小 Application 和合成 PDO resolver，不发送业务请求、不连接业务数据库。

## 滚动期间的容量处理

此前并行发布与 HPA 扩缩容改变了观察条件。发布前有效窗口为 LaravelS 4、Horizon 4；本次滚动期间 HPA 将 LaravelS 目标调为 5，出现 6 个新旧 Pod。新 Pod readiness 返回 `db_connection_failed`，滚动无法排出旧 Pod。

本次临时将 test LaravelS 的策略由 `maxSurge=1,maxUnavailable=0` 改为 `maxSurge=0,maxUnavailable=1`，先释放旧 Pod 连接，滚动随后完成。23:29 前已恢复原策略并读取确认；没有修改 HPA、目标副本数或数据库上限。最终 LaravelS 4、Horizon 1 是当时实际 HPA 状态。

## 观察结果与限制

发布前后均采样约 60 秒、5 个点。共享数据库/Redis 指标只统计一次，进程连接通过 Pod 内 `/proc` 对有效 PG 端口的 ESTABLISHED socket 进行归属；采样非原子，不能当作严格性能基准。

| 指标 | 发布前窗口 | 发布后窗口 |
| --- | --- | --- |
| LaravelS / Horizon Pod | 4 / 4 | 4 / 1 |
| Mongo history worker | 32 | 8 |
| 其中持有 PG 的 Mongo history worker | 29–30（约 91%–94%） | 6（75%） |
| 全部应用去重 PG socket | 342–348 | 274–277 |
| PG 实例活动条目，排除探针 | 可读样本 403–406；2 次因连接不足不可读 | 334–342 |
| LaravelS Task worker 的 PG socket | 240 | 240 |
| 定位结果队列 ready / 队首原始入队年龄 | 21–51 / 0.76–1.64 秒 | 1945–2015 / 约 60.5–60.7 秒 |
| 定位结果任务完成速率 / 平均处理时间 | 34.14/s / 184.92 ms | 14.85/s / 172.18 ms |
| Mongo 历史任务完成速率 / 平均处理时间 | 1.63/s / 587.14 ms | 1.35/s / 386.85 ms |

**不能把连接总数下降归因于本次修复**：Horizon 同时从 4 个 Pod 缩为 1 个，且自然流量、历史积压、并行 Mongo 索引维护均不同。Horizon 任务完成包括非失败删除的 noop/drop，平均时间是任务处理时间，不是 SQL 耗时；此处没有可用的分位数。Swoole Task 与 funnel 观察开关关闭，rate_limit 等指标不可用，不能报告为零。

Mongo history 在新 Horizon 初期仅 1/8 持有 PG，约 8 分钟后变为 6/8；23:34:06 复查（新 Pod 创建约 13 分钟后）已恢复为 **8/8**。因此本轮没有证明该角色稳定运行时的连接数量下降。原因上有正常的 PG 读取路径：队列诊断配置共享缓存失效后由取得 refill 锁的 worker 读取配置表；Mongo 动态门禁与项目策略缓存失效后也可能读取关系库。未追踪具体建连调用栈，因此不能把某一条新增连接归因于某个缓存路径。

确定结论是：两处事务守卫不再强制创建连接，原异常响应及事务回滚行为保持。主体的 240 条 Task worker 连接尚未减少。

## 仍需跟进

发布后存在定位结果队列积压。23:31 的 60 秒失败记录窗口有 1240 个保留的失败 ID；限量分类的 100 条全部为 `LocationResultStorageJob` / `MaxAttemptsExceededException`，不是这 100 条中出现新的时区或事务清理异常。该抽样不能代表全部失败原因，失败 ID 统计也不是完整重试次数审计。

23:34:06 复查时 PG 实例活动条目为 338，定位结果 ready 为 1959、队首原始入队年龄约 60.5 秒。共享日志最后 1 MiB 的最近 5 分钟窗口有 1 条 ERROR、0 条连接槽不足错误；这是有界日志观察，不代表所有日志无错误。

应继续按计划评估连接预算和队列消费容量，特别是 HPA 按 CPU 缩容后队列等待与失败的关系。无用建连修复验收通过，不代表共享 test 的容量及队列问题已解决。

后续只读调查已核实 HPA 缩容时间线，并确认抽样任务首次领取即过期，详见[结果存储积压诊断](2026-11-19-location-result-storage-backlog.md)。本报告保留发布时的原始观察口径。

## 证据位置

本次工作树 ignored 目录 `storage/framework/lazy-guard-release/` 保存 `before.json`、`after.json`、`stable-recheck.json`、`release-target.json`、`trigger.json`、`rollout-history.json`、`deployed-verification.json`、`temporary-rollout-strategy.json`、`guard-probe-before.json`、`failure-summary.json`、`final-log-counts.json` 和测试日志。报告仅包含脱敏聚合指标，未登记飞书发布。

返回[文档索引](../README.md)。
