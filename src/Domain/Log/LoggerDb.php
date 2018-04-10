<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Log;

use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Log;


/**
 * Created by PhpStorm.
 * User: never615
 * Date: 12/01/2017
 * Time: 3:21 PM
 */
class LoggerDb implements Logger
{

    /**
     * 记录第三方接口通讯日志
     *
     * @param $tag
     * @param $action
     * @param $content
     * @return
     */
    public function logThirdPart($tag, $action, $content)
    {
        return Log::create([
            'code'       => $tag,
            'tag'        => $action,
            'content'    => $content,
            'subject_id' => SubjectUtils::getSubjectId(),
        ]);
    }



    /**
     * 记录管理端的操作日志
     *
     * @param  array $log
     * @return mixed
     */
    public function logAdminOperation($log)
    {
        // TODO: Implement logAdminOperation() method.
    }


    /**
     * 记录自己api的通讯日志
     *
     * @param $action
     * @param $content
     * @return
     */
    public function logOwnerApi( $action, $content)
    {
        // TODO: Implement logOwnerApi() method.
    }
}
