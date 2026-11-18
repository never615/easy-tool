# location-result-storage 积压诊断

主要读者：开发、评审与测试环境维护人员。记录于 2026-11-19，时间均为 Asia/Shanghai。状态：诊断记录，结论已按用户补充修正；后续资源调优与自动扩容已完成，见[Horizon 资源验收](../../../../../docs/performance/2026-11-19-horizon-resource-autoscaling.md)。下文运行数据保留原诊断时点。

## 结论

**结论修正（2026-11-19）：用户确认此前数天 Horizon 都是 1 个 Pod 正常运行。** 下述短窗口的 4→1 缩容只能证明当晚观察期内消费容量变化，不能解释为什么此前单 Pod 正常、现在开始积压，因此不再将缩容定性为根本原因。

已确认当前 CPU HPA 的触发门槛与实际负载不匹配：800m CPU request、80% 目标对应约 640m，而积压时 CPU 约 400–550m，HPA 保持 1 个 Pod。当前问题优先通过调整单 Pod 的 CPU/内存资源及验证弹性伸缩处理。近期结果 Job、派发器及共享限流代码没有变化，非失败任务的平均处理时间也没有上升，但这些证据不能排除其他代码、流量或下游成本变化。

队列维持约 2,000 条不是恢复：大量任务首次领取时已超过 60 秒截止时间，业务正文未执行便失败。当前是持续过期使队列长度受到截断，历史结果可能因此出现缺口。

本轮只读检查 test；没有修改副本、HPA、队列配置或业务代码，没有触发部署、重试失败任务或清理队列。

## 环境与时间证据

观察环境为 Kubernetes `easy-admin` namespace 的 `test-*`。本轮实际镜像后缀为 `2026-11-18-23-42-10`，easy-location 为 `f22eac0ecd9e4a622ba04852ce65bdadfaede75d`，easy-tool 为 `c3d9d5f516c17868bedbd62aff1fdb402996113e`。期间其他任务有发布，不能将本轮镜像冒充上一轮事务守卫发布镜像。

Kubernetes HPA 的 `SuccessfulRescale` 事件与 Deployment 事件一致：

- 11 月 18 日 23:06:29：Horizon 扩至 4 个 Pod。
- 23:11:45：因 `All metrics below target` 缩至 2 个。
- 23:16:15：因同一原因缩至 1 个。
- 23:21:16：事务守卫修复镜像才开始创建 Horizon Pod。因此缩容早于该修复实际上线。

23:55 的 CPU HPA 为 min=1、max=4、目标为 request 的 80%；Horizon request=800m，实际约 401m/50%，desired=1。该 HPA 没有队列长度或等待年龄指标，持续积压本身不会触发扩容。

## 同口径观察

| 观察窗口，11 月 18 日 | Horizon Pod / storage worker | ready / 队首原始年龄 | 非失败完成速率 / 平均 runtime |
| --- | --- | --- | --- |
| 23:09–23:10，上一轮发布前 | 4 / 22–23 | 21–51 / 0.76–1.64 秒 | 34.14/s / 184.92 ms |
| 23:29–23:30，上一轮发布后 | 1 / 6 | 1945–2015 / 60.53–60.70 秒 | 14.85/s / 172.18 ms |
| 23:52:50–23:53:49，本轮 | 1 / 6 | 1969–2035 / 60.66–60.92 秒 | 11.87/s / 173.36 ms |

本轮约 60 秒有效窗口内，Horizon 非失败完成 711 次；全部队列保留失败 ID 的窗口增量约 21.47/s。非失败完成包括 noop/drop，不等于全部写入成功；失败速率不是所有记录逐条分类后的 storage 专属计数。自然流量、部署和其他存储维护不同，以上不是固定容量的 A/B 基准。

Horizon runtime 排除失败任务，也不包含完整取队列及部分监控成本。因此 `6 / 0.173 ≈ 34.6/s` 只是理想估算，不能作为单 Pod 实测能力。过期失败的登记、日志等可能进一步消耗消费能力，目前尚未单独计时。

## 首次领取即过期

23:55 的最近 60 秒有 1259 个保留失败 ID；取其中 200 条，全部满足：

- queue 为 `location-result-storage`，Job 为 `LocationResultStorageJob`。
- 异常为 `MaxAttemptsExceededException`。
- Horizon 保存的 reserved payload 中 `attempts=1`，表示首次领取。
- `retryUntil` 已过，失败栈包含 `markJobAsFailedIfAlreadyExceedsMaxAttempts`，发生在 `fire()` 前。
- 原始入队至失败：P50=60.87 秒，P95=61.09 秒；有效截止距离入队约 59–60 秒。

因此这 200 条不是 funnel 重排耗尽重试，也不是存储 SQL 执行超时。Laravel 的截止时间过期同样产生该异常。已通过 Pod 内只读源码检查确认，部署的 Worker、RedisJob、LuaScripts、Queue 与 Horizon 失败事件/仓库等 9 个相关文件和分析用源码一致；不是根据其他框架版本猜测。

