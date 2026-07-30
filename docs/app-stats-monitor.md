# AppStats 应用运行监控运维手册

## 1. 适用范围与安全边界

AppStats 是 Laravel Admin“监控中心”下的只读集群运行状态页面，用来同时观察：

- 每个 LaravelS Pod 的 Swoole Worker、Task Worker、连接数和 SkyLab TCP 快照；
- 由现有 Laravel Scheduler 采集的 PostgreSQL 连接余量、锁等待、长事务和 `locators` 热表统计；
- 由现有 Laravel Scheduler 采集的 MongoDB 可用性、连接容量和 WiredTiger 缓存状态；
- 由现有 Laravel Scheduler 采集的共享 Redis 内存状态；
- EasyLocation `mqtt-publish` 共享 Redis Stream 的长度、Pending 和消费者状态。

管理页面地址为：

```text
GET /admin/monitor/app_stats
GET /admin/monitor/app_stats/data
```

页面路由与数据路由分别受 `admin_monitor.app_stats`、
`admin_monitor.app_stats.data` 权限保护。给角色授权时必须同时授予两项权限；只授予页面权限会导致
页面外壳可以打开，但首屏、手动和自动刷新数据请求均会被拒绝。

页面和数据接口只读取当前状态，不会执行修复、重试、内存采样、Pod 清理、Stream 消费或告警状态
写入。打开页面不会启动进程，也不会增加 Swoole Task、Horizon Job 或 Scheduler 任务。PostgreSQL
、MongoDB 和 Redis 内存采样由既有 Scheduler 执行，Pod 写入和过期清理由既有
`swoole-stats-collector` 进程执行，MQTT 失败重试由既有 MQTT 发布进程执行。

排障时禁止对生产 Redis 使用 `KEYS`、全库 `SCAN` 或 `FLUSHDB`。本功能使用的 Key 都是固定
名称，没有遍历 Redis Key 空间的必要；也不要从管理页面推断或执行任何业务 Stream 清理。

## 2. Redis 连接与固定 Key

### 2.1 连接语义

AppStats 不要求新增 Redis 实例，三类命名连接有不同的 socket 使用方式，但都必须指向该环境所有
Pod 可见的同一套共享 `REDIS_*` 端点和逻辑数据库：

| 数据 | Laravel 连接 | 说明 |
| --- | --- | --- |
| Pod 快照 | `app_stats.redis_connection`，默认 `null`，即 Laravel `default` | 所有 Pod 必须共享；若指向 Pod 本地 Redis，页面只能看到当前 Pod |
| PostgreSQL 状态 | `database.postgres_monitor.state_redis_connection`，默认 `redis-monitor` | 只保存 Scheduler 生成的一个小快照；AppStats 页面不会直接查询 PostgreSQL |
| MongoDB 状态 | `database.mongodb_monitor.state_redis_connection`，默认 `redis-monitor` | 只保存 Scheduler 生成的一个小快照；AppStats 页面不会直接查询 MongoDB |
| Redis 内存状态 | `database.redis_monitor.connection`，默认 `redis-monitor` | 使用非持久、短超时连接观察同一共享 Redis，避免 Scheduler 采样占用业务 socket |
| MQTT Publish Stream | `mqtt-publish` | 独立连接用于常驻发布进程，但端点仍是共享 `REDIS_*`，不能改为每个 Pod 的 `LOCAL_REDIS_*` |

固定 Pod Key 为：

```text
swoole_stats:pods                 # Hash：field 为 hostname，value 为最近快照
swoole_stats:pods:last_seen       # ZSet：member 为 hostname，score 为最近采样 Unix 时间戳
```

默认 Redis 内存状态 Key 带环境 hash tag，避免多个环境误用同一 Redis 时互相覆盖：

```text
easy-tool:redis-memory-monitor:{APP_ENV}:state
easy-tool:postgres-monitor:{APP_ENV}:state
easy-tool:mongodb-monitor:{APP_ENV}:state
```

