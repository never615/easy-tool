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

保存配置后的地址若带有 `?saved=1`，只表示配置已保存并重定向回本页，**不会**立即采样 Redis 或 backlog；要看保存后当下的现场数据，仍需点击“采样并刷新”。

## 3. 页面操作

| 操作 | 作用 |
| --- | --- |
| 启用队列诊断 / 停用队列诊断 | 只修改总开关，不覆盖其余配置。 |
| 采样并刷新 | 立即采集当前 Redis memory、keyspace 和队列 backlog，再刷新页面。 |
| JSON | 打开当前窗口的 JSON 数据，便于接入外部工具。 |
| 复制诊断结果 | 复制 Markdown 诊断摘要；不包含完整 raw payload 或序列化 Job 对象。 |
| 配置管理 | 默认收起；仅在需要改保留时长、阈值、队列列表或 PostgreSQL 摘要时展开。 |

## 4. 数据框与口径

除非页面提示“最近一次采样”，**窗口类数据**只统计当前聚合窗口（默认 60 秒）内，诊断总开关开启后由 Horizon 上报的 `processed`、`failed` 和 `exception` 事件；同一 Job 的重试或异常可产生多条事件。**Backlog 类数据**是点击“采样并刷新”或定时快照时，对配置队列中等待消费 payload 的受限抽样，不是全量积压明细。

| 数据框 | 展示内容与单位 | 怎么看 / 限制 |
| --- | --- | --- |
| 配置状态 | 当前实际生效的总开关、窗口秒数、TTL、阈值、采样队列、榜单条数和 Redis key 前缀。 | 先确认 `enabled` 已开启；`queues` 决定哪些队列会出现在 backlog 快照中。 |
| 窗口事件 | `total` 为当前窗口的已记录结果事件总数；`processed`、`failed`、`exception` 分别是成功、失败和执行异常事件次数。 | 这是消费端结果事件，不是 Redis 中等待任务数，也不是唯一 Job 数。 |
| Redis Memory | Redis `INFO memory` 原始字段；内存字节字段会同时显示可读单位和精确字节数。 | 重点看 `used_memory`、`maxmemory`、`used_memory_peak`、碎片率和 `oom_err_count`；页面内每个字段均有说明。 |
| 队列 Backlog | 每个配置队列当前的 `Queue::size()` 值，单位为任务数。 | 它包含 ready、delayed、reserved 三种状态的总量；用于判断总积压和是否达到 warning/error 阈值，不按 Job 类型拆分。 |
| Backlog 样本 | `enabled`、单队列 `sample_count`、采样时间，以及每个队列的 `size`、`ready`、`delayed`、`reserved`、`sampled` 数；除时间外均为任务数。 | `ready` 可立即消费，`delayed` 尚未到期，`reserved` 已被 worker 领取但尚未确认；`sampled` 最多为配置的样本上限。 |
| Backlog Jobs | 按 `queue + state + Job` 汇总的**抽样积压任务数**。 | 定位样本中最多的等待任务类型；只代表抽到的 payload，不能推出该类型全量 backlog。 |
| Backlog Payload Top | 按相同维度汇总的**抽样 payload 原始序列化总大小（字节）**。 | 数值不是次数；用来发现占用 Redis 内存更大的等待任务，但不等同 Redis 中该队列的实际内存占用。 |
| Backlog 数据来源汇总 | 带安全诊断上下文的积压样本，按来源、阶段、定位模式、subject、gateway 等较粗维度汇总的**抽样积压任务数**。 | 没有诊断上下文的任务不会进入本框；用于先缩小问题范围。 |
| Backlog 数据来源 | 上一项的细粒度版本，额外可含 `locator_id`、`target_slug`，数值仍是**抽样积压任务数**。 | 用于在已知来源集中后定位到具体对象；不展示完整原始 payload。 |
| Top Jobs | 当前窗口按 Job 类名汇总的**结果事件次数**。 | 高但 backlog 低，通常表示吞吐高且仍在消费；需再看耗时和 CPU。 |
| Payload Top | 当前窗口按 Job 类名汇总的**结果事件 payload 原始序列化总大小（字节）**。 | 数值不是次数；与 Redis Memory 同时升高时，优先检查该 Job 的 payload 设计。 |
| 任务耗时 | 每个 Job 的结果事件次数、平均耗时、最大耗时、总耗时；单位为毫秒（大于等于 1 秒时同时显示秒）。 | 耗时从 worker 开始执行到结束，不含在 Redis 中排队等待的时间。 |
| 数据来源汇总 | 带安全诊断上下文的当前窗口结果事件，按较粗来源维度的**事件次数**。 | 与 Backlog 数据来源不同：本框是已执行结果，不是等待任务样本。 |
| 数据来源 | 上一项的细粒度版本，数值为**事件次数**。 | 结合 Top Jobs 和耗时定位已经执行的压力来源。 |
| 慢任务 | 耗时大于等于 `slow_job_ms` 的当前窗口结果事件，按 Job 汇总的**慢任务事件次数**。 | 先看任务耗时和依赖服务日志；该框不表示所有慢任务的总时长。 |
| 大 Payload | payload 大于等于 `large_payload_bytes` 的当前窗口结果事件，按 Job 汇总的**大 payload 事件次数**。 | 数值是命中阈值的次数，不是 payload 字节数；具体字节总量看 Payload Top。 |
| 失败任务 | `failed` 或 `exception` 的当前窗口结果事件，按 Job 汇总的**失败/异常事件次数**。 | 先从“最近事件”的异常摘要和应用日志确认是重试中异常还是失败事件。 |
| 异常状态 | 最近采样是否达到 `warning` / `error`，原因数组及检查时间。 | 原因可能是 Redis 内存或单队列 backlog 触及阈值；它是告警判断，不是错误次数。 |
| Keyspace | Redis `INFO keyspace` 返回的每个逻辑库概要，例如 key 数、过期 key 数和平均 TTL。 | 用于辅助判断 key 数与过期策略；不包含 key 名或逐 key 明细。 |
| 最近事件 | 最近一条结果事件的状态、Job、队列、payload 字节数、尝试次数、已脱敏的诊断上下文和异常摘要。 | 只是一条最新记录，不能代替失败任务榜单或完整应用日志。 |

