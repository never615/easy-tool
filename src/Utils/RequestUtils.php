<?php
/**
 * Copyright (c) 2021. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * User: never615 <never615.com>
 * Date: 2021/1/15
 * Time: 3:21 上午
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
