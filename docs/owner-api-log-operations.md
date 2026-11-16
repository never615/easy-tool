# 内网部署：入站接口日志配置与自动清理

主要读者：本地及内网部署的运维实施人员。本文说明本系统 API 入站请求的日志采集、数据库查看、每日清理与验收；采集范围由中间件及路径白名单决定，不依据调用方身份自动判断“第三方”。

## 1. 前置条件与数据范围

- 使用包含本功能的 `easy-tool` 包版本；应用宿主的现有 `config/app.php` 提供日志总开关和后端选择。
- PostgreSQL 已应用 `2026_11_16_000001_create_owner_api_logs_table` 迁移。
- 异步日志由现有 `LogJob` 投递至默认队列，Horizon 必须消费该队列。
- 接口必须挂载 `owner_api` 中间件。定位器第三方入口为 `/api/tp/locator`；规则应按部署实际完整路径填写，而不是只写 `/locator`。

本功能写入独立的 `owner_api_logs` 表，记录入站请求。`third_api_logs` 是本应用向外部系统发起调用的出站日志，不在本次自动清理范围。阿里云日志存储的保留时间由阿里云单独配置，本清理命令只处理本地数据库表。

`/admin/owner_logs` 读取的是客户端主动上报的 `logs` 表，与入站请求日志独立，继续保留。

正常调用记录“请求”和“响应”各一条，通过同一个 `request_id` 关联。已有日志采集总开关默认关闭；开启白名单后，每次匹配的正常调用通常增加两个队列任务和两条数据库记录。

## 2. 管理页面：总开关与逐条白名单

管理员打开 `/admin/configs/owner-api-log`（部署修改管理端前缀时相应替换 `/admin`），也可从配置中心的“入站接口日志”模块进入。沿用现有管理端登录与接口权限；新增页面应按既有权限同步流程分配给需要维护日志的管理员。

同一页面提供：

- **是否启用接口日志**：继承部署配置、开启、关闭。与统一配置中心的 `owner_api_log.enabled` 共用同一个值，不需要切换到其他页面。
- **白名单规则**：选择“使用本页白名单”，填写 `api/tp/locator`、`api/tp/locator/*`，每条都有独立“启用规则”开关。停用某条保留其路径；删除行则移除规则。没有已启用规则时不采集。
- **日志保留天数**：继承部署默认，或在本页覆盖，默认 7 天。

该页面只管理白名单。选择继承时保留现有 config/.env 过滤语义；一旦使用本页白名单，就只采集已启用规则命中的路径。显式关闭总开关、显式空白名单、逐条关闭均不会被部署默认值覆盖；只有选择“继承部署配置”才撤销数据库覆盖。

### 高频接口先行排除与缓存

中间件先用 `owner_api_log.hard_exclude` 的本地配置匹配路径，命中便立即放行：不读取日志规则缓存、不查询配置数据库、不投递日志任务。清单包含原 `app.log.except` 的接口，并补充实时定位与历史轨迹查询（含 `trajectory-segments`）的实际路径；完整清单在本页只读展示。页面规则即使设置 `*`，也不能覆盖这层排除。

其余请求才批量读取日志总开关和白名单，复用配置中心现有的 Swoole Table L1 与共享 Redis 缓存。L1 可用且命中时没有 Redis 往返；未启用 Swoole Table、规则超出 L1 容量或缓存失效时会读取 Redis，缓存缺失时由现有回填机制访问 PostgreSQL。保存后沿用配置中心的提交后缓存更新与失效广播，不增加新的逐请求 SQL 或每个 Worker 自建永久缓存。

静态排除清单只能在 `config/owner_api_log.php` 修改，变更后刷新配置并重启进程；管理页面无法临时开启这些高频路径的日志。页面开关、白名单与保留天数则通过运行缓存生效，不要求逐次重启。

### 数据库日志查看