`Queue::before` 或 `started_at` 也不能证明业务开始：Worker 先发处理事件，随后检查截止时间，最后才执行 `fire()`。Horizon 失败 payload 的 attempts 包含当次领取，而 Laravel 数据库 failer 的 raw body 口径不同，不能混用。

当前 batch 入队关闭，ready 头尾各 100 条均为未领取 payload；delayed=0。过期任务不会进入结果 Job 的历史写入及按配置启用的区域日志等处理，也没有该 Job 的自动补偿。主实时位置及 PostgreSQL 快照属于其他路径，不能据此说整次定位结果全部丢失；尚未对具体历史缺口做逐事件核对。

## 限流、连接与异常恢复

有效结果池为每 Pod 6 个 worker，共享 funnel=200，最长等待=60 秒，release=5 秒。funnel 观测关闭，不能报告长期 release=0；00:05 单次只读槽位快照为 0/200，结合首次领取失败样本，未发现共享令牌耗尽是此次主因的证据。

本轮 PG 实例活动条目为 336–340，排除探针；上限 420、超级用户保留 20。四个 LaravelS 的 Task worker 仍持有 240 条 PG socket，storage 的 6 个 worker 各持有 1 条。普通连接预算余量不能全部用于扩容：一个 LaravelS 滚动 Pod 可能再增加约 60 多条，其他客户端和终止中的旧 Pod 也需计入。

另有 35–38 条 reserved，不能解释成同等数量的活跃 worker。抽样原始年龄可达约 35.8 小时；00:05 读取的 reservation 回收剩余时间从约 12.5 分钟到 36.17 小时。有效 `retry_after=130200` 秒，宿主 `config/queue.php` 明确为最长 36 小时导出任务保留此值。短任务 worker 若异常退出，未确认 reservation 可能长时间不能回收；单凭原始年龄不能判定具体退出原因。

后续应为短存储任务分离 queue connection 的回收设置，不能直接降低长任务共用连接的 `retry_after`。这属于恢复时延问题，不能解释为当前约 2,000 条 ready 的主要来源。

## 近期代码核对与剩余不确定性

以此前已发布的 easy-location `b41fc0e29` 对比本轮 `f22eac0ec`，结果 Job、派发器、工作策略、Mongo datastore、结果 batch store/flush、`Support/Queue` 与 `Jobs/Middleware` 内容一致。最近 `c8946c98f → f22eac0ec` 主要删除退役回填命令和注册，没有增加普通结果任务量。历史版本用于定位比较范围，现状以本轮部署引用及运行证据为准。

仍需保留两个成本观察点，不能把“主因已明确”写成“所有代码均排除”：

1. easy-tool 的 `2e349f5`（11 月 18 日 18:52，早于上述前后窗口）将有界入队扩展到所有 Redis 队列，增加容量检查与独立 admission 连接，bulk 逐条确认；结果 Job 内再派发其他任务时也经过此路径。可能影响 Redis RTT/拒绝成本，但没有改变本队列 pop 逻辑，本轮未量化其分阶段耗时。
2. `MongoHistoryProtection` 的动态配置批量预取在 11 月 17 日已存在；热缓存可能减少 RTT，miss 时会增加预读。它在最近时区修复至当前版本间没有变化；缓存命中率、移动/静止流量比例和下游延迟仍可能影响处理成本。

## 下一步试验

用户已将优先方向调整为单 Pod CPU/内存资源与弹性伸缩，原 storage 专用 worker `6 → 8 → 12` 建议暂缓。先核实 CPU/内存、下游和滚动连接预算，调整流水线资源参数并观察 HPA 自动扩容、队首年龄、过期失败与 PG 连接。保持原任务截止时间，不用延长截止或清空积压掩盖不足。

若单 Pod 内增加专用 worker 不够，再评估独立 storage Deployment 或 HPA 外部队列指标，并将最大副本及滚动峰值纳入连接预算。直接恢复 4 套完整 Horizon 会复制无关队列池及 PG 连接，不宜作为未经预算的长期方案。

随后分离短任务回收配置，并为“首次领取即过期”建立独立告警。若固定容量后仍有异常，再用现有有界阶段 trace 分解 Redis、Mongo、配置读取及失败登记耗时。PgBouncer 验证排在该问题之后。

## 证据与关联

本工作树 ignored 目录 `storage/framework/storage-backlog-study/` 保存 `before.json`、`summary-before.json`、`details-before.json`、`runtime-inventory.json`、`versions.json` 和 `extra.json`。只输出聚合元数据，没有序列化业务 payload、设备标识或凭据。前一轮数据在 `storage/framework/lazy-guard-release/`；此处不覆盖原始证据。

关联：[连接治理计划](../plans/2026-11-18-postgres-connection-budget.md)、[事务守卫 test 验收](2026-11-18-lazy-transaction-guard-test.md)、[文档索引](../README.md)。本报告未登记飞书发布。
