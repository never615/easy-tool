# 队列诊断监控使用说明

## 1. 用途与范围

队列诊断监控用于在排查 Redis 内存升高、Horizon CPU 升高或队列 backlog 增大时，将当前窗口的队列事件、Redis 状态和 backlog 样本放在同一个页面查看。

它是一次诊断工具，不替代 Redis 云监控、基础设施监控或 Horizon 的实时工作台。默认关闭，诊断结束后应关闭总开关。

## 2. 快速开始

1. 打开“队列诊断监控”页面。
2. 点击页面顶部的“启用队列诊断”。常规使用只需这个快捷开关，配置管理默认收起。
3. 保持一段正常或异常队列流量，等待队列事件进入当前窗口。
4. 需要现场 Redis 数据时，点击“采样并刷新”。此操作只在总开关和快照开关均开启时采集 Redis memory、keyspace 与队列 backlog。
5. 查看诊断面板；需要协作排查时，点击“复制诊断结果”，将生成的 Markdown 摘要发送给协作者。
6. 排查结束后，点击“停用队列诊断”。Redis 诊断数据会按 TTL 自动过期。

## 3. 页面操作

| 操作 | 作用 |
| --- | --- |
| 启用队列诊断 / 停用队列诊断 | 只修改总开关，不覆盖其余配置。 |
| 采样并刷新 | 立即采集当前 Redis memory、keyspace 和队列 backlog，再刷新页面。 |
| JSON | 打开当前窗口的 JSON 数据，便于接入外部工具。 |
| 复制诊断结果 | 复制 Markdown 诊断摘要；不包含完整 raw payload 或序列化 Job 对象。 |
| 配置管理 | 默认收起；仅在需要改保留时长、阈值、队列列表或 PostgreSQL 摘要时展开。 |

## 4. 如何阅读数据

1. **配置状态**：确认总开关、窗口长度、阈值、队列列表和 Redis key 前缀是否符合当前环境。
2. **窗口事件**：查看当前窗口的 processed、failed、exception 和 total 计数。
3. **Redis Memory / Keyspace**：判断 Redis 内存是否接近 warning 或 error 阈值。
4. **队列 Backlog / Backlog 样本**：确认积压集中在哪个队列，以及 pending 队列中的 Job 类型、Payload 体积和业务来源。
5. **Top Jobs / 任务耗时 / Payload Top**：判断是高频 Job、慢 Job，还是大 Payload 消耗了主要资源。
6. **失败任务 / 异常状态**：优先结合应用日志检查失败重试、异常循环或已达到的阈值。
7. **数据来源汇总与明细**：在已接入安全上下文的 Job 中，按 subject、gateway、业务阶段等维度定位压力来源；不会展示完整业务上报包。

## 5. 常见判断方向

| 现象 | 优先排查方向 |
| --- | --- |
| Top Jobs 高、Backlog 不高 | 吞吐高但消费可能仍正常，结合总耗时和 Horizon CPU 判断。 |
| Top Jobs 与 Backlog 都高 | 某类任务派发过快，或消费者数量/处理速度不足。 |
| Payload Top 高、Redis Memory 高 | 检查对应 Job 的 payload 是否过大。 |
| failed / exception 高 | 先检查异常日志、失败重试和依赖服务。 |
| Backlog 来源集中 | 检查该业务来源的上报量、派发链路和消费能力。 |

## 6. 配置建议

日常只使用顶部快捷开关即可。需要调整时再展开配置管理：

- `queue_diagnostic_retention_seconds`：诊断 Redis key 的保留时间，默认 24 小时。
- `queue_diagnostic_db_snapshot_enabled`：默认关闭；只有需要隔天复盘时才开启 PostgreSQL 摘要。
- `queue_diagnostic_queues`：需要采样的队列列表。
- 内存、backlog、慢任务和大 payload 阈值：应按当前环境容量设置，避免将一次偶发高峰直接视为故障。

页面保存配置后会清理本模块短 TTL 配置缓存；队列事件和 snapshot 会较快读取新值，无需因页面配置修改而重启 LaravelS 或 Horizon。

## 7. 多实例与安全边界

诊断数据以共享 Redis 为中心按应用、环境和时间窗口聚合。所有 Horizon 实例必须连接到同一 Redis，并使用一致的应用/环境标识；否则页面只能看到部分数据。

页面展示的是集群总量，不提供 Pod、hostname 或实例维度，因此不能仅凭页面确认每个 Pod 都已成功上报。需要逐实例排查时，应同时检查对应 Pod 的版本、Redis 连接和 Horizon 运行状态。

不要为了排查而长期记录完整 payload，也不要在高峰期对 Redis 执行全库 `KEYS`、`SCAN` 或清库操作。
