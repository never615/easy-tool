<?php
namespace Mallto\Tool\Domain\Log;

use Encore\Admin\AppUtils;
use Mallto\Tool\Data\Log;


/**
 * Created by PhpStorm.
 * User: never615
 * Date: 12/01/2017
 * Time: 3:21 PM
 */
class LogUtils
{
    /**
     * 记录第三方接口通讯日志
     *
     * @param $code
     * @param $tag
     * @param $content
     * @return mixed
     */
    public static function addSystemLog($code, $tag, $content)
    {

        return Log::create([
            'code'       => $code,
            'tag'        => $tag,
            'content'    => $content,
            'subject_id' => AppUtils::getSubjectId(),
        ]);
    }
}