实际值以 `config('database.redis_monitor.state_key')` 为准。例如 `APP_ENV=production` 时通常为
`easy-tool:redis-memory-monitor:{production}:state`。历史部署或显式覆盖为无环境后缀时，检查命令是：

```bash
redis-cli --raw GET easy-tool:redis-memory-monitor:state
```

执行本文 Redis 命令前，必须确认 `redis-cli` 连接的是上述共享 Redis 和正确逻辑 DB。不同环境不应
共用同一逻辑 DB，因为两个 Pod Key 是有意固定的，未包含环境前缀。不要在命令、工单或日志中粘贴
Redis 密码、完整连接串或 Stream payload。

### 2.2 `allkeys-lru` 的影响

本地部署允许 Redis 使用 `allkeys-lru`，以便在内存不足时优先保证 Redis 继续服务。因此 AppStats
Key、Redis 内存状态 Key 和 MQTT Stream 都可能被淘汰；AppStats 是观测面，不把这些 Key 当作
持久化数据源。

- Pod Hash/ZSet 被淘汰后，健康的采集进程会在后续两个五秒采集周期内逐步重建当前 Pod 状态。
- Redis 内存状态被淘汰后，页面应显示“等待首次采样”，下一次一分钟 Scheduler 采样会重建它。
- PostgreSQL 状态被淘汰后也会显示“等待首次采样”，下一次一分钟 Scheduler 采样会重建它。
- MongoDB 状态被淘汰后也会显示“等待首次采样”，下一次一分钟 Scheduler 采样会重建它。
- MQTT Stream 或消费组异常会让 MQTT 区块显示错误；页面不会重建消费组或恢复已淘汰消息。
- 状态 Key 缺失不能解释为“Redis 内存正常”。可能原因包括监控未启用、尚未首次采样、
  `allkeys-lru` 淘汰或 Scheduler 未运行。

## 3. Pod 状态语义

`swoole-stats-collector` 在每个 Pod 内复用一个既有自定义进程，每五秒通过一次 pipeline 执行
固定的 `HSET + ZADD`。页面按样本年龄分类：

| 样本年龄 | 状态 | 页面行为 |
| --- | --- | --- |
| `<= 15` 秒 | `online` | 计入在线 Pod 并显示在表格中 |
| `> 15` 且 `<= 60` 秒 | `delayed` | 计入延迟 Pod 并显示明确延迟状态 |
| `> 60` 秒 | `expired` | 不再显示为在线，也不进入默认 Pod 表格，但计入过期数量 |
| `> 300` 秒 | cleanup candidate | 由采集进程后台清理 Hash/ZSet；页面请求不执行清理 |

正常 reload/stop 时，进程会尝试移除自身 hostname；异常退出时依赖上述过期判断和后台清理。
Pod store 以 hostname 作为 Hash field 和 ZSet member，因此同一 hostname 不会同时保留新旧两份
快照：hostname 复用时，新快照会覆盖旧 field，`instance_id` 用来确认进程已经更换，而不是让新旧
实例并存。正常退出并成功执行 stop hook 的旧 Pod 可以立即消失；异常退出或 stop hook 未执行时，
旧样本才会依次进入 `delayed`、`expired`。

### 3.1 页面刷新与详情展开

管理页面打开时只请求一次最新快照，之后默认不自动刷新。需要持续观察时，由用户显式勾选
“自动刷新”；轮询间隔使用数据接口返回的 `refresh_seconds`，默认五秒。取消勾选后立即清除页面
定时器，但仍可使用“立即刷新”获取一次最新数据。自动刷新开关只在当前页面生命周期内有效，
不会写入 Cookie、数据库或 Redis；重新进入页面时仍默认关闭。

Pod 行内“查看 Swoole / SkyLab 完整指标”的展开状态按 `instance_id` 保存在当前页面内。手动刷新或
自动刷新重新渲染 Pod 表格时，同一实例的详情保持展开；Pod 重启产生新的 `instance_id` 后按新实例
处理，不沿用旧进程的展开状态。

## 4. 五项固定诊断命令

