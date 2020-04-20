<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Log;

use Mallto\Admin\Data\OperationLog;
use Mallto\Tool\Data\ThirdApiLog;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 12/01/2017
 * Time: 3:21 PM
 */
class LoggerDb implements Logger
{

    private $switch = false;


    /**
     * LoggerAliyun constructor.
     */
    public function __construct()
    {
        $this->switch = config("app.ali_log", true);
    }


    /**
     * 记录第三方接口通讯日志
     *
     * @param $content
     *
     * @return
     */
    public function logThirdPart($content)
    {
        if ( ! $this->switch) {
            return;
        }
        try {
            ThirdApiLog::create($content);
        } catch (\Exception $exception) {
        }

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
        if ( ! $this->switch) {
            return;
        }
        try {
            if ($log['action'] === 'request') {
                OperationLog::create(
                    array_merge(
                        array_only($log, [
                            'user_id',
                            'path',
                            'method',
                            'input',
                            'subject_id',
                        ]), [
                        'ip' => $log['request_ip'],
                    ]));
            }
        } catch (\Exception $exception) {
            // pass
        }
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