通过“日志中心 → 入站接口日志”或 `/admin/owner_api_logs` 查看（管理端前缀可变）。页面只读，沿用管理端登录与路由权限；执行 `php artisan tool:update` 同步菜单和权限后，按需给角色分配“入站接口日志查看”和“入站接口日志详情”。

- 列表支持请求 ID、项目 UUID 精确筛选，URL / 路径字面量包含筛选，以及 HTTP 方法、请求/响应类型、响应状态码和记录时间范围。
- 每页固定 20 条，按记录时间及 ID 倒序，只显示摘要；使用上一页/下一页翻页，不执行全表总数统计。大量记录时优先用请求 ID 或时间范围缩小查询。
- 点击“详情”同时展示同一请求 ID、同一项目 UUID 的请求头和请求/响应正文，最多 20 条；JSON 自动缩进，截断或非 JSON 内容按文本显示。关联记录按落库 ID 排序，异步写入时响应可能先于请求落库。
- 状态码属于响应记录，按状态码筛选不会直接返回请求行，进入详情仍可看到关联请求。仅有请求记录时，响应可能尚未写入，或请求处理抛出异常。
- owner 账号可查看全部记录；普通管理员仅可查看账号所属主体、子主体及额外管理主体范围对应 UUID 的日志。直接访问详情也受相同限制；空 UUID / `0` 的记录仅 owner 可见。

**该页面只查 PostgreSQL 的 `owner_api_logs`，不读取 SLS。** 启用 `LoggerAliyun` 后端时，新日志在 SLS 中查看，不会同时写入此表；数据库写入要求 `MALLTO_LOG='Mallto\Tool\Domain\Log\LoggerDb'` 且 `DB_LOG=true`，并重启 Web / Horizon 使启动配置生效。页面中的后端提示反映当前 Web 配置，Horizon 需单独核验。历史数据库记录不会因为当前后端切换而隐藏。

## 3. 内网部署默认配置：只采集指定接口

配置统一通过 `config('owner_api_log.*')` 读取。宿主配置入口为 `config/owner_api_log.php`，包自带默认配置；运行期不直接读取环境变量。可以在配置文件中直接设置：

```php
'filter_mode' => 'whitelist',
'include' => ['api/tp/locator', 'api/tp/locator/*'],
'retention_days' => 7,
```

也可以保留配置文件中的 `env()` 映射，在部署使用的环境配置中设置如下。直接改成固定 PHP 值后，对应环境变量不再覆盖该值：

```dotenv
OWNER_API_LOG=true
DB_LOG=true
MALLTO_LOG='Mallto\Tool\Domain\Log\LoggerDb'
LOG_DISPATCH_NOW=false

OWNER_API_LOG_FILTER_MODE=whitelist
OWNER_API_LOG_INCLUDE='api/tp/locator;api/tp/locator/*'

OWNER_API_LOG_RETENTION_DAYS=7
OWNER_API_LOG_CLEANUP_ENABLED=true
OWNER_API_LOG_CLEANUP_AT=04:20
OWNER_API_LOG_CLEANUP_BATCH_SIZE=500
```

这组规则采集第三方定位器列表及详情路径上的所有 HTTP 方法，包括 GET 查询及 DELETE 删除。例如：

```text
/api/tp/locator?third_slug=45C671000141&with_area=1
/api/tp/locator/123
```

如果实际入口是其他前缀，替换规则中的 `api/tp`。该示例标识仅用于说明，不需要加入配置规则。`MALLTO_LOG` 是现有共享 Logger 后端选项，修改前需确认本部署的出站及管理操作日志也应使用数据库后端；`ALI_LOG` 不控制 `LoggerDb`，数据库写入由 `DB_LOG` 控制。

### 过滤规则