先确认路由已经由当前部署加载：

```bash
php artisan route:list --name=admin_monitor.app_stats
```

预期同时看到页面路由 `admin_monitor.app_stats` 和数据路由
`admin_monitor.app_stats.data`。如果缺失，先检查部署产物实际安装的 EasyTool 版本，并确认
Composer path 安装使用了包含 AppStats 路由的包提交；随后重建配置/路由缓存并 reload LaravelS。
不要通过添加临时根路由绕过包路由。

读取 Pod Hash 条目数：

```bash
redis-cli HLEN swoole_stats:pods
```

读取最后活跃 ZSet 成员数：

```bash
redis-cli ZCARD swoole_stats:pods:last_seen
```

部署并完成两个采集周期（约十秒）后，`HLEN`、`ZCARD` 应大致等于活跃 Pod 数。滚动发布、
实例刚退出或后台清理尚未运行时，两者可能短暂不相等；持续不一致才需要检查采集进程日志、共享
Redis 连接和 reload 是否完成。

查看每个 Pod 的最后采样 Unix 时间戳：

```bash
redis-cli ZRANGE swoole_stats:pods:last_seen 0 -1 WITHSCORES
```

将 score 与当前时间比较：超过 60 秒的 Hash 项不会继续显示为在线，超过 300 秒的项是后台清理
候选。该命令只读取固定 ZSet，不要改用 `KEYS` 或 `SCAN` 搜索 Pod 数据。

读取 Redis 内存监控状态：

```bash
redis-cli --raw GET easy-tool:redis-memory-monitor:state
```

该命令适用于 `state_key` 明确配置为这个无环境后缀值的部署。默认宿主配置带 `{APP_ENV}`，执行时
应把参数替换为 `config('database.redis_monitor.state_key')` 的精确结果。有效 schema-v2 JSON 应
包含 `level`、`reasons`、`snapshot` 和 `last_sampled_at`。空结果可能是监控关闭、首次采样尚未
发生、`allkeys-lru` 淘汰或 Scheduler 故障，不能把空结果诊断为正常内存状态。

## 5. Redis 内存监控启用条件

Redis 内存采样默认开启；若某个部署需要停用，可在 `.env` 显式设置
`REDIS_MEMORY_MONITOR_ENABLED=false`。要显示真实内存指标，发布配置还必须满足：

1. `REDIS_MEMORY_MONITOR_CONNECTION` 指向 `database.redis.redis-monitor`（默认即
   `redis-monitor`），并与业务共享 Redis 使用同一 `REDIS_*` 端点/逻辑 DB；
2. 配置缓存已经重新生成，LaravelS 已 reload；
3. 项目既有 Laravel Scheduler 正常每分钟运行。

启用后只会向现有 Scheduler 注册一个 `tool:redis_memory_monitor` 命令，使用
`onOneServer()` 和 `withoutOverlapping()` 限制多 Pod 重复与重叠执行。它每分钟执行固定规模的
`INFO memory`、`INFO stats` 和单状态 Key 读写，不新增常驻监控进程、Horizon Job 或 Swoole
Task。状态超过两个调度周期（默认 120 秒）未更新时，页面显示“采样延迟”，而不是沿用旧的
`normal`。

### 5.1 PostgreSQL 监控与 HDD 开销边界

PostgreSQL 监控默认开启；可通过 `POSTGRES_MONITOR_ENABLED=false` 关闭。默认连接为 `pgsql`，
状态写入 `redis-monitor`，部署后必须重新生成配置缓存并 reload LaravelS。启用后只向现有
Scheduler 注册一个 `tool:postgres_database_monitor`，使用 `onOneServer()`、
`withoutOverlapping(5)` 和一分钟周期，不新增常驻进程、Horizon Job 或 Swoole Task。

