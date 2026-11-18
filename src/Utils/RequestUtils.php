<?php
/**
 * 版权所有 (c) 2021. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * 作者： never615 <never615.com>
 * 日期： 2021/1/15
 * 时间： 3:21 上午
 */
class RequestUtils
{

    /**
     * 获取请求中的需求的语言
     *
     * @return array|mixed|string
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function getLan(): mixed
    {
        $request = request();
        if ($request) {
            $language = $request->header('language', $request->get('language'));

            // 可用的语言版本
            $availableLanguages = ['en', 'tc'];

            if (!in_array($language, $availableLanguages, true)) {
                return null;
            }

            return $language;
        } else {
            return null;
        }
    }
}
