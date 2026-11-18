<?php

namespace Mallto\Tool\Msg;

/**
 *
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2018/3/19
 * 时间： 下午6:02
 */
interface MobileDevicePush
{

    /**
     * 推送消息给安卓
     *
     * @param $subject
     * @param $target
     * @param $targetValue
     * @param $title
     * @param $body
     * @param $appKey
     *
     * @return mixed
     */
    public function pushMessageToAndroid($subject, $target, $targetValue, $title, $body, $appKey = null);

}
