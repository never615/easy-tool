<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Log;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 12/01/2017
 * Time: 3:21 PM
 */
class LoggerDb implements Logger
{

    /**
     * 记录第三方接口通讯日志
     *
     * @param $content
     *
     * @return
     */
    public function logThirdPart($content)
    {
        // TODO: Implement logThirdPart() method.
    }


    /**
     * 调度任务执行记录
     *
     * @param $content
     *
     * @return mixed
     */
    public function logSchedule($content)
    {
        // TODO: Implement logSchedule() method.
    }


    /**
     * 记录自己api的通讯日志
     *
     * @param $content
     *
     * @return
     */
    public function logOwnerApi($content)
    {
        // TODO: Implement logOwnerApi() method.
    }


    /**
     * 记录管理端的操作日志
     *
     * @param array $log
     *
     * @return mixed
     */
    public function logAdminOperation($log)
    {
        // TODO: Implement logAdminOperation() method.
    }


    /**
     * 队列任务执行记录
     *
     * @param      $content
     *
     * @return mixed
     */
    public function logQueue($content)
    {
        // TODO: Implement logQueue() method.
    }
}
