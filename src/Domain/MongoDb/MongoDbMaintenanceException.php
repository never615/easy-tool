<?php

namespace Mallto\Tool\Domain\MongoDb;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** MongoDB 计划维护期间访问历史数据时返回的稳定业务异常。 */
final class MongoDbMaintenanceException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(null, 'MongoDB 维护中，历史位置数据暂不可用');
    }
}
