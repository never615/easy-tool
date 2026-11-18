# easy-tool 文档索引

`easy-tool` 为 Laravel 与 Laravel Admin 扩展提供通用能力。本文档按当前用途索引运维、监控与排障资料；
开始修改包代码时也请阅读包根目录 README，并检查本仓库的 Git 状态。

## 使用与配置（甲方管理人员）

### 接口日志

- [自有接口日志使用说明](owner-api-log-operations.md)：通过管理页面开启记录、配置接口白名单、查询请求与响应、调整日志保留时间。

## 运维实施

### 接口日志

- [自有接口日志实施维护说明](owner-api-log-maintenance.md)：日志存储、部署默认配置、生效验证、每日清理与排障。

### 运行监控

- [应用统计监控](app-stats-monitor.md)：应用级统计信息采集与查看。
- [Swoole Task 监控 Redis 连接](swoole-task-monitor-redis-connection.md)：Task 监控相关 Redis 连接使用说明。
- [运行时配置补填跟踪](runtime-config-refill-trace.md)：排查运行时配置补填链路。

### 队列与消息排障

- [Horizon Worker 控制台输出](horizon-worker-console-output.md)：Worker 控制台日志与输出行为。
- [MQTT 空闲休眠](mqtt-idle-sleep.md)：MQTT 订阅进程空闲处理说明。
- [队列诊断监控使用说明](queue-diagnostic-monitor-usage.md)：队列诊断监控的配置和使用方式。
- [Redis 网络与响应诊断](redis-diagnostic.md)：只读 Redis 探针的运行方式、结果解释和安全边界。

## 开发参考

### 数据库与队列

- [PostgreSQL 连接治理计划](plans/2026-11-18-postgres-connection-budget.md)：进行中；先避免事务清理主动建连，再依据 test 观察评估连接预算与事务池，仅 Git。

## 开发与验收记录

### 数据库与队列

- [事务清理避免主动建连：test 验收](reports/2026-11-18-lazy-transaction-guard-test.md)：守卫回归、部署版本与连接观察；记录容量问题和对比限制。
- [location-result-storage 积压诊断](reports/2026-11-19-location-result-storage-backlog.md)：CPU HPA 缩容、首次领取即过期及专用消费者容量试验建议。

## 维护约定

当前配置契约和可执行操作应维护在本索引链接的权威文档中；时点性问题记录应标明环境、时间和状态。
调整文档路径时，同一提交更新本索引、包 README 和仍有效的 Markdown 链接。