| 配置项 | 默认值 | 含义 |
| --- | --- | --- |
| `OWNER_API_LOG` | `false` | 入站日志采集总开关 |
| `OWNER_API_LOG_FILTER_MODE` | `whitelist` | 默认白名单；可显式选择兼容的 `blacklist` |
| `OWNER_API_LOG_INCLUDE` | 空 | 白名单，只有命中规则才采集 |
| `OWNER_API_LOG_EXCLUDE` | 未设置 | 黑名单；未设置时沿用 `app.log.except`，显式空字符串表示空黑名单 |
| `LOG_DISPATCH_NOW` | `false` | 默认异步投递日志，避免请求内直接写库 |

- 多条规则使用逗号 `,` 或分号 `;` 分隔。
- 仅支持完整路径和通配符，例如 `api/tp/locator`、`api/tp/locator/*`；不区分 HTTP 方法，路径匹配区分大小写。不使用 `GET:` 等方法前缀。
- 路径从应用入口开始，不包含域名和查询参数；开头、结尾的 `/` 可省略，`*` 表示通配符。
- `api/tp/locator` 只匹配列表，详情需要另外配置 `api/tp/locator/*`。
- 白名单模式只检查 INCLUDE，黑名单模式只检查 EXCLUDE。白名单为空时不记录任何接口；模式拼写无效时也不采集。
- 默认使用白名单；未配置部署规则、管理端也没有覆盖规则时，即使打开日志总开关，也不会采集任何接口。
- 高频固定排除 `owner_api_log.hard_exclude` 优先于两种模式，不能被 INCLUDE、空 EXCLUDE 或管理页规则覆盖。
- 旧全局配置 `close_owner_api_log_except=1` 只会绕过普通黑名单，不能绕过白名单或高频固定排除。内网白名单部署无需开启这个旧选项。

要继续使用黑名单，可以设置：

```dotenv
OWNER_API_LOG_FILTER_MODE=blacklist
OWNER_API_LOG_EXCLUDE='api/gateway/data;api/tp/lbs/history_locator'
```

不要同时把白名单和黑名单理解为叠加过滤。更改采集模式后，用本节的规则语义重新核对实际采集范围。

## 4. 每日清理与保留周期

| 配置项 | 默认值 | 可接受值 |
| --- | --- | --- |
| `OWNER_API_LOG_RETENTION_DAYS` | `7` | `1`–`36500` 的整数天数 |
| `OWNER_API_LOG_CLEANUP_ENABLED` | `true` | 是否注册每日自动清理 |
| `OWNER_API_LOG_CLEANUP_AT` | `04:20` | `HH:MM`，按应用 `app.timezone` 执行 |
| `OWNER_API_LOG_CLEANUP_BATCH_SIZE` | `500` | 每批删除 `1`–`5000` 行 |

每日任务以执行时刻减去保留天数作为固定截止时间，只删除 `created_at` 严格早于截止时间的记录；恰好等于截止时间的记录保留。时间从日志落库时计算。每日检查意味着记录通常保留 7 至不足 8 天，调度停机或执行延迟会延长实际保留时间。

清理使用 `(created_at, id)` 索引，分批选择主键并逐批删除，每批后暂停 50 毫秒，不开启覆盖全部删除的大事务。调度通过 `onOneServer` 和 `withoutOverlapping` 避免多实例重复执行；实例应使用同一支持锁的共享缓存，推荐 Redis。

**关闭 `OWNER_API_LOG` 或 `DB_LOG` 后，默认仍每天清理历史入站日志。** 需要暂停自动删除时单独设置 `OWNER_API_LOG_CLEANUP_ENABLED=false`；该开关仅控制调度，显式执行清理命令仍会删除。

可先预览过期数量：

```bash
php artisan tool:prune_owner_api_logs --dry-run
```

输出 `Expired` 和截止时间，不删除数据。确认目标应用、数据库和保留天数正确后，手动运行：

```bash
php artisan tool:prune_owner_api_logs
```

输出 `Deleted` 和截止时间。非法保留天数或批次配置会返回非零退出码，不执行删除。自动清理与手工清理均只处理 `owner_api_logs`。