> “采样并刷新”仅在总开关和快照开关都开启时执行。页面刚跨入新窗口时，Redis Memory、队列 Backlog、Keyspace 和 Backlog 样本最多可能回退到两分钟内最近一次成功快照；页面会明确标出采样时间，而窗口事件仍保持当前窗口口径。

## 5. Backlog 的使用顺序

1. 先看**队列 Backlog**：找出总积压任务数高或触发阈值的队列。
2. 查看同一次快照的**Backlog 样本**：分清积压是 ready、delayed 还是 reserved，并确认 `sampled` 是否已触及样本上限。
3. 用**Backlog Jobs**找样本中最多的 Job 类型，再用 **Backlog Payload Top** 判断是否有少量大 payload 放大了 Redis 占用。
4. 若业务上下文已接入，继续看**Backlog 数据来源汇总 / 数据来源**定位到来源、阶段或对象；若为空，表示积压任务没有可安全展示的诊断上下文，或任务在开启诊断前已入队，并不表示没有积压。
5. 同时对照**Top Jobs、任务耗时、慢任务和失败任务**：积压高但执行事件低通常是消费者未及时领取；执行事件高且耗时/失败高，则优先排查消费者、依赖服务或任务逻辑。

Backlog 样本不是随机或全量抽样：每个队列会先从 ready 队首取 payload，样本未满才依次取 delayed、reserved。因此 ready 已占满样本上限时，后两种状态虽然仍会显示总数，但不会出现在组成榜单。不要据单次样本直接删除、清空或重试队列；先确认 Job 的业务语义、重试策略和当前消费者状态。

## 6. 常见判断方向

| 现象 | 优先排查方向 |
| --- | --- |
| Top Jobs 高、Backlog 不高 | 吞吐高但消费可能仍正常，结合总耗时和 Horizon CPU 判断。 |
| Top Jobs 与 Backlog 都高 | 某类任务派发过快，或消费者数量/处理速度不足。 |
| Payload Top 高、Redis Memory 高 | 检查对应 Job 的 payload 是否过大。 |
| failed / exception 高 | 先检查异常日志、失败重试和依赖服务。 |
| Backlog 来源集中 | 检查该业务来源的上报量、派发链路和消费能力。 |

## 7. 配置建议

日常只使用顶部快捷开关即可。需要调整时再展开配置管理：

- `queue_diagnostic_retention_seconds`：诊断 Redis key 的保留时间，默认 24 小时。
- `queue_diagnostic_db_snapshot_enabled`：默认关闭；只有需要隔天复盘时才开启 PostgreSQL 摘要。
- `queue_diagnostic_queues`：需要采样的队列列表。
- 内存、backlog、慢任务和大 payload 阈值：应按当前环境容量设置，避免将一次偶发高峰直接视为故障。

页面保存配置后会清理本模块短 TTL 配置缓存；队列事件和 snapshot 会较快读取新值，无需因页面配置修改而重启 LaravelS 或 Horizon。

## 8. 多实例与安全边界

诊断数据以共享 Redis 为中心按应用、环境和时间窗口聚合。所有 Horizon 实例必须连接到同一 Redis，并使用一致的应用/环境标识；否则页面只能看到部分数据。

页面展示的是集群总量，不提供 Pod、hostname 或实例维度，因此不能仅凭页面确认每个 Pod 都已成功上报。需要逐实例排查时，应同时检查对应 Pod 的版本、Redis 连接和 Horizon 运行状态。

不要为了排查而长期记录完整 payload，也不要在高峰期对 Redis 执行全库 `KEYS`、`SCAN` 或清库操作。
