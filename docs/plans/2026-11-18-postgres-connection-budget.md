# PostgreSQL 连接治理计划

主要读者：开发、评审与测试发布人员。状态：进行中；2026-11-18 用户批准先实施阶段一并部署 test 观察。后续阶段待本阶段证据评估，仅在 Git 维护。

## 已知问题

test 的四个 LaravelS Pod 各有 60 个 Task worker，调查时共持有 240 条 PostgreSQL 连接；Horizon 30 个 worker 持有 27 条。滚动发布中 LaravelS 与 Horizon 均允许额外一个 Pod，因此新旧进程会叠加连接需求。此快照不代表持续吞吐或可直接减少的进程数。

已确认 `Queue::looping` 和异常 Handler 的清理逻辑无条件调用 `DB::transactionLevel()`，会创建默认 Connection；连接初始化监听器执行会话 SET 后打开 PDO。无数据库业务的任务或异常也会被迫建连。

## 阶段一：避免清理逻辑主动建连

1. 在独立 easy-tool checkout 基于 test 实际分支修复两处事务清理，只访问已解析的 DatabaseManager 及已创建的连接；保留嵌套事务回滚、回滚异常向上传播与现有注释。
2. 回归覆盖：未解析 DB、已解析但无连接、已有惰性连接、多个连接的嵌套事务、rollback 失败；测试实际队列事件回调和异常 render 入口。测试使用 PHP 8.2、最小容器及内存 SQLite，不读取部署连接设置。
3. 保存发布前 60 秒同口径采样：进程数、按队列/进程角色 PG socket、PG 活跃/空闲、队列 ready/reserved/delayed/最老年龄、可用的吞吐/耗时/失败指标。
4. 提交并推送 easy-tool develop，通过既有 Flow 发布 test；核对全部 LaravelS 与 Horizon 的包引用、修改文件哈希、Ready、HTTP 健康和重启。
5. 新 Pod 就绪后按相同口径采样并在稳定运行后复查。比较每类 worker 的连接占比，区分进程伸缩和流量变化；记录滚动期间数据库容量错误。无可用指标时明确标注，不能用缺少数据声称零失败。
6. 将版本、测试、发布和观察结论记录为开发验收资料。阶段一完成不等于解决 Task worker 主体连接占用。

本阶段保持 persistent、进程上限、Redis funnel 及数据库配置不变，不清队列、不改写业务数据。无数据库迁移。滚动期间若容量阻塞就绪，应记录有界的临时处置并恢复原策略；不能将临时操作误报为代码优化收益。

## 阶段二：连接预算与部署峰值

按角色和其他应用占用制定实例连接预算，包含终止中 Pod 的连接。评估 LaravelS/Horizon 顺序滚动，以及在可接受短时降容情况下的 maxSurge/maxUnavailable。单副本 Horizon 若先停后启会短暂停止消费，应单独评估。

## 阶段三：事务连接池隔离验证

前置：先处理结果存储消费容量。2026-11-19 已确认 200 条失败样本均为首次领取即超过 60 秒截止时间。用户补充此前数天单 Horizon Pod 正常运行，故当晚 4→1 的短窗口不能作为根因结论。按用户最新方向，先修改 Flow 的单 Pod CPU/内存资源并验证 HPA 自动伸缩，storage 专用 worker 数量实验暂缓。详见[积压诊断记录](../reports/2026-11-19-location-result-storage-backlog.md)。

选择固定 PgBouncer 版本，在隔离环境验证 PHP 8.2 / PDO_pgsql 的实际 prepare 行为、时区、search_path、超时、SET LOCAL、事务回滚、重试及 advisory lock。维护、VACUUM 和依赖会话锁的 benchmark 保留直连通道。先验证一组队列，再考虑 LaravelS Task。

评估客户端连接数与 PG 后端数、事务耗时、池等待、业务吞吐及队列年龄；不能依据低频 active 快照直接指定池大小，多实例池额度必须纳入总预算。

## 阶段四：进程容量与闲置回收

根据计算吞吐和定位延迟评估 Task worker 数量。若研究 PDO 闲置回收，先确保每次物理重连均初始化会话设置，并保护在用事务/游标。单独关闭 DB_PERSISTENT 不会自动释放 Laravel 常驻对象持有的 PDO，不作为独立治理方案。

## 进度

- [x] 只读调查 test 角色连接数与代码建连原因。
- [x] 建立分阶段计划。
- [x] 阶段一实现及回归：PHP 8.2 最小容器 13 项 / 33 断言通过；原代码同套测试 6 项失败。
- [x] test 发布与前后观察：守卫探针通过；原滚动策略已恢复。副本变化使整体连接下降不可直接归因，定位结果队列仍有容量问题，见[阶段一验收记录](../reports/2026-11-18-lazy-transaction-guard-test.md)。
- [x] 诊断结果存储积压：已确认缩容时间线和首次领取过期，尚未执行容量调整。
- [x] 单 Pod CPU/内存调优：Flow CPU request=400m、memory limit=3072Mi；HPA 限定 1–2，自动扩容及秒级队列恢复已验证，见[资源验收](../../../../../docs/performance/2026-11-19-horizon-resource-autoscaling.md)。低负载自动缩容尚未实测。
- [ ] 继续治理 LaravelS/Horizon 同时滚动的连接峰值；按需评估队列扩缩容指标。
- [ ] 分离短存储任务与长导出任务的 reservation 回收配置，并验证异常退出恢复。
- [ ] 在上述问题处理后推进事务连接池隔离验证。

返回[文档索引](../README.md)。
