# 自有接口日志实施维护说明

主要读者：负责系统部署、日志存储和日常维护的实施人员。甲方管理人员的页面操作见[自有接口日志使用说明](owner-api-log-operations.md)。

## 1. 启用前检查

- 应用已发布接口日志采集、配置页、查看页和清理命令。
- 目标接口挂载了 `owner_api` 中间件；仅配置白名单不会为未接入该中间件的接口增加采集能力。
- 已执行目标应用的迁移和 `tool:update`，管理人员已获配置、列表及详情权限。标准 LaravelS 启动脚本会执行这些步骤，发布后仍需核对结果。
- 异步日志由默认队列处理，Horizon 须正常消费；每日清理由调度器触发。

入站日志保存在独立的 `owner_api_logs` 表。“自己上报的日志”使用 `logs` 表，“第三方接口通讯日志”使用出站日志，均不在本功能的清理范围内。

## 2. 部署默认配置

配置入口为宿主 `config/owner_api_log.php`。以下是只采集定位器列表、详情路径并保存到本地数据库的示例：

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

`MALLTO_LOG` 是共享日志后端设置，切换前应核对其他日志的存储要求。使用 `LoggerAliyun` 时，新日志写入阿里云 SLS，不会同时写入本地数据库；管理端查看页只读取本地数据库，历史记录仍可见。`ALI_LOG` 不控制数据库后端，数据库写入由 `DB_LOG` 控制。

| 设置 | 默认值及维护说明 |
| --- | --- |
| `OWNER_API_LOG` | `false`，采集总开关 |
| `OWNER_API_LOG_FILTER_MODE` | `whitelist`，默认白名单 |
| `OWNER_API_LOG_INCLUDE` | 空；白名单为空时不采集，多条路径以逗号或分号分隔 |
| `OWNER_API_LOG_EXCLUDE` | 仅兼容黑名单模式；未设置时沿用原排除项，显式空字符串表示空黑名单 |
| `LOG_DISPATCH_NOW` | `false`，默认异步处理日志 |
| `OWNER_API_LOG_RETENTION_DAYS` | `7`，支持 1–36500 的整数天数 |
| `OWNER_API_LOG_CLEANUP_ENABLED` | `true`，是否启用每日自动清理 |
| `OWNER_API_LOG_CLEANUP_AT` | `04:20`，按应用时区执行，格式为 `HH:MM` |
| `OWNER_API_LOG_CLEANUP_BATCH_SIZE` | `500`，每批支持 1–5000 条 |

管理页的显式开关、白名单及保留天数优先于部署默认值；选择“继承部署配置”才撤销对应覆盖。使用本页白名单后始终按白名单处理，空规则表示不采集。部署配置中的兼容黑名单仅在继承时生效；两种模式不叠加，模式无效时不采集。

`owner_api_log.hard_exclude` 是优先执行的固定排除清单，覆盖部分高频定位和历史轨迹接口。它不能被管理页、通配符或兼容黑名单绕过。旧配置 `close_owner_api_log_except` 只可绕过普通黑名单，不绕过白名单和固定排除。

## 3. 发布与生效检查

1. 按目标环境既有流程发布应用，完成 `php artisan migrate --force` 和 `php artisan tool:update`；采集前必须确保表已就绪。
2. 刷新部署配置和运行快照，分别重启 LaravelS 与 Horizon。只改环境文件或只重启 Web 不足以完成后端切换。
3. 登录管理端检查“配置中心 → 入站接口日志”和“日志中心 → 入站接口日志”；给需要使用的角色分配配置、查看及详情权限。
4. 确认 Horizon 正常消费默认队列，Web 与 Horizon 的日志后端设置一致。
5. 执行 `php artisan schedule:list`，确认 `tool:prune_owner_api_logs` 已注册在每日指定时间。
6. 确认调度实际运行：默认由 LaravelS 的 `LaravelScheduleJob` 每分钟触发，要求 `LARAVELS_TIMER=true` 且定时器进程正常。关闭该定时器的部署应通过既有运维系统每分钟执行 `php artisan schedule:run`。仅看到注册任务不能证明已经执行。

管理页保存开关、白名单、保留天数后通过运行缓存生效，无需逐次重启；修改部署环境配置或固定排除清单则需刷新配置并重启对应进程。多实例应使用支持共享锁的缓存，推荐 Redis，以避免清理任务重复或重叠执行。

## 4. 清理维护

每日清理以执行时刻减去保留天数为截止时间，只删除记录时间早于截止时间的入站日志。默认 7 天且调度正常时，实际保留约 7 至不足 8 天；任务延迟会延长保留时间。关闭采集或数据库写入后，历史日志仍按原计划清理。

在目标应用运行以下命令，先预览过期数量和截止时间，不删除数据：

```bash
php artisan tool:prune_owner_api_logs --dry-run
```

确认应用、数据库及保留天数后，必要时手动清理：

```bash
php artisan tool:prune_owner_api_logs
```

命令按批次清理，只操作 `owner_api_logs`。非法保留天数或批次配置会返回非零退出码，不执行删除。`OWNER_API_LOG_CLEANUP_ENABLED=false` 只暂停自动调度，不能阻止显式执行清理命令。

## 5. 验证与排障

通过实际认证方式调用一个已启用的白名单接口，正常返回时记录响应头 `request-id`。稍等日志处理完成后，在管理端按请求 ID 查询，核对请求和响应内容；再调用一个未命中白名单的接口，确认未生成对应记录。

| 问题 | 检查方向 |
| --- | --- |
| 配置正确但没有新记录 | 接口中间件、有效开关和规则、固定排除、日志后端、`DB_LOG` |
| 保存后采集范围没有变化 | 页面是否仍选择继承、逐条开关、运行缓存刷新是否正常 |
| 有请求 ID，但暂时没有数据库记录 | 是否使用 SLS、Horizon 默认队列是否积压或失败 |
| 只有请求记录 | 后续处理可能抛出异常；当前中间件只采集正常返回的响应 |
| 日志没有按期删除 | 清理开关、时区、调度触发、共享缓存锁及命令执行结果 |
| 开启后存储或队列压力增加 | 缩小白名单；正常每次调用通常产生请求、响应两条记录 |

常见凭据字段会在采集时脱敏，文本过长会带 `[truncated]` 标记；业务正文仍按日志查看权限控制访问。日志用于排障，不作为完整审计的保证。

返回 [easy-tool 文档索引](README.md)。
