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

日期：2026-08-11

本文说明 `.env`、Docker Compose/K8s 环境变量、配置中心和传统配置入口的当前边界。

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

以下项目 key 固定为 `shared_redis`，不会进入 Swoole：

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
- `map_layer`、`sprite` 等地图数据可能很大；其他 Redis-only 配置读取较低频。它们不占用容量有限的 runtime Swoole 表。Swoole payload 超限时虽会安全回退 Redis，但继续探测 L1 没有性能收益。

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

1. 运行时代码不得直接调用 `env()`；环境值进入 `config/*.php` 后用 `config()`。
2. 全局业务读取统一使用 `ConfigUtils`，项目业务读取统一使用 `SubjectUtils`。
3. 小型业务值默认 `swoole_redis`；固定大值/低频值明确登记为 `shared_redis`。
4. 不得把业务值重新加入 `new_configs_values.php`、`subject_configs_values.php` 或启动 env。
5. Definition Syncer 会创建配置目录，但新目录记录的 `value=null`、`override_source=null`、`is_enabled=false`；已有人工/系统覆盖的 value、启用状态和来源保持不变。name、remark、type、env key、default、options、sort 和 apply/reload metadata 会随代码定义同步。
6. 当调用方默认值来自 `config($configKey)` 时，Global Definition 必须通过 `fromConfig()` 读取同一 config key；当默认值是代码常量时，调用方与 Definition 必须共用 Defaults 常量；原本没有默认值时不得人为补默认值。
7. 临时 `sa_lo_st_*` 和历史未注册 key 仍按业务缓存兼容处理；同一项目临时基站开关最多 10 个。

## 常用排查

业务配置未生效：

1. 全局配置检查 DB 的 `override_source`、`is_enabled` 和 value；项目配置检查正确的 subject/scope/key/value。
2. 检查 definition mode；`shared_redis` 不应访问 Swoole。
3. 检查 Redis L2 写穿、runtime revision 和无 value 广播。
4. Swoole 开启时检查 subscriber / revision watcher；关闭时确认已回退 Redis/DB。
5. 定位参数或 tx rssi 还要检查派生缓存是否按带尾下划线的项目 prefix 清理。

启动配置未生效：检查 `new_configs.env`、config cache、restart switch、restart generation 和新进程是否实际拉起。不要检查旧业务 snapshot 文件，也不要把生成 env 与真实 `.env` 混为一谈。

## 上线检查

迁移上线前，逐项核对以下 21 个全局业务 key：

```text
location.upload_data_direct_parse_enabled
location.locator_negative_cache_ttl_seconds
location.queue_limiters.enabled
location.queue_limiters.pgsql_write.concurrency
location.queue_limiters.location_result_storage.concurrency
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
