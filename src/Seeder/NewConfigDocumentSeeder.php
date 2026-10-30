<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\NewConfigDocument;

/**
 * NewConfigDocumentSeeder：初始化 new config document seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class NewConfigDocumentSeeder extends Seeder
{
    /**
     * 处理「run」相关的内部协作步骤。
     */
    public function run(): void
    {
        if (!Schema::hasTable('new_config_documents')) {
            return;
        }

        NewConfigDocument::query()->updateOrCreate([
            'slug' => NewConfigDocument::SLUG_USAGE,
        ], [
            'title' => '配置使用说明',
            'content_type' => 'markdown',
            'content' => $this->configurationUsageMarkdown(),
            'remark' => '配置中心后台使用说明，由 tool:update 写入。',
            'sort' => 1,
            'is_enabled' => true,
        ]);
    }

    /**
     * 处理「configurationUsageMarkdown」相关的内部协作步骤。
     */
    private function configurationUsageMarkdown(): string
    {
        return <<<'MARKDOWN'
# 配置使用说明

初版日期：2026-08-11；统一入口更新：2026-10-30。

定位开关与缓存分层补充核验：2026-10-19（不代表目标环境实际值已核验）。

本文是所有配置的统一查阅入口，说明配置文件职责、专题说明、修改入口及生效方式。
主要供部署运维人员、配置中心管理员及维护配置的开发人员查阅。
原路径为 `docs/configuration-usage.md`，现与配置源码一起放在 `config/`。
各项完整键名、环境变量、默认值和取值校验以对应 PHP 文件及业务 Definition 为准；本文集中维护操作说明，专题文档维护业务细节。

新项目首次部署或调整队列保护时请先阅读：[队列保护与运行配置](../docs/queue-protection-and-runtime-configuration.md)。

定位功能、心跳/样本停写与降载，统一见[定位项目开关与降载操作说明](../packages/mallto/easy-location/docs/runbooks/location-feature-switches.md)。
该手册集中维护项目开关默认值、完整/轻量模式、优先级、动态生效及已有积压的处理边界。

## 配置文件索引

表内路径均相对于本目录。文件存在不代表所属功能或服务提供者已经启用，实际启用情况还需检查
`app.php`、启动脚本和目标环境。不要把连接凭据或目标环境的真实值写进本文。

### 定位、业务及外部设备

| 配置文件 | 具体职责与查阅入口 |
| --- | --- |
| [location.php](location.php) | 定位业务、算法、历史、报警、设备状态、通知、队列保护及后置业务异步开关；见下文及[定位开关手册](../packages/mallto/easy-location/docs/runbooks/location-feature-switches.md)。 |
| [lance_aoa.php](lance_aoa.php) | Lance AOA 接入、latest 采样、地图身份快照及 Task 通道等启动参数；参数有显式校验，修改后须重新加载进程。 |
| [lierda.php](lierda.php) | 利尔达签名请求的时间容差、共享缓存重放保护。 |
| [hytera_dmr_mock.php](hytera_dmr_mock.php) | Hytera DMR 模拟服务配置，用于测试联调；不代表真实设备连接配置。 |
| [subject-config.php](subject-config.php) | 项目配置的宿主兼容入口；项目动态配置的完整定义还在各业务包中，读取与生效方式见下文。 |
| [other.php](other.php) | 接口签名、主体过滤、短信系统、模型绑定、CDN 和 MQTT 等通用业务基线。 |
| [mall.php](mall.php) | 商城相关兼容配置；应先核对对应包及 Provider 是否启用。 |
| [helps.php](helps.php) | 管理端辅助生成器使用的包路径、实体与控制器命名空间映射。 |
| [magellan.php](magellan.php) | PostGIS schema、坐标类型/SRID、空间字段转换及序列化。 |

定位专题还包括：[算法参数与优先级](../packages/mallto/easy-location/docs/reference/positioning-algorithm-parameters.md)、
[固定坐标配置](../packages/mallto/easy-location/docs/reference/locator-fixed-position-configuration.md)、
[被动定位启动参数](../packages/mallto/easy-location/docs/reference/passive-gateway-aggregation-configuration.md)。

### 运行进程、存储及通信

| 配置文件 | 具体职责与查阅入口 |
| --- | --- |
| [laravels.php](laravels.php) | LaravelS/Swoole Worker、Task、Socket、自定义进程与 Timer 的启动配置；与启动脚本一起核对。 |
| [swoole_task_tmp_cleaner.php](swoole_task_tmp_cleaner.php) | Task 临时文件自动清理、过期时间、采样/盘点周期、单轮删除上限及手动请求租约。 |
| [queue.php](queue.php) | 队列连接、重试可见性及失败任务存储；见[队列保护与运行配置](../docs/queue-protection-and-runtime-configuration.md)。 |
| [horizon.php](horizon.php) | Horizon 各环境 Supervisor、消费队列、并发与超时；仅投递 Job 不代表已有 Worker 消费对应队列。 |
| [database.php](database.php) | PostgreSQL、MongoDB、Redis 等连接和连接池基线；属于启动依赖。 |
| [cache.php](cache.php) | 缓存驱动、存储连接和前缀；业务运行配置缓存机制见下文。 |
| [filesystems.php](filesystems.php) | 本地与云存储磁盘、访问路径。 |
| [elasticsearch.php](elasticsearch.php) | Elasticsearch 连接与客户端配置；是否实际使用取决于相关业务是否启用。 |
| [mqtt-client.php](mqtt-client.php) | MQTT 客户端连接、订阅/发布与可靠发布流程的配置。 |
| [mqtt-web.php](mqtt-web.php) | Web MQTT 会话启用、端点、JWT 签发身份/私钥路径、有效期与会话限制。 |
| [broadcasting.php](broadcasting.php) | 广播连接与驱动。 |
| [mail.php](mail.php) | 邮件传输、发件人和各邮件连接。 |
| [services.php](services.php) | 外部服务的凭据配置入口。 |
| [wechat.php](wechat.php) | 微信相关应用和 SDK 连接配置。 |
| [wechat-template.php](wechat-template.php) | 微信模板消息配置。 |

### 应用、认证、管理端及开发工具

| 配置文件 | 具体职责 |
| --- | --- |
| [app.php](app.php) | 应用环境、时区、语言、加密密钥、Provider 与别名。 |
| [auth.php](auth.php) | 认证 Guard、用户 Provider 与密码重置。 |
| [sanctum.php](sanctum.php) | Sanctum 有状态域名、认证 Guard、Token 与中间件。 |
| [hashing.php](hashing.php) | 密码哈希算法与成本参数。 |
| [session.php](session.php) | 会话驱动、生命周期、Cookie 与存储。 |
| [cors.php](cors.php) | 跨域路径、来源、方法、请求头和凭据。 |
| [admin.php](admin.php) | Laravel Admin 路径、认证、数据库表、界面与扩展。 |
| [captcha.php](captcha.php) | 图形验证码的样式与生成参数。 |
| [ueditor.php](ueditor.php) | 编辑器上传、资源路径及格式限制。 |
| [image.php](image.php) | 图片处理驱动。 |
| [excel.php](excel.php) | Excel 导入导出、临时文件和缓存。 |
| [view.php](view.php) | 模板路径与编译缓存目录。 |
| [logging.php](logging.php) | 日志 Channel、级别、输出与保留策略。 |
| [logviewer.php](logviewer.php) | 日志查看器的访问及展示配置。 |
| [debugbar.php](debugbar.php) | 调试条启用、采集与存储。 |
| [telescope.php](telescope.php) | Telescope 监控入口、Watcher 与存储。 |
| [tinker.php](tinker.php) | 交互命令行允许的命令和别名。 |
| [ide-helper.php](ide-helper.php) | IDE 辅助代码生成，通常不参与线上业务。 |

## 定位开关快速入口

| 目标 | 配置中心项目键 | 重要边界 |
| --- | --- | --- |
| 只停定位器扫描到的基站心跳 | `upload_locator_station_update=0` | 默认 `false`；不影响 `locators.is_debug=true` 时写入 PostgreSQL `upload_beacons`、`cache_beacons` 调试数据。 |
| 按业务保留报警、历史、定位器/电量、网关或基站更新 | `location_business_features` | 默认完整模式。要让分项 `false` 生效，先设置 `full_features_enabled=false`；轻量模式默认仍保存电量。 |
| 只停原始 `lbs_logs` | `lbs_logs_write_enabled=0` | 默认回退全局 `true`；不等于关闭全部历史。刷新时仍检查停写开关，暂存记录可能被确认丢弃。 |

`upload_locator_station_update` 使用 `swoole_redis`，`location_business_features` 和
`lbs_logs_write_enabled` 使用 `shared_redis`；经后台提交后按缓存失效机制动态生效，无需为切换重启服务。
首次部署新增代码仍需加载新版 LaravelS/Horizon。项目级 `cache_beacon` 虽然存在界面定义，
当前扫描存储链读取的是全局 `config('location.cache_beacon')`，不能用项目同名项控制全局采样。
旧 `upload_locator_station_store_enabled` 已退役，遗留记录不再影响心跳或调试准入。

### 定位器后置业务异步开关

| 配置键 | 环境变量 | 默认值 | 控制行为 |
| --- | --- | --- | --- |
| `location.locator_update.async_enabled` | `LOCATION_LOCATOR_UPDATE_ASYNC_ENABLED` | `true` | `LocatorUpdateUsecase` 将原有后置业务编排投递为 `LocatorUpdateJob`，显式使用 `redis` 连接、`high` 队列；`false` 恢复原来的同步编排。 |
| `location.locator_change_processing_enabled` | `LOCATION_LOCATOR_CHANGE_PROCESSING_ENABLED` | `true` | 控制定位器变化业务的处理准入；旧变量 `LOCATION_NOTIFY_ENABLE` 仅在新变量缺省时回退。与异步开关的执行位置选择分开控制。 |
| `location.battery_notifications.enabled` | `LOCATION_NOTIFICATION_DELIVERY_ENABLED` | `false` | 是否启用通知可靠投递的状态、intent/delivery 持久化与重试；旧变量 `LOCATION_BATTERY_NOTIFICATIONS_ENABLED` 仅在新变量缺省时回退。同时涉及电量和区域 HTTP 通知。 |

异步开关控制执行位置，`location_business_features` 控制业务准入。完整模式下仍会接受完整业务；
开启异步后由 Horizon 承担 `LocatorUpdateUsecase` 的编排及后续任务分发，实时位置写缓存和位置推送
仍由各自原有链路负责。切回同步路径只改变之后的新调用；已接受的 Job 继续消费，不随开关关闭而取消。
新 Job 的执行超时为 30 秒，仅尝试一次，不配置自动重试，避免重复触发已有副作用。

这不是把所有解析工作都移入 Horizon：当可靠通知开启时，AOA 广播内嵌 7 字节电量和普通 Lance
DIY MQTT/UDP 状态解析，都可能在进入 `LocatorUpdateUsecase` 前同步记录可靠电量事务，写入
`locator_battery_notification_states`、`locator_battery_notification_intents`、
`locator_battery_notification_deliveries`；实际通知发送另由队列负责。该段不受异步开关控制。

切换和发布步骤：

1. 首次部署先让所有 Horizon 加载包含 `LocatorUpdateJob` 的新代码，并确认其消费 `redis/high`，再启用新版生产者。
2. 在部署环境设置 `LOCATION_LOCATOR_UPDATE_ASYNC_ENABLED=true`（默认）或 `false`（同步回退），刷新配置缓存，并分别重启 LaravelS 与 Horizon；该键不是项目动态开关。
3. 若只回退执行方式，设为 `false` 后继续保留新代码，排查旧同步路径和 `high` 积压；入队失败会向上抛错，不自动转为同步重跑。
4. 若还要回滚到没有新 Job 类的旧代码，先关闭异步并让所有生产者生效，再排空已接受的新 Job，最后回滚代码，避免旧 Worker 无法反序列化新任务。

## 总体原则

全局业务配置以当前环境 `.env -> config/*.php` 为默认基线，只有管理员或系统明确启用的 `new_configs.global_config` 记录才覆盖该基线。项目动态配置仍以 PostgreSQL 为事实来源。两者都通过运行配置缓存生效；普通业务保存不生成业务 env/value/subject 快照，不刷新 Laravel config cache，也不要求发布或重启。

业务配置有两种模式：

| 模式 | 读取路径 | 保存后的行为 |
| --- | --- | --- |
| `swoole_redis` | LaravelS：专用 Swoole L1 → 共享 Redis L2 → DB；Horizon/Artisan：Redis L2 → DB | commit 后写穿 L2、删除本机 L1、递增 runtime revision 并广播无 value identity |
| `shared_redis` | 所有进程：共享 Redis L2 → DB，绝不访问 Swoole | commit 后写穿 L2、递增 runtime revision；无 L1 identity |

未知或历史业务 key 默认使用 `swoole_redis`。当 `SWOOLE_TABLE_ENABLE=false` 时，它自动回退到 Redis L2 → DB，不需要为了正确性开启 Swoole。

`storage/framework/new_configs_values.php` 和 `storage/framework/subject_configs_values.php` 只保留为空兼容文件，不是运行时真相。`ConfigUtils` 和 `SubjectUtils` 均从运行配置缓存读取。

## 日常修改入口

日常优先使用“配置中心”下的模块化表单：

- 项目动态配置
- 基础业务配置、短信与告警配置
- 定位算法、定位维护、BeaconArea、定位日志配置
- Swoole Task 配置、运行期 Env 覆盖
- 发布启动配置

新增业务 key 时，应先在所属包的 Global / Subject definitions 中登记，再执行 `php artisan tool:update` 补齐目录记录。`fromConfig()` 定义表示“环境 config 基线 + 可选数据库覆盖”，普通 `make()` 定义表示“数据库主配置 + 代码兜底值”。后台按定义来源展示对应控件。apply mode 由代码定义，管理员不能在 UI 中任意修改。

`/admin/configs`、`/admin/subject_configs` 和 `/admin/new_configs` 是查看原始记录或兼容历史 key 的排查入口，不是日常首选入口。旧 `configs` 表已退役；升级 seeder 只单向读取它以补齐缺失的 `new_configs`，不会覆盖新表已有值或把值写回旧表。

## Redis-only 项目配置

以下为常用的固定 `shared_redis` 项目 key，不会进入 Swoole；完整清单以各包当前 Subject definitions 为准：

```text
map_layer
sprite
default_base_bg_color
compartment_color
place_style_text_size
facilities_style_icon_size
bearing
default_positioning_args
active_positioning_args
passive_positioning_args
mobile_positioning_args
location_positioning_strategy
location_business_features
lbs_logs_write_enabled
second_order_enabled
lc_tag_status_log
lance_tag_status_api_url
fence_area_alarm_logs_max_count
fence_area_alarm_logs_max_months
work_place_no_head_name
wiki
cdn_backend_domain
statistics_pid
statistics_project
```

全局 `location_args` 也固定使用 `shared_redis`。四个 positioning JSON 只在重建项目定位参数派生缓存时读取，正常定位热路径继续使用派生缓存。

### 为什么这样选择

- `navigation_config`、`navigation_config_road1`、`navigation_project_distance`、`navigation_voice_copywriter_config` 长度可控，而且导航请求会直接读取，所以使用 `swoole_redis`，让 LaravelS 优先命中本机 L1。
- 四个 positioning JSON 和全局 `location_args` 是定位参数的源配置。正常定位热路径读取的是合并后的 `l_a_*` 派生 Swoole/Redis 缓存；只有派生缓存 miss 或配置变化后重建时才读取源 JSON。因此源配置继续使用 `shared_redis`，避免源值和派生结果重复占用 Swoole 内存。
- `map_layer`、`sprite` 等地图数据可能很大；低频 Redis-only 配置也无需占用容量有限的 runtime Swoole 表。Swoole payload 超限时虽会安全回退 Redis，但继续探测 L1 没有性能收益。
- 项目业务范围、定位结果策略等明确要求从共享缓存读取的策略也使用 `shared_redis`；这类开关的选择依据是动态策略一致性，不应归因为“大值或低频”。

这项分类依据“实际读取路径、频率和 payload 大小”，而不是只看字段类型是否为 JSON。业务访问模式改变时，应重新审查 definition mode。

## 保存业务配置后

使用 `fromConfig()` 登记的全局配置，保存与环境值不同的内容时自动产生 `manual` 覆盖；改回环境值并保存时自动清除覆盖并回到当前进程 config 基线，不需要额外勾选。普通 `make()` 全局配置没有环境 config 来源，页面显示普通“启用配置”，启用时数据库值就是主配置，停用或缺少记录时才使用代码兜底值。系统通过 `ConfigUtils::set()` 写入的配置标记为 `system`，`app_secret` 不允许在普通表单中查看或手工覆盖。

保存全局覆盖或项目业务配置后，Observer 在数据库 commit 后按当前 definition mode 写穿/失效运行缓存，并递增独立的 runtime revision。rollback 不产生缓存或广播副作用。

通常下一次读取即可取得新值，不需要进入“发布启动配置”。跨实例的 Pub/Sub 消息只携带 revision 和 identity hash，不携带 key/value；若消息丢失，revision watcher 会清理专用 Swoole L1 以补偿，但不会触发服务重启。

删除或移动项目 key 时会同时处理原 identity 与新 identity。定位参数、tx rssi 等源配置还会定向失效对应 `l_a_{subject_id}_*` 或 `r_m{subject_id}_*` 派生缓存。

## 长驻组件如何生效

Hytera 的 IP、端口、账号、密码、API 版本、service request 和 SSI identity 都是 `swoole_redis` 业务值。活动 ACAPI 会话比较七项配置的 SHA-256 指纹；变化时只关闭并立即重建 ACAPI 连接，不记录或广播密码/指纹，也不重启 LaravelS/Horizon。

`location.beacon_area_check_interval` 也是运行时业务值。BeaconArea Timer 固定每秒 tick，同一个 Cron 实例按最新 interval 控制是否派发；修改间隔会影响后续派发，无需重启。Timer 是否注册仍由启动环境控制。

仓库默认 `LARAVELS_JOBS` 未包含 `LocatorBeaconAreaCron`。只有现场环境显式加入该类完整 FQCN 时，这个 Timer 才会运行；修改 interval 不会自动注册任务。

## 启动配置与生成的 env

### 被动定位计算触发模式

RSSI 被动定位默认使用 `direct`。单项目部署在 `.env` 设置全局默认；多项目需要差异时，在对应
subject 的动态配置中设置 `passive_gateway_mode`。项目非空值优先，留空继承全局默认，不再维护
按 `subject_id` 索引的启动 `projects` 清单，也没有 `enabled` 总开关。

```dotenv
LOCATION_PASSIVE_GATEWAY_MODE=direct
```

| 模式 | 观测与计算行为 |
| --- | --- |
| `direct` | 默认模式。当前上报竞争原有 Redis throttle，Task 开始前读取当前 locator 的有效共享 station 快照；首个网关可先计算，其他网关等待下一次名额。 |
| `aggregated` | 供明确需要集中协调的高频项目使用。观测写入后登记 due，scheduler 领取并计算；没有额外收集窗口、计算租约、故障恢复或失败重试。 |
| `legacy` | 明确回退原有 locator 数组链路。只有选择此值才使用旧链路，非法项目模式不隐式回退。 |

`direct` 使用 `LOCATION_INTERVAL` 限制重复计算频率，不要求严格每秒计算。`aggregated` 的
`interval_ms` 也只限制相邻开始计算的最小间隔。共享观测、station 过期、CAS/receipt 和结果提交
仍使用 Redis；accepted 后处理只领取一次，失败或 owner 超时后记录失败，不做后台重试。
Timer 仍负责 accepted 派发，因此全为 `direct` 时也不能关闭 `LARAVELS_TIMER`。

`LOCATION_PASSIVE_GATEWAY_SETTINGS` 只保存共享启动参数；修改 `.env`/启动参数后须刷新配置
缓存并重启 LaravelS 和 Horizon。subject 模式是动态配置，但实际切换时仍应先停流排空旧任务。
术语、Redis 操作、切换和排查分别见以下两份正式文档；本页不重复维护参数清单：

- [被动定位架构与 Redis 流程](../packages/mallto/easy-location/docs/architecture/passive-gateway-redis-logic.md)
- [被动定位实施配置说明](../packages/mallto/easy-location/docs/reference/passive-gateway-aggregation-configuration.md)

只有 `runtime_env_override` 和 `swoole_task_monitor` 属于生成 env 的启动配置。它们发布到：

```text
storage/framework/new_configs.env
```

该文件供启动脚本 source 和 `config:cache` 子进程使用。配置中心不是 `.env` 编辑器：发布不会读取、展示或修改真实 `.env`。

启动配置需要计划内重启新进程才能完整生效。后台进入“配置中心 > 发布启动配置”，或使用：

```bash
php artisan tool:new_config_publish --force-config-cache
php artisan tool:new_config_publish --restart --force-config-cache
```

`NEW_CONFIG_RESTART_ENABLED` 默认开启，并可独立关闭。关闭后仍会发布 `new_configs.env`、刷新 config cache，业务缓存更新也完全不受影响；仅跳过 restart generation、LaravelS restart 和 Horizon terminate。页面或 CLI 的“已发布”不代表真实 `.env` 被修改，也不代表服务已经重启。

`LOCATION_QUEUE_LIMITER_ENABLED`、四个按组 `*_LIMITER_ENABLED` 开关、`LOCATION_PGSQL_WRITE_CONCURRENCY` 和 `LOCATION_RESULT_STORAGE_CONCURRENCY` 是定位队列限流的启动配置。业务任务只读取本地 `config()`，不会在热路径读取运行时配置中心、Redis 或 Swoole Table。按组开关默认开启，关闭只旁路对应 Redis funnel 并保留专用队列；需要先核验所有 worker 与数据库预算。升级时 `location:update` 会把三个历史全局显式覆盖转换为 `runtime_env_override`；按组开关由 `.env`/Secret/ConfigMap 提供，随后发布并重启 LaravelS 和 Horizon，所有新进程才会使用同一数值。

定位队列截止和 failed provider 的建议基线为：

```dotenv
QUEUE_FAILED_DRIVER=file
QUEUE_FAILED_FILE_PATH=
QUEUE_FAILED_FILE_LIMIT=100
LOCATION_PGSQL_WRITE_MAX_WAIT_SECONDS=60
LOCATION_RESULT_STORAGE_MAX_WAIT_SECONDS=60
```

`QUEUE_FAILED_DRIVER` 对宿主所有 Laravel Queue Job 全局生效，只接受 `file/null/database`，非法值按
`file` 处理。file 容量限制为 1–1000，默认 100；只有在路径确认使用 Pod-local storage 时，记录才是
每个 Horizon Pod 独立的短期证据。file 路径不得指向多 Pod 共享 PVC，避免跨 Pod 单文件竞争；要求
集中审计的 SSD 项目应显式使用 database，故障期可切 null。切换时不迁移旧 `failed_jobs` 或旧 file
记录，也不会自动删除数据库历史记录。

## revision 与 generation 不要混淆

- `runtime_config:revision`：业务配置每次 commit 后递增，用于跨实例缓存同步；不触发重启，不受 restart switch 影响。
- `new_configs:apply_generation`：仅用于启动配置的计划内重启；请求 restart 且总开关开启时才递增。

## `.env`、Compose 与 K8s 边界

以下启动依赖必须在配置中心可用之前存在，应继续由真实 `.env`、Docker Compose env 或 K8s Secret/ConfigMap 管理：

- `DB_*`、`DATABASE_URL`
- `REDIS_*`
- `MONGO_*` / `MONGODB_*`
- MQTT、Cache、Session、Mail 和云厂商连接凭据
- LaravelS/Horizon 与配置中心自身的启动依赖

后台会拒绝把这些 bootstrap key 作为运行期 Env 覆盖发布。

## 新增配置规范

开发维护时的读取、写穿、失效广播和跨实例同步机制见[配置运行机制说明](../docs/architecture/configuration-runtime.md)。

1. 运行时代码不得直接调用 `env()`；环境值进入 `config/*.php` 后用 `config()`。
2. 全局业务读取统一使用 `ConfigUtils`，项目业务读取统一使用 `SubjectUtils`。
3. 小型业务值默认 `swoole_redis`；固定大值/低频值明确登记为 `shared_redis`。
4. 不得把业务值重新加入 `new_configs_values.php`、`subject_configs_values.php` 或启动 env。
5. Definition Syncer 会创建配置目录，但新目录记录的 `value=null`、`override_source=null`、`is_enabled=false`；已有人工/系统覆盖的 value、启用状态和来源保持不变。name、remark、type、env key、default、options、sort 和 apply/reload metadata 会随代码定义同步。
6. 当调用方默认值来自 `config($configKey)` 时，Global Definition 必须通过 `fromConfig()` 读取同一 config key；当默认值是代码常量时，调用方与 Definition 必须共用 Defaults 常量；原本没有默认值时不得人为补默认值。
7. 临时 `sa_lo_st_*` 和历史未注册 key 仍按业务缓存兼容处理；同一项目临时基站开关最多 10 个。
8. 新增配置文件时更新本文索引；新增或修改业务开关时，在所属配置源码与专题文档中写明默认值、控制功能/写入表、生效方式和回退边界。后台“配置使用说明”的 Seeder 正文应与本文逐字节一致，由 `tool:update` 写入数据库。

## 常用排查

业务配置未生效：

1. 全局配置检查 DB 的 `override_source`、`is_enabled` 和 value；项目配置检查正确的 subject/scope/key/value。
2. 检查 definition mode；`shared_redis` 不应访问 Swoole。
3. 检查 Redis L2 写穿、runtime revision 和无 value 广播。
4. Swoole 开启时检查 subscriber / revision watcher；关闭时确认已回退 Redis/DB。
5. 定位参数或 tx rssi 还要检查派生缓存是否按带尾下划线的项目 prefix 清理。

启动配置未生效：检查 `new_configs.env`、config cache、restart switch、restart generation 和新进程是否实际拉起。不要检查旧业务 snapshot 文件，也不要把生成 env 与真实 `.env` 混为一谈。

## 上线检查

迁移上线前，逐项核对以下 18 个全局业务 key：

```text
location.upload_data_direct_parse_enabled
location.locator_negative_cache_ttl_seconds
location.trajectory_segments.enabled
location.trajectory_segments.history_points_read_enabled
location.trajectory_segments.history_query_source
location.trajectory_segments.history_cutover_at
location.trajectory_segments.legacy_lbs_log2s_write_enabled
location.trajectory_segments.near_realtime_enabled
location.trajectory_segments.near_realtime_throttle_seconds
location.trajectory_segments.near_realtime_window_minutes
location.trajectory_segments.chunk_minutes
location.trajectory_segments.max_points_per_chunk
location.trajectory_segments.max_gap_seconds
location.trajectory_segments.algorithm_version
location.trajectory_segments.road_match_enabled
location.trajectory_segments.road_network_cache_seconds
location.beacon_area_check_interval
location.skylab_location_perf_log
```

每个 LaravelS Pod、Horizon 当前加载的 `config(key)` 必须与 `fromConfig()` Definitions 展示的环境基线一致。对 config 基线型配置，只有 `override_source` 非空且 `is_enabled=true` 时才取数据库 value；对数据库主配置，`is_enabled=true` 即取数据库 value，不要求 override_source。历史 config 基线型记录来源不明确时先审计，只有确认需要继续覆盖才标记为 `reviewed`。

上线时先冻结后台业务配置写入，再完成对账并全量滚动 LaravelS、Horizon，确认所有实例已使用新代码和同一共享 Redis 后解除冻结。新旧版本混跑期间不得保存业务配置；滚动完成后复核 runtime revision、Redis L2 和 Swoole L1。
MARKDOWN . "\n";
    }
}
