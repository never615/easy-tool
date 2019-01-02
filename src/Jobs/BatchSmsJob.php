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
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\SmsNotify;
use Mallto\Tool\Domain\Sms\Sms;
use Mallto\User\Data\User;

/**
 * 群发短信任务
 *
 * 暂不处理可能出现的发送接口不可用的异常
 *
 * Class BatchSmsJob
 *
 * @package Mallto\Tool\Jobs
 */
class BatchSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    /**
     * @var
     */
    private $id;


    /**
     * Create a new job instance.
     *
     * @param $id
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
//        \Log::debug("群发短信任务");

        $smsNotify = SmsNotify::find($this->id);
        if ($smsNotify->status == "not_start") {
            $smsNotify->status = "processing";
            $smsNotify->save();
        } else {
            //其他状态,则不执行任务
            return;
        }


        $sms = app(Sms::class);

        $templateCode = $smsNotify->sms_template_code;
        $selects = $smsNotify->selects;
        $selects = json_decode($selects, true);
        $subject = $smsNotify->subject;
        $subjectId = $subject->id;


        //array (
        //'choice_users' => '[{"id":"6","type":"member_levels","text":"黑卡"},{"id":"91832","type":"users","text":"云心:18666202809"}]',
        //  'templates' => 'SMS_141195417',
        //  '_token' => 'OUKat9VS8oNniprHVUON3lmMzuW8zhOXUphxS10A',
        //)


        $memberLevelSelects = array_where($selects, function ($value, $key) {
            return $value["type"] == "member_levels";
        });

//        \Log::debug($memberLevelSelects);

        $userSelects = array_where($selects, function ($value, $key) {
            return $value["type"] == "users";
        });

//        \Log::debug($userSelects);

        $sign = SubjectUtils::getDynamicKeyConfigByOwner("sms_sign", $subject, "墨兔");

        //已经处理过的会员等级
        $handledMemberLevel = [];

        foreach ($memberLevelSelects as $select) {
            //检查select是会员分类,还是单一用户

            //1.先把所有会员等级的用户都发送一遍 ,然后记录已发的会员等级
            if ($select["type"] == "member_levels") {
                User::where("subject_id", $subjectId)
                    ->whereHas("member", function ($query) use ($select) {
                        $query->where("member_level_id", $select["id"]);
                    })->chunk(100, function ($users) use ($sms, $templateCode, $sign) {
                        $this->send($sms, $users, $sign, $templateCode);
                    });

                $handledMemberLevel[] = $select["id"];
            }
        }

        //2.发送给用户,检查所属会员等级是否是已发送的会员等级
        User::where("subject_id", $subjectId)
            ->whereNotNull("mobile")
            ->whereHas("member", function ($query) use ($handledMemberLevel) {
                $query->whereNotIn("member_level_id", $handledMemberLevel);
            })
            ->whereIn("id", array_pluck($userSelects, "id"))
            ->chunk(100, function ($users) use ($sms, $templateCode, $sign) {
                $this->send($sms, $users, $sign, $templateCode);
            });


        $smsNotify->status = "finish";
        $smsNotify->save();
    }

    /**
     * The job failed to process.
     *
     * @param Exception $e
     */
    public function failed(Exception $e)
    {
        // 发送失败通知, etc...

        \Log::error("批量发送短信失败");
        \Log::warning($e);
        $smsNotify = SmsNotify::find($this->id);
        $smsNotify->status = "failure";
        $smsNotify->save();
    }


    private function send($sms, $users, $sign, $templateCode)
    {
        $users = $users->pluck("mobile")->toArray();
        $count = count($users);

        try {
            //批量发送短信
            $sms->sendBatchSms($users,
                array_fill(0, $count, $sign),
                $templateCode,
                array_fill(0, $count,
                    [
                        "code" => 1111,
                    ])
            );
        } catch (\Exception $exception) {
            \Log::error("短信发送失败");
            \Log::warning($exception);
        }
    }


}
