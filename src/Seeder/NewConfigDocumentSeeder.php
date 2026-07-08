<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\NewConfigDocument;

class NewConfigDocumentSeeder extends Seeder
{
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

    private function configurationUsageMarkdown(): string
    {
        return <<<'MARKDOWN'
# 配置使用说明

日期：2026-06-26

本文说明当前项目中 `.env`、Docker Compose/K8s 环境变量、配置中心、传统配置入口之间的关系，以及日常应该在哪里修改配置。

## 总体原则

当前项目已经把业务全局配置迁到内部配置中心。运行期高频业务路径不能读取 DB 或 Redis，也不能在业务代码中直接调用 Laravel 的 `env()`。

配置中心的发布流程会生成运行期文件：

- `storage/framework/new_configs.env`：shell env 导出文件，供 LaravelS/Horizon 启动脚本 source。
- `storage/framework/new_configs_values.php`：逻辑 key/value 快照，供 `config('new_config.values')` 读取。
- `storage/framework/subject_configs_values.php`：按 `subject_id` 分组的项目动态配置快照，供 `config('subject_config_runtime.values')` 读取。

业务代码通过 `ConfigUtils::get()` 读取的配置，最终来自 `config('new_config.values')`，也就是 `new_configs_values.php` 被 Laravel config cache 加载后的内存数据。

项目动态配置通过 `SubjectUtils::getDynamicKeyConfigByOwner()` 读取时，优先来自 `config('subject_config_runtime.values')`，也就是 `subject_configs_values.php` 被 Laravel config cache 加载后的内存数据。快照缺失时第一阶段仍会回退旧的 Redis/DB 路径。

## 日常修改入口

日常优先使用 `配置中心` 下的模块化表单：

- `使用说明`
- `项目动态配置`
- `基础业务配置`
- `短信与告警配置`
- `定位算法配置`
- `定位维护配置`
- `BeaconArea配置`
- `定位日志配置`
- `Swoole Task配置`
- `运行期 Env 覆盖`
- `发布与重启`

这些页面对应代码中已登记的配置定义。新增业务配置时，应该先在代码定义中补充，再由 `php artisan tool:update` 补齐缺失的 `new_configs` 记录。

`配置中心 > 使用说明` 对应 `/admin/new_configs/usage`，展示数据库 `new_config_documents` 表中 slug 为 `configuration_usage` 的 Markdown 文档。默认内容由 `tool:update` 通过 seeder 写入，运行时不依赖项目根目录 `docs/` 文件。

## 配置类型速查

| 配置类型 | 管理入口 | 持久化来源 | 运行期读取 | 适用场景 |
| --- | --- | --- | --- | --- |
| 全局业务配置 | `配置中心 > 基础业务配置` 等模块化表单 | `new_configs` 表 | `config('new_config.values')` / `ConfigUtils::get()` | 所有项目共用的业务开关、阈值、接口地址 |
| 项目动态配置 | `配置中心 > 项目动态配置` | `subject_configs` 表 | `config('subject_config_runtime.values')` / `SubjectUtils::getDynamicKeyConfigByOwner()` | 按 `subject_id` 区分的定位、地图、推送、厂商参数 |
| 启动基础配置 | `.env`、Docker Compose env、K8s Secret/ConfigMap | 进程环境和 `.env` | Laravel config cache | DB/Redis/Mongo、配置中心自身依赖、容器启动前必须存在的连接信息 |
| 传统兜底配置 | `配置中心 > 传统配置` | `configs` / `new_configs` / `subject_configs` 原始记录 | 视具体代码路径而定 | 排查、迁移过渡、模块化表单尚未覆盖的历史 key |

## 主体项目动态配置

`配置中心 > 项目动态配置` 对应 `/admin/subject-config-module`，用于维护按项目 `subject_id` 绑定的动态配置。页面会先选择项目，再按“基础与前端、定位开关、定位算法、地图样式、导航、推送、日志排查、厂商接入、运行维护、历史/未归类、临时基站”等分组展示表单。

主体项目动态配置不是 `new_configs` 记录，也不会展开成大量 env key。它仍以 `subject_configs` 表作为后台编辑和持久化来源，发布时额外生成二维运行期快照：

```text
storage/framework/subject_configs_values.php

[
    subject_id => [
        key => value,
    ],
]
```

发布后的 Laravel config cache 会把快照加载到：

```text
config('subject_config_runtime.values')
```

定位、地图、导航、推送、厂商接入等按项目区分的配置，业务代码应通过 `SubjectUtils::getDynamicKeyConfigByOwner()` 读取。发布后的新 LaravelS/Horizon 进程会优先从内存快照读取项目配置，避免热路径反复访问 Redis/DB。快照缺失时第一阶段仍保留旧 Redis/DB 回退路径，用于兼容未发布、历史数据或异常恢复场景。

保存 `项目动态配置` 表单时，会写入 `subject_configs` 表并发布 `subject_configs_values.php` 快照，同时刷新 Laravel config cache。这个保存动作不会自动重启所有长驻进程；如果定位、推送、Swoole process、Horizon job 等长驻进程需要立刻读取新值，继续进入 `配置中心 > 发布与重启` 执行发布并重启。

新增主体项目动态配置 key 时，优先在所属业务包的 `*SubjectConfigDefinitions` 中定义，并通过 `SubjectConfigRegistry` 注册，这样后台会以固定分组、类型校验、默认值和中文说明展示。数据库中已经存在但尚未登记的历史 key 会出现在“历史/未归类”中，只允许修改已有 value，不提供任意新增。`sa_lo_st_` 前缀的临时定位基站开关属于“临时基站”，同一个项目最多 10 个，超过会拒绝保存。

`配置中心 > 传统配置 > 动态配置` 对应 `/admin/subject_configs`，是项目动态配置的原始 key/value 兜底入口。日常不建议直接使用它；只有遇到表单尚未覆盖的历史 key 或排查原始数据时再使用。保存后仍需要进入 `配置中心 > 发布与重启` 执行发布并重启，LaravelS/Horizon 新进程才会读取最新项目动态配置快照。

## `/admin/configs` 的定位

`/admin/configs` 现在不是日常主入口。

它保留在 `配置中心 > 传统配置 > 全局配置` 下，主要用途是：

- 查看迁移后的 `new_configs.global_config` 原始 key/value。
- 临时处理还没有模块化表单覆盖的历史 `ConfigUtils::get()` key。
- 模块化表单出问题时，作为运维兜底入口。
- 排查 `key`、`env_key`、发布时间、发布错误等原始数据。

正常业务配置应优先使用模块化表单，而不是直接使用 `/admin/configs`。

`/admin/configs` 的说明字段按纯文本展示和保存。历史数据中如果包含 HTML 标签，页面会清理为纯文本，避免可编辑字段渲染 HTML 带来安全风险。

## `/admin/new_configs` 的定位

`/admin/new_configs` 是运行期配置原始列表，也属于传统/排查入口。

它适合用于确认底层 `new_configs` 记录是否存在、是否启用、发布时间和发布错误。日常配置修改仍应优先走模块化表单。

## 运行期 Env 覆盖

`配置中心 > 运行期 Env 覆盖` 用于维护少量可以安全覆盖的 Laravel 运行期 env key。它不是 `.env` 编辑器，不会读取、展示或修改真实 `.env` 文件，只会写入 `new_configs` 表并在发布时导出到 `storage/framework/new_configs.env`。

该入口适合处理已经在 `config/*.php` 中通过 `env()` 读取、但尚未进入模块化表单的非启动类开关和阈值，例如定位队列限流开关。

该入口禁止 DB、Redis、Mongo、Cache、Session、Mail、云厂商、微信支付、MQTT、LaravelS/Horizon、配置中心自身 key。命中黑名单的 key 必须继续通过 `.env`、Docker Compose env 文件或 K8s Secret/ConfigMap 管理。

保存后仍需要进入 `配置中心 > 发布与重启` 执行发布并重启，LaravelS/Horizon 新进程才会读取最新值。

## 发布与重启

后台保存配置后，会发布运行期快照并刷新 Laravel config cache，但不会自动重启所有服务。

需要让 LaravelS/Horizon 新进程读取最新配置时，进入：

`配置中心 > 发布与重启`

然后点击发布并重启按钮。

该页面还会展示最终生效 Env 视图，便于确认 `.env`、当前进程 env、配置中心导出值合并后的实际结果。

命令行也可以手动发布：

```bash
php artisan tool:new_config_publish --force-config-cache
```

需要结合服务重启时，使用当前环境的重启脚本或容器/Pod 重启机制。

## Docker Compose / K8s / `.env` 的使用边界

Docker Compose env 文件、K8s Secret/ConfigMap、项目 `.env` 仍然用于启动基础配置。

适合放在这些地方的配置包括：

- `DB_*`
- `REDIS_*`
- `MONGO_*` / `MONGODB_*`
- `DATABASE_URL`
- 容器启动必须先知道的连接信息
- LaravelS/Horizon 启动前必须存在的进程级配置
- 配置中心自身需要的基础配置

这些配置不能放入配置中心。后台创建或发布 DB/Redis/Mongo 等 bootstrap key 时会被拒绝。

对于 `ConfigUtils::get()` 读取的业务全局配置，不建议用 Docker Compose env 文件兜底。原因是这类配置运行期读取的是 `new_configs_values.php` 快照，不是直接读取容器 env。

如果本地真实 Docker Compose 环境发现配置中心页面有 bug，推荐兜底方式是：

1. 直接修正数据库 `new_configs` 表对应 key 的 value/is_enabled。
2. 执行：

```bash
php artisan tool:new_config_publish --force-config-cache
```

3. 重启 LaravelS 和 Horizon 容器。

只有启动基础配置才优先通过 Docker Compose env 文件修正。

## 生效优先级

对 Laravel 配置文件中的 `env()` key，启动和 `config:cache` 时的覆盖关系大致是：

1. 容器/K8s 注入的环境变量。
2. 项目 `.env`。
3. 配置中心导出的 `storage/framework/new_configs.env`，以及 `tool:new_config_publish` 传给 `config:cache` 子进程的 env 值。

最终运行期 LaravelS/Horizon worker 读取的是 Laravel config cache。

对 `ConfigUtils::get()` 读取的业务全局配置，读取路径是：

```text
new_configs 表
  -> tool:new_config_publish
  -> storage/framework/new_configs_values.php
  -> config('new_config.values')
  -> ConfigUtils::get()
```

因此这类配置是否生效，以配置中心发布后的 `new_configs_values.php` 和 Laravel config cache 为准。

对 `SubjectUtils::getDynamicKeyConfigByOwner()` 读取的项目动态配置，读取路径是：

```text
subject_configs 表
  -> tool:new_config_publish
  -> storage/framework/subject_configs_values.php
  -> config('subject_config_runtime.values')
  -> SubjectUtils::getDynamicKeyConfigByOwner()
```

因此定位、推送等热路径读取项目配置时，发布后的新进程优先走内存数组，不再访问 Redis/DB。

## 开发服务器

开发服务器如果手动覆盖 `.env`，例如：

```bash
cp .env.integration .env
```

后续仍应运行现有重启脚本。重启脚本会重新发布配置中心运行期 env，并刷新 config cache。

如果只是改了配置中心业务配置，应优先使用后台 `发布与重启` 页面，而不是依赖 LaravelS inotify reload。

当前已确认 LaravelS reload 在开发环境曾出现旧 task worker 残留问题，因此配置生效路径采用更保守的 full restart。

## 新增配置规范

新增业务配置时遵守以下规则：

1. 运行期代码不要直接调用 `env()`。
2. 需要 Laravel 配置项时，先写入 `config/*.php`，业务代码用 `config()`。
3. 需要全局业务配置时，先在 `GlobalConfigDefinitions` 中登记，再由 `tool:update` 补齐缺失记录。
4. 已存在的线上配置 key，seeder 不允许覆盖 value、默认值、启用状态、说明等线上数据。
5. DB/Redis/Mongo/bootstrap key 不允许进入配置中心。
6. 新增模块化配置时，优先做成 `配置中心` 下的表单，不新增散落入口。
7. `subject_configs` 中 `sa_lo_st_` 前缀的临时定位基站开关，同一个项目最多 10 个，超过会拒绝保存。
8. 新增项目动态配置 key 时，优先在所属业务包通过 `SubjectConfigRegistry` 注册定义，让后台以表单和中文说明展示；临时或历史 key 会在“历史/未归类”中按数据库已有记录展示。

## 常用排查

查看发布命令是否正常：

```bash
php artisan tool:new_config_publish --force-config-cache
```

查看后台最终生效值：

```text
配置中心 > 发布与重启
```

查看原始运行期配置记录：

```text
配置中心 > 传统配置 > 运行期配置
```

查看迁移后的全局配置记录：

```text
配置中心 > 传统配置 > 全局配置
```

如果配置修改后没有生效，优先检查：

- 对应 `new_configs` key 是否存在。
- 如果是项目动态配置，对应 `subject_configs` key 和 `subject_id` 是否存在。
- 如果是项目动态配置，是否在 `配置中心 > 项目动态配置` 选中了正确项目。
- `is_enabled` 是否符合预期。
- `last_published_at` 是否更新。
- `last_publish_error` 是否为空。
- `storage/framework/new_configs_values.php` 是否包含目标 key。
- `storage/framework/subject_configs_values.php` 是否包含目标 `subject_id` 和 key。
- 是否已经重启 LaravelS/Horizon 新进程。
MARKDOWN;
    }
}
