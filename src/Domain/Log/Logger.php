<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Log;

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 12/01/2017
 * 时间： 3:21 下午
 */
interface Logger
{

    /**
     * 记录第三方接口通讯日志
     *
     * @param $content
     *
     * @return
     */
    public function logThirdPart($content);


    /**
     * 记录自己api的通讯日志
     *
     * @param $action
     * @param $content
     *
     * @return
     */
    public function logOwnerApi($content);


    /**
     * 记录管理端的操作日志
     *
     * @param array $log
     *
     * @return mixed
     */
    public function logAdminOperation($log);


    /**
     * 调度任务执行记录
     *
     * @param $content
     *
     * @return mixed
     */
    public function logSchedule($content);


    /**
     * 队列任务执行记录
     *
     * @param      $content
     *
     * @return mixed
     */
    public function logQueue($content);
}
