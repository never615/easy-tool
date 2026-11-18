<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mallto\Tool\Domain\Log\Logger;
use Illuminate\Support\Facades\Log;

/**
 * 日志投递任务
 * LogJob 类
 *
 * @package Mallto\Tool\Jobs
 */
class LogJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务超时前最多可运行的秒数。
     *
     * @var int
     */
    public $timeout = 5;

    /**
     * 任务最多可尝试的次数。
     *
     * @var int
     */
    public $tries = 3;

    /**
     * 日志的方法名
     *
     * @var
     */
    private $type;

    private $content;


    /**
     * 创建任务实例。
     *
     * @param $type
     * @param $content
     */
    public function __construct($type, $content)
    {
        $this->type = $type;
        $this->content = $content;
    }


    /**
     * 执行任务。
     *
     * @return void
     */
    public function handle()
    {
        $logger = app(Logger::class);

        call_user_func([ $logger, $this->type ], $this->content);
    }


    /**
     * 任务处理失败。
     *
     * @param Exception $e
     */
    public function failed(Exception $e)
    {
        Log::error("日志投递任务失败");
        Log::warning($e);
    }

}
