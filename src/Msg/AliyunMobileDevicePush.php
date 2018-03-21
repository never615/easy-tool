<?php

namespace Mallto\Tool\Msg;

use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Utils\AppUtils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/3/19
 * Time: 下午6:02
 */
class AliyunMobileDevicePush extends AbstractAPI implements MobileDevicePush
{

    protected $slug = 'aliyun_push';

    const TARGET_DEVICE='DEVICE';
    const TARGET_ACCOUNT='ACCOUNT';
    const TARGET_ALIAS='ALIAS';
    const TARGET_TAG='TAG';
    const TARGET_ALL='ALL';


    //http://cloudpush.aliyuncs.com
    protected $SETTING_KEY_BASE_URL = "aliyun_push_url";

    /**
     * 推送消息给安卓
     *
     * @param      $subject
     * @param      $target
     * @param      $targetValue
     * @param      $title
     * @param null $body
     * @param null $appKey
     * @return mixed
     */
    public function pushMessageToAndroid($subject, $target, $targetValue, $title, $body, $appKey = null)
    {
        $request = $this->mergePublicParamsAndSign([
            'Action'      => 'PushMessageToAndroid',
            'AppKey'      => $appKey,
            'Target'      => $target,
            'TargetValue' => $targetValue,
            'Title'       => $title,
            'Body'        => $body,
        ]);


        try {
            $response = $this->parseJSON('json', [
                $this->getBaseUrl($subject),
                [],
                JSON_UNESCAPED_UNICODE,
                $request,
                [],
                'GET',
            ]);


            return true;
        } catch (ClientException $exception) {

            return false;
        }


    }


    /**
     * 合并公众请求参数,并且签名
     *
     * @param        $params
     * @param string $httpMethod
     * @return string
     */
    private function mergePublicParamsAndSign($params, $httpMethod = 'GET')
    {
        $aliyunAccessKeySecret = env('ALIYUN_LOG_ACCESS_KEY');


        $params = array_merge([
            'Format'           => 'JSON',
            'RegionId'         => 'cn-hangzhou',
            'Version'          => '2016-08-01',
            'AccessKeyId'      => env('ALIYUN_LOG_ACCESS_KEY_ID'),
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => AppUtils::getRandomString(14),
            'SignatureVersion' => '1.0',
            'Timestamp'        => Carbon::createFromTimestampUTC(time())->format('Y-m-d\\TH:i:s\\Z'),
        ], $params);


        ksort($params, SORT_STRING);
        $stringToSign = http_build_query($params);
        $stringToSign = $httpMethod.'&'.urlencode('/').'&'.urlencode($stringToSign);
        $sign = base64_encode(hash_hmac('sha1', $stringToSign, $aliyunAccessKeySecret.'&', true));
        $params = array_merge($params, [
            'Signature' => $sign,
        ]);

        return http_build_query($params);
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
