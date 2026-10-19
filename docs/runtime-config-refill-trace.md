# 运行配置冷回填计时

`RUNTIME_CONFIG_REFILL_TRACE_ENABLED` 默认关闭。此诊断只覆盖
`RuntimeConfigSharedStore::remember()`：上层共享缓存 miss 后的锁、缓存复查和 loader。
不覆盖热读、首次 L1/L2 查询或 `fresh()`，不增加 Redis 命令，不改变 TTL、锁和回退。

在独立诊断镜像中设置以下启动变量，并重启 LaravelS/Horizon：

```text
RUNTIME_CONFIG_REFILL_TRACE_ENABLED=true
RUNTIME_CONFIG_REFILL_TRACE_RUN_ID=refill-diagnostic-unique-run
RUNTIME_CONFIG_REFILL_TRACE_DIRECTORY=/var/www/html/storage/framework/refill-diagnostic-unique-run
```

目录必须事先创建、为进程可写的本机目录。不要使用网络存储。run ID 只允许字母、数字、
下划线和连字符，长度最多 96。无效配置、目录不可写或本地文件失败只停用诊断；业务继续。
每 PID 最多尝试 256 条，写失败也占配额；fork 后按新 PID 重新计数。
文件名为 `<run-id>-<pid>.jsonl`。使用 fresh 目录；不跨重启拼接 PID 配额。

每条记录包含 identity 指纹、PID/序号、墙上时钟和单调起止、总耗时、锁竞争次数、
sleep 次数/实际耗时、loader 次数/实际耗时及返回来源。不记录配置名、值、异常消息或凭据。
`sequence=256` 表示后续记录已截断，不能用该进程的文件推导完整冷调用率。
缺失记录也可能是目录/写入失败，不能把缺失当作零调用。

`loader_ns` 包含 loader 与 payload 构造，并非纯 SQL 时间；`total_ns` 不含上游缓存查找
和末尾文件写入。总耗时扣除 sleep/loader 的剩余部分混有 Redis、锁和调度，不能当作纯锁等待。
文件写入和冷路径计时仍会增加实际诊断开销。该窗口用于定位，不能代替关闭诊断的容量验收。

竞争回填在锁未取得且缓存仍为空时，以 10ms 开始轮询，并按 2 倍退避，单次最多 250ms。
这是冷路径的等待上限，不代表每次调用都会睡眠 250ms；热命中和已回填值不会进入该等待。

结束后自然排空、保存完整文件和源码/配置指纹，再关闭开关并重启两服务。不得记录配置值
来解释 identity；需要映射时在现有源代码中计算对应 fingerprint。
