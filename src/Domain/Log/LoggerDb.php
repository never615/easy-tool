<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Log;

use Illuminate\Support\Facades\Log;
use Mallto\Admin\Data\OperationLog;
use Mallto\Tool\Data\ThirdApiLog;
use Mallto\Tool\Data\OwnerApiLog;

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
        $this->switch = config("app.db_log", true);
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
        if (!$this->switch) {
            return;
        }
        try {
            ThirdApiLog::create($content);
        } catch (\Exception $exception) {
            Log::warning($exception);
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
        if (!$this->switch) {
            return;
        }

        // 不吞掉写入异常，让现有 LogJob 重试并暴露失败，避免“开了日志却没有资料”。
        OwnerApiLog::create(array_only($content, [
            'request_id', 'uuid', 'action', 'method', 'url', 'request_ip',
            'user_id', 'header', 'input', 'status',
        ]));
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
        if (!$this->switch) {
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
//            Log::info($exception);
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
