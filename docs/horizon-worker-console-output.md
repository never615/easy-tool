# Horizon 高频任务控制台输出

更新：2026-10-10。状态：源码实现及单元回归通过；amd64测试ECS的等效实验已通过30标签90秒基础门槛，长期容量未验收。

## 原因与范围

测试机捕获的AreaLogJob、LocatorChangeNotifyJob超时堆栈位于JobProcessing事件的
Symfony Console StreamOutput::doWrite，业务handle尚未执行。Worker逐任务打印RUNNING/DONE，
输出经过Supervisor/Master读取；高频输出可能填满管道，阻塞写入并耗尽3秒Job超时。
系统调用采样也看到几十字节stdout写入等待约0.39至0.51秒。

HorizonServiceProvider在启动时给WorkerCommandString添加标准--quiet参数，默认开启。
不修改vendor、队列路由、Job timeout/tries、共享限流、实时位置缓存或业务载荷。
应用错误日志、Horizon事件及失败任务持久记录保持；关闭的只是子Worker控制台输出。
Master启动/退出消息保持。完整运营/故障验收仍需单独进行。

## 配置与发布

宿主config/horizon.php提供horizon.worker_quiet，读取HORIZON_WORKER_QUIET，默认true；
Provider缺少该配置时也默认true，使旧宿主配置配合新包仍有保护。
临时CLI排障可设HORIZON_WORKER_QUIET=false并重建Horizon；不要在高频压测中保持开启逐任务输出。
若命令已有--quiet/-q则不重复添加，其他自定义命令参数保留。

正式发布需要构建包含Provider修复的业务镜像，并通过既有app_update流程部署。
运行docker top检查真实horizon:work命令含--quiet；restart并不会加载新容器环境。
诊断auto_prepend/只读挂载仅用于隔离ECS实验，不是正式镜像发布。

## 验证与剩余事项

宿主HorizonWorkerQuietTest覆盖默认开启、重复boot、显式false、已有quiet参数和任务路由/超时不变。
测试使用PHP8.5，存在既有依赖弃用提示；运行代码兼容PHP8.2。
详细运行证据在部署仓库docs/performance/2026-10-10-horizon-worker-timeout-investigation.md。
这不解决AreaLog同标签并发重复、配置读取或Redis命令放大，不能外推200标签/生产容量。
