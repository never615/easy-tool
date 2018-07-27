<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Log;

use Aliyun\SLS\Client;
use Aliyun\SLS\Models\LogItem;
use Aliyun\SLS\Models\PutLogsRequest;


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

    private $client;
    private $serverName;

    private $switch = false;

    /**
     * LoggerAliyun constructor.
     */
    public function __construct()
    {
        $this->switch = config("app.ali_log", true);
        $this->client = new Client(config("app.aliyun_log_endpoint"), config("app.aliyun_log_access_key_id"),
            config("app.aliyun_log_access_key"));
        $this->project = config("app.aliyun_log_project");
        $this->serverName = php_uname("n");
    }


    /**
     * 记录第三方接口通讯日志
     *
     * 请求别人的接口的日志记录
     *
     * @param $tag
     * @param $content
     */
    public function logThirdPart( $content)
    {
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = "";
        $logitems = array ();
        $logItem = new LogItem();
        $logItem->setTime(time());

        $logItem->setContents(
            array_merge($content, [
                "server_name" => $this->serverName,
                "env"         => config("app.env"),
            ])
        );
        array_push($logitems, $logItem);
        $req2 = new PutLogsRequest($this->project, $this->logstore_third_part_api, $topic, $source, $logitems);
        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {
            \Log::error($exception->getMessage());
        }
    }

    /**
     * 记录自己api的通讯日志
     *
     * @param $action
     * @param $content
     * @return mixed|void
     */
    public function logOwnerApi($action, $content)
    {
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = "";
        $logitems = array ();
        $logItem = new LogItem();
        $logItem->setTime(time());
        $logItem->setContents(array_merge($content, [
            'action'      => $action,
            "server_name" => $this->serverName,
            "request_url" => config("app.url"),
        ]));
        array_push($logitems, $logItem);
        $req2 = new PutLogsRequest($this->project, $this->logstore_own_api, $topic, $source, $logitems);

        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {

        }
    }

    /**
     * 记录管理端的操作日志
     *
     * @param  array $log
     * @return mixed
     */
    public function logAdminOperation($log)
    {
        if (!$this->switch) {
            return;
        }

        $topic = "";
        $source = "";
        $logitems = array ();
        $logItem = new LogItem();
        $logItem->setTime(time());
        $logItem->setContents(array_merge($log, [
            "server_name" => $this->serverName,
            "request_url" => config("app.url"),
        ]));
        array_push($logitems, $logItem);
        $req2 = new PutLogsRequest($this->project, $this->logstore_admin_operation, $topic, $source, $logitems);
        try {
            $res2 = $this->client->putLogs($req2);
        } catch (\Exception $exception) {

        }
    }
}
