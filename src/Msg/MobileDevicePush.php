<?php

namespace Mallto\Tool\Msg;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/3/19
 * Time: 下午6:02
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
     * @return mixed
     */
    public function pushMessageToAndroid($subject,$target, $targetValue, $title, $body, $appKey = null);


}
