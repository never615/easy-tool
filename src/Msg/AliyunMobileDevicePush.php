<?php

namespace Mallto\Tool\Msg;

use GuzzleHttp\Exception\ClientException;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Domain\Traits\AliyunTrait;
use Mallto\Tool\Exception\ThirdPartException;
use Illuminate\Support\Facades\Log;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/3/19
 * Time: 下午6:02
 */
class AliyunMobileDevicePush extends AbstractAPI implements MobileDevicePush
{

    use AliyunTrait;

    const TARGET_DEVICE = 'DEVICE';
    const TARGET_ACCOUNT = 'ACCOUNT';
    const TARGET_ALIAS = 'ALIAS';
    const TARGET_TAG = 'TAG';
    const TARGET_ALL = 'ALL';

    protected $baseUrl = "http://cloudpush.aliyuncs.com/";


    /**
     * 推送消息给安卓
     *
     * @param      $subject
     * @param      $target
     * @param      $targetValue
     * @param      $title
     * @param null $body
     * @param null $appKey
     *
     * @return mixed
     */
    public function pushMessageToAndroid($subject, $target, $targetValue, $title, $body, $appKey = null)
    {


        $params = array_merge([
            'AppKey'      => $appKey,
            'Target'      => $target,
            'TargetValue' => $targetValue,
            'Title'       => $title,
            'Body'        => $body,
        ], [
            "RegionId" => "cn-hangzhou",
            "Action"   => "PushMessageToAndroid",
            "Version"  => "2016-08-01",
        ]);

        $query = $this->mergePublicParamsAndSign($params);

        $http = $this->getHttp();
        try {
            $response = $http->request($this->baseUrl . "?$query", 'GET');

            try {
                $contents = $http->parseJson($response);
                $this->checkAndThrow($contents);

                return true;
            } catch (\Exception $exception) {
                Log::error("阿里云移动推送:数据解析错误");
                Log::warning($exception);

                return false;
            }

        } catch (ClientException $exception) {
            Log::error("阿里云移动推送:ClientException");
            Log::warning($exception);
            Log::warning($exception->getResponse()->getBody());

            return false;
        }
    }


    /**
     * 不同的实现需要重写此方法 标准的json请求使用
     * Check the array data errors, and Throw exception when the contents contains error.
     *
     * @param array $contents
     *
     * @return array
     * @throws ThirdPartException
     */
    protected function checkAndThrow(
        array $contents
    ) {
        // TODO: Implement checkAndThrow() method.
    }
}