每分钟核心采样只读取 `pg_settings`、`pg_stat_activity`、`pg_locks` 和
`pg_stat_database` 的固定规模统计，且在只读事务内设置默认 1 秒 `statement_timeout` 和
200 毫秒 `lock_timeout`。这些数据主要来自 PostgreSQL 共享统计/锁管理内存，不扫描业务表。
`locators` 的 `pg_stat_user_tables` 统计和 `pg_relation_size`、`pg_indexes_size`、
`pg_total_relation_size` 元数据读取默认降频到 300 秒一次。采样禁止执行 `COUNT(*)`、
`VACUUM`、`ANALYZE`、统计重置或任何业务行读取，因此在 HDD 部署中的额外磁盘 I/O 通常可以
忽略；若实际 `sample_duration_ms` 持续超过 200 毫秒，页面会告警，应先排查数据库本身的锁等待、
系统目录压力或磁盘故障，不要提高采样频率。

连接余量按普通客户端容量计算：

```text
normal_capacity = max_connections - superuser_reserved_connections - reserved_connections
normal_headroom = normal_capacity - 当前 client backend 数
```

采样连接自身不计入当前客户端数。默认达到 70% 使用率告警、85% 报错；连接余量、锁等待、事务中
空闲连接、最长事务、死锁增量、缓存命中率和 `locators` 的估算死元组/HOT 更新率会展示在
AppStats。`pg_stat_user_tables.n_live_tup`、`n_dead_tup` 是估算值，不应当作精确业务行数。

### 5.2 MongoDB 监控与 HDD 开销边界

MongoDB 监控默认开启；可通过 `MONGODB_MONITOR_ENABLED=false` 关闭。采样使用独立的
`mongodb-monitor` 短超时连接，每分钟执行固定规模的 `ping`、`hello` 和一次裁剪后的
`serverStatus`。这些命令读取进程、连接与 WiredTiger 的内存态累计指标，不扫描数据库、集合、
索引或业务文档，因此不会因数据量增长而增加 HDD 扫描压力。

监控明确禁止周期执行 `dbStats`、`collStats`、集合枚举、文档计数、`validate`、`compact` 或
业务聚合。`dbStats` 的成本可能随数据库和数据文件规模增长，尤其不适合 HDD 上的一分钟采样。

`serverStatus` 可能需要额外监控权限。若业务账号没有该权限，但 `ping` 和 `hello` 成功，页面显示
“MongoDB 在线，但当前账号无 serverStatus 详细指标权限”，不会误报数据库离线；如需连接容量、常驻内存和
WiredTiger 缓存指标，应为监控连接授予最小化的 `serverStatus`/`clusterMonitor` 权限，不需要写
权限。页面上的“采样未刷新”只表示 Redis 快照超过默认 120 秒未更新，并非 MongoDB 业务读写延迟。

## 6. MQTT 指标与预留 dead stream

MQTT 区块使用 `mqtt-publish` 共享连接，每次页面数据请求只执行固定数量的 `XLEN`、`XPENDING`
和 `XINFO`，不读取消息 payload。页面展示的 `mqtt_publish_dead` 是预留 Stream：当前发布进程
不会把最终失败 payload 写入死信队列，`dead_stream_enabled` 固定为 `false`，因此该 Stream
通常不存在或长度为零。它不是已启用的 DLQ，也不需要人工消费。

发布失败仍按 MQTT 进程既有 pending 重试边界处理；超过上限后记录 Laravel 错误日志并按当前
策略确认、删除原消息，不向 `mqtt_publish_dead` 追加 payload。AppStats 只展示结果，不会 repair、
retry、claim、ack、delete 或 trim 任何 MQTT 消息。

## 7. 发布步骤

1. 确认审核通过的 EasyTool、EasyLocation 指定提交已经进入镜像构建上下文，并在实际安装产物中
   核对包版本；同时确认没有 LaravelS 三方库、Swoole Task 投递或 Horizon 业务变更。
2. 部署宿主代码并按现有 laradock 构建流程重新安装依赖；重建项目既有配置/路由缓存。宿主
   `.gitignore` 有意忽略 `composer.lock`，构建脚本也会删除旧 lock 后重新执行 Composer 安装，
   因此本地生成的 lock 只是部署构建产物，不属于宿主 Git 审核内容。审核依据是两个包的指定提交
   和最终构建产物中实际安装的版本，而不是把整份本地 lock 强制加入宿主仓库。