## 5. 部署与生效

1. 发布包含新迁移、管理页面、过滤、日志写入与清理命令的 `easy-tool` 版本，并同步宿主 `config/owner_api_log.php`；源码固化镜像需要重新构建应用镜像。
2. 配置上述环境变量，确保 LaravelS、Horizon 和 Scheduler 使用相同环境文件与时区。
3. 在目标部署应用中执行 `php artisan migrate --force`。迁移必须先于新日志采集生效。
4. 按既有部署流程运行 `php artisan tool:update` 同步配置元数据和管理端权限；由现有部署启动脚本刷新配置缓存和配置中心运行时快照，分别重启 LaravelS 和 Horizon。不要只修改 `.env` 或只重启 Web 进程。
5. 用 `php artisan schedule:list` 确认 `tool:prune_owner_api_logs` 每天在指定时间执行。
6. 检查 Scheduler 实际被触发。本项目默认使用 LaravelS 的 `LaravelScheduleJob` 每分钟触发调度，要求 `LARAVELS_TIMER=true` 且定时器进程正常。若部署关闭了该定时器，应由现有运维系统每分钟运行 `php artisan schedule:run`；`schedule:list` 只验证注册，不证明任务已经执行。

macOS 原生开发环境继续使用 `bash local_start.sh` 及其已有参数管理启动，不需要手工替换运行方式。Horizon 的具体消费队列以部署的 `config/horizon.php` 为准；异步日志若积压，不要通过改为同步写入来掩盖队列故障。

## 6. 验收与问题定位

通过现有第三方签名方式请求白名单中的一个接口，记录响应头 `request-id`，等待默认队列处理完成，在数据库核对：

```sql
SELECT id, request_id, action, method, status, url, input, created_at
FROM owner_api_logs
WHERE request_id = '<本次响应头中的 request-id>'
ORDER BY id;
```

正常应看到请求、响应各一条。诊断定位器偶发空列表时，响应的 `input` 可区分 HTTP 状态、分页 `total`、`current_page` 与 `data`；同时结合请求中的 UUID 和 `third_slug`。

再用同一认证方式请求一个非白名单接口，确认没有新增对应入站日志。通过管理页面修改保留天数后，用 dry-run 检查截止时间（修改 config/.env 则先刷新配置并重启相关进程）；自动任务执行后，再核对过期数量及调度输出。

| 现象 | 检查项 |
| --- | --- |
| 开关已开但没有日志 | `OWNER_API_LOG`、`DB_LOG`、`MALLTO_LOG`；是否应用新迁移；是否确实挂载 `owner_api` |
| 只有部分接口有日志 | 固定排除清单、规则来源、逐条启用状态、完整路径；列表和详情规则是否分别配置 |
| 有 request-id 但数据库暂时没有记录 | Horizon 是否消费默认队列；LogJob 是否积压或进入 failed_jobs |
| 日志没有被自动删除 | `cleanup_enabled`、时区、Scheduler 定时器/外部触发、共享缓存锁、命令输出；清理不是由 Horizon 消费 |
| 开启后数据库写入明显增加 | 缩小白名单，避免采集高频定位上报与大体积查询；默认每次正常调用写两行 |

请求参数、URL 查询参数、请求头和 JSON 响应中的常见凭据字段会在入队前脱敏，包括 Authorization、Cookie、密码、token、签名和 secret。URL、输入及请求头文本分别限制为 64 KiB，超出后带 `[truncated]` 标记，截断内容不保证可继续当作完整 JSON 解析。业务正文仍可能包含需要受控访问的信息。

当前中间件只在后续处理正常返回 Response 时采集响应；异常直接抛出、最终交给外层异常处理器的请求可能只有请求日志。`request-id` 响应头也只在该中间件采集并正常返回时设置。日志是排障记录，不承诺事务级完整审计。

返回 [easy-tool 文档索引](README.md)。
