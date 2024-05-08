<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
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
 * Class LogJob
 *
 * @package Mallto\Tool\Jobs
 */
class LogJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * The number of times the job may be attempted.
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
     * Create a new job instance.
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
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $logger = app(Logger::class);

        call_user_func([ $logger, $this->type ], $this->content);
    }


    /**
     * The job failed to process.
     *
     * @param Exception $e
     */
    public function failed(Exception $e)
    {
        Log::error("日志投递任务失败");
        Log::warning($e);
    }

}
