# Redis 网络与响应诊断

## 用途

`tool:redis_diagnostic` 是一次性、只读的 Redis 诊断命令，用于核对以下现象是否在同一时间窗出现：

- 当前 Redis 监控使用的 100ms 读取超时失败；
- 放宽到 1s 的独立对照探针可以成功；
- Redis 网络吞吐、响应耗时和队列积压同时升高。

命令不会加入 Scheduler，不会派发 Job，不会写入 Redis 状态 Key，也不会读取任务 payload。它只执行
`PING`、白名单 `INFO`、固定队列的 `LLEN/ZCARD`，并使用独立的非持久连接。

## 执行

在目标 Laravel Pod 内运行：

```bash
php artisan tool:redis_diagnostic \
  --duration=60 \
  --interval-ms=1000 \
  --queues=high,pgsql-write,location-result-storage,mid,default \
  --output-dir=/tmp/redis-diagnostics
```

如果甲方已经书面确认 Redis 所在接口的带宽上限，可以附加：

```bash
--bandwidth-limit-mbps=4000
```

该参数必须是外部确认值。没有它时，报告会明确写“带宽上限未知”，不会把吞吐量直接解释成带宽已满。

命令会生成一份 JSON 和一份脱敏 Markdown 报告。Markdown 可以直接发给甲方；JSON 用于归档和后续比对。

## 结果解释

| 报告结论 | 含义 |
| --- | --- |
| `issue_not_reproduced` | 本次未复现严格探针失败，不能确认历史日志原因 |
| `strict_read_failure_capacity_unknown` | 100ms 探针失败、1s 对照成功；存在延迟/抖动，但带宽上限未知 |
| `high_correlation_near_supplied_capacity` | 上述现象同时出现，且峰值吞吐接近外部提供上限；高度相关，疑似 Redis 或网络链路压力 |
| `redis_unavailable_or_severely_degraded` | 严格与对照探针均失败，Redis 可能不可用或严重退化 |
| `comparison_not_valid` | 两条连接无法确认命中同一 Redis 实例，不能形成有效对照 |

应用侧报告不能单独证明“带宽已满”“带宽导致超时”或“队列堆积导致超时”。正式结论还需要平台侧的
接口容量、丢包/错误、TCP 重传、节点网络和 CPU throttling 指标。Redis 的总网络输出也可能包含复制流量，
不能直接等同于业务客户端流量。

## 安全边界

报告不输出 Redis 地址、密码、Redis 版本、原始异常文本、业务 Key、队列任务内容或 payload。不要在生产环境
为取证临时启用 `MONITOR`、`KEYS`、全量 `SCAN`、`BIGKEYS`、`MEMORY USAGE` 或修改 Redis 配置。
