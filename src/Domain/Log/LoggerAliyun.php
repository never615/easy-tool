<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Log;

use Aliyun_Log_Models_LogItem;
use Illuminate\Support\Facades\Log;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 12/01/2017
 * Time: 3:21 PM
 */
class LoggerAliyun implements Logger
{

    private $project;

    private $logstore_third_part_api = "third_part_api";

    private $logstore_admin_operation = "admin_operation";

    private $logstore_own_api = "own_api";

    private $logstore_schedule = "schedule";

    private $logstore_queue = "queue";

    private $client;

    private $serverName;

    private $localIp;

    private $switch = false;

    private $switch_database_operation_log = false;

    /**
     * LoggerAliyun constructor.
     */
    public function __construct()
    {
        $this->switch = config("app.ali_log", true);
        $this->client = new \Aliyun_Log_Client(config("app.aliyun_log_endpoint"), config("app.aliyun_access_key_id"),
            config("app.aliyun_access_key"));
        $this->project = config("app.aliyun_log_project");
        $this->serverName = php_uname("n") ?: "cli";
        $this->localIp = $_SERVER['SERVER_ADDR'] ?? "";
        $this->switch_database_operation_log = config("app.switch_database_operation_log", false);
    }


    /**
     * 记录第三方接口通讯日志
     *
     * 请求别人的接口的日志记录
     *
     * @param $tag
     * @param $content
     */
    public function logThirdPart($content)
    {
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = $this->localIp;
        $logitems = [];
        $logItem = new Aliyun_Log_Models_LogItem();
        $logItem->setTime(time());

        $logItem->setContents(
            array_merge($content, [
                "server_name" => $this->serverName,
                "env" => config("app.env"),
            ])
        );
        array_push($logitems, $logItem);
        $req2 = new  \Aliyun_Log_Models_PutLogsRequest($this->project, $this->logstore_third_part_api, $topic, $source,
            $logitems);
        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {
            Log::warning("阿里日志 logThirdPart");
            Log::warning($exception);
            Log::warning($content);
        }
    }


    /**
     * 记录自己api的通讯日志
     *
     * @param $content
     *
     * @return mixed|void
     */
    public function logOwnerApi($content)
    {
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = $this->localIp;
        $logitems = [];
        $logItem = new Aliyun_Log_Models_LogItem();
        $logItem->setTime(time());
        $logItem->setContents(array_merge($content, [
            "server_name" => $this->serverName,
            "env" => config("app.env"),
        ]));
        array_push($logitems, $logItem);
        $req2 = new \Aliyun_Log_Models_PutLogsRequest($this->project, $this->logstore_own_api, $topic, $source, $logitems);

        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {
            Log::warning("阿里日志 logOwnerApi");
            Log::warning($exception);
            Log::warning($content['url'] ?? null);
        }
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

        $topic = "";
        $source = $this->localIp;
        $logitems = [];
        $logItem = new Aliyun_Log_Models_LogItem();
        $logItem->setTime(time());
        $logItem->setContents(array_merge($log, [
            "server_name" => $this->serverName,
            "request_url" => config("app.url"),
            "env" => config("app.env"),
        ]));
        array_push($logitems, $logItem);
        $req2 = new \Aliyun_Log_Models_PutLogsRequest($this->project, $this->logstore_admin_operation, $topic, $source,
            $logitems);
        try {
            if ($this->switch_database_operation_log) {
                //写入数据库
                $loggerDb = app(LoggerDb::class);
                $loggerDb->logAdminOperation($log);
            }

            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {
            Log::warning("阿里日志 logAdminOperation");
            Log::warning($exception);
            Log::warning($log['path'] ?? null);
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
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = $this->localIp;
        $logitems = [];
        $logItem = new Aliyun_Log_Models_LogItem();
        $logItem->setTime(time());
        $logItem->setContents(array_merge($content, [
            "server_name" => $this->serverName,
            "env" => config("app.env"),
        ]));
        array_push($logitems, $logItem);
        $req2 = new \Aliyun_Log_Models_PutLogsRequest($this->project, $this->logstore_schedule, $topic, $source, $logitems);
        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {
            Log::warning("阿里日志 schedule");
            Log::warning($exception);
            Log::warning($content);

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
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = $this->localIp;
        $logitems = [];
        $logItem = new Aliyun_Log_Models_LogItem();
        $logItem->setTime(time());
        $logItem->setContents(array_merge($content, [
            "server_name" => $this->serverName,
            "env" => config("app.env"),
        ]));
        array_push($logitems, $logItem);
        $req2 = new \Aliyun_Log_Models_PutLogsRequest($this->project, $this->logstore_queue, $topic, $source, $logitems);
        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {
            Log::warning("阿里日志 queue");
            Log::warning($exception);
        }
    }
}
