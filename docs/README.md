# easy-tool 文档索引

`easy-tool` 为 Laravel 与 Laravel Admin 扩展提供通用能力。本文档按当前用途索引运维、监控与排障资料；
开始修改包代码时也请阅读包根目录 README，并检查本仓库的 Git 状态。

## 运行监控

- [应用统计监控](app-stats-monitor.md)：应用级统计信息采集与查看。
- [Swoole Task 监控 Redis 连接](swoole-task-monitor-redis-connection.md)：Task 监控相关 Redis 连接使用说明。
- [运行时配置补填跟踪](runtime-config-refill-trace.md)：排查运行时配置补填链路。

## 队列与消息排障

- [Horizon Worker 控制台输出](horizon-worker-console-output.md)：Worker 控制台日志与输出行为。
- [MQTT 空闲休眠](mqtt-idle-sleep.md)：MQTT 订阅进程空闲处理说明。
- [队列诊断监控使用说明](queue-diagnostic-monitor-usage.md)：队列诊断监控的配置和使用方式。
- [Redis 网络与响应诊断](redis-diagnostic.md)：只读 Redis 探针的运行方式、结果解释和安全边界。

## 维护约定

当前配置契约和可执行操作应维护在本索引链接的权威文档中；时点性问题记录应标明环境、时间和状态。
调整文档路径时，同一提交更新本索引、包 README 和仍有效的 Markdown 链接。