3. 运行 EasyTool 菜单和权限 Seeder。生产环境可执行最小范围命令：

   ```bash
   php artisan db:seed --class='Mallto\Tool\Seeder\MenuTablesSeeder' --force
   php artisan db:seed --class='Mallto\Tool\Seeder\PermissionTablesSeeder' --force
   ```

   项目标准升级流程中的 `php artisan tool:update` 也会执行这两类 Seeder，但同时会更新 EasyTool
   其他配置文档；应按发布流程选用其一，不要重复手工插入菜单或权限记录。
4. 把 `admin_monitor.app_stats` 和 `admin_monitor.app_stats.data` 同时授予需要查看页面的角色。
5. 执行 `php bin/laravels reload`，使常驻容器、路由和每个 Pod 的既有
   `swoole-stats-collector` 加载新逻辑。此功能没有 Horizon Job，Horizon 无需单独重启。
6. 等待至少两个采集周期，再按下一节完成单 Pod 或多 Pod 验收。

## 8. 运行态验收

### 8.1 单 Pod

1. 打开 `/admin/monitor/app_stats`，确认标题为“应用运行监控”，页面与数据接口均通过权限校验。
2. 等待十秒后确认 `HLEN`、`ZCARD` 约为 `1`，Pod 状态为 `online`，样本年龄持续更新。
3. 确认 Redis 内存区块能区分未启用、等待首次采样、正常/告警、读取失败和采样延迟。
4. 确认 PostgreSQL 区块显示连接容量与余量，且连续刷新页面不会在 PostgreSQL
   `pg_stat_activity` 中产生额外页面查询。
5. 确认 MQTT 与 SkyLab 区块存在；MQTT 失败不应导致 Pod、PostgreSQL 和 Redis 区块一起失败。
6. 页面关闭与打开各观察一分钟，确认打开页面没有触发 PostgreSQL 查询、Redis `INFO`、额外采样、Pod 清理、
   MQTT 重试或新增日志风暴。

### 8.2 多 Pod

1. 确认所有副本使用相同共享 Redis 端点/逻辑 DB，完成两个采集周期后 `HLEN`、`ZCARD` 大致
   等于活跃副本数。
2. 只打开一个 AppStats 页面，确认所有 Pod 同时出现，而不是随负载均衡刷新交替出现。
3. 做一次滚动 reload：正常退出且成功执行 stop hook 的旧 Pod 可以立即消失；异常退出或未执行
   stop hook 的旧样本应依次进入 `delayed`、`expired`。新 Pod 应显示新的 `instance_id`，任何
   停止更新的旧样本都不得继续显示为 live。hostname 被复用时，新快照覆盖同一 field，不要求
   新旧实例同时出现在页面。
4. 确认仍只有每 Pod 一个既有 `swoole-stats-collector`，没有新增 AppStats 进程、Task Worker、
   Horizon Worker 或 Scheduler 项。

### 8.3 旧接口兼容

以下旧入口必须继续可访问：

```text
GET /swoole_stats
GET /admin/swoole_stats
GET /admin/monitor/swoole_stats
```

- `/admin/monitor/swoole_stats` 的普通管理端 HTML 请求应跳转到 `/admin/monitor/app_stats`；
- 旧 `?json=1` 响应字段和 `?grafana=1` 指标名称保持兼容；
- 旧书签中的 `start_collect`、`stop_collect`、`duration` 不再写 Redis；
- 旧公开兼容页面不展示新增 Redis 内存详情，不扩大原有访问范围。

## 9. 常见故障定位

### 页面 403 或能打开但不刷新

- 页面 403：检查 `admin_monitor.app_stats` 权限。
- 页面能打开但数据请求 403：检查 `admin_monitor.app_stats.data` 权限。
- 菜单不存在：重新执行 EasyTool 菜单 Seeder；不要手工创建重复菜单。

