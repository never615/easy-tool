<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\Jobs\RedisJob;

/** 复用 RedisJob 构造函数已解码的不可变就绪负载。 */
class CachedPayloadRedisJob extends RedisJob
{
    public function payload()
    {
        // 父构造函数执行期间 decoded 尚未填充。无效的
        // JSON 也保留父类的 null 结果及错误行为。
        return $this->decoded ?? parent::payload();
    }
}