### 页面没有 Pod 或 Pod 数持续不匹配

- 确认 LaravelS 已 reload，并检查 `swoole-stats-collector` 是否存在且每 Pod 只有一个。
- 确认 `app_stats.redis_connection=null` 最终解析到共享 `default` Redis，而非 local Redis。
- 对比固定 Hash/ZSet 的 `HLEN`、`ZCARD` 和 ZSet score；不要搜索整个 Redis。
- 检查 Laravel 日志中的固定错误类别。页面不会显示 Redis 地址、凭证或底层异常详情。

### Redis 内存区块一直等待或延迟

- 检查 `REDIS_MEMORY_MONITOR_ENABLED`、配置缓存和 `redis-monitor` profile。
- 确认 Scheduler 每分钟执行，且多 Pod 的 `onOneServer()` 锁使用同一共享 Redis。
- 使用实际配置的精确 `state_key` 执行一次 `GET`。Key 缺失可能是 `allkeys-lru` 淘汰，不代表
  内存正常；等待下一调度周期确认是否重建。

### PostgreSQL 区块一直等待、延迟或报错

- 检查 `POSTGRES_MONITOR_ENABLED`、`POSTGRES_MONITOR_CONNECTION`、配置缓存和
  `redis-monitor` profile，确认目标 Laravel 连接的 driver 为 `pgsql`。
- 用 `php artisan schedule:list` 确认 `tool:postgres_database_monitor` 已按一分钟注册；多 Pod
  的 `onOneServer()` 锁必须使用同一共享 Redis。
- 用 `php artisan tool:postgres_database_monitor --json` 手动执行一次低开销采样。该命令不会
  扫描 `locators` 业务行，但会刷新一个 PostgreSQL 状态 Redis Key。
- `sample_duration_ms` 持续偏高时先检查锁等待、长事务和 PostgreSQL 主机状态；不要通过改成页面
  直连数据库或缩短采样周期来掩盖延迟。

### MQTT 区块报错或 Pending 增长

- 确认 `mqtt-publish` 指向共享 `REDIS_*`，不是 `LOCAL_REDIS_*`。
- 检查 `mqtt-publish` 自定义进程、消费组和 Laravel 固定错误日志。
- Pending 增长表示生产速度或发布失败超过消费能力，应按 MQTT 发布进程既有运维手册排查 Broker
  和消费者；AppStats 页面不会替代、重放或清理这些消息。
- `mqtt_publish_dead=0` 只表示预留 Stream 没有条目，不表示没有发生过最终失败；最终失败以
  Laravel 错误日志为准。

## 10. 回滚

1. 回滚 EasyTool、EasyLocation 至审核过的包提交，同时回滚对应宿主发布版本，并按现有构建流程
   重新安装依赖；不要把本地生成且被忽略的 `composer.lock` 强制加入宿主 Git。
2. 恢复旧菜单/权限 Seeder 后按旧发布流程执行，或将菜单重新指向兼容路由
   `admin_monitor.swoole_stats`。
3. reload LaravelS；仍无需因为本功能单独重启 Horizon。
4. `swoole_stats:pods` 是旧版本也使用的兼容 Hash，不要删除。PostgreSQL/Redis 监控状态都是
   可过期的观测快照，MQTT Stream、消费组和业务数据一律不清理。

回滚后 `swoole_stats:pods:last_seen` 对旧代码无影响，可以保留。只有确认已经完全回滚、确认没有
新采集进程仍在写入、并由值班人员人工复核目标环境和 Redis DB 后，才允许删除这个精确 ZSet：

```bash
redis-cli DEL swoole_stats:pods:last_seen
```

执行前必须再次人工确认 Key 名；不要把命令扩展到 `swoole_stats:pods`、Redis 内存状态 Key、
`mqtt_publish_stream`、`mqtt_publish_dead` 或任何其他业务 Stream，也不要使用通配符、`KEYS`、
`SCAN`、`FLUSHDB` 做“顺便清理”。
