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
            'subject_id' => AppUtils::getSubjectId(),
        ]);
    }

    /**
     * 记录自己api的通讯日志
     *
     * @return mixed
     */
    public function logOwnerApi()
    {
        // TODO: Implement logOwnerApi() method.
    }

    /**
     * 记录管理端的操作日志
     *
     * @return mixed
     */
    public function logAdminOperation()
    {
        // TODO: Implement logAdminOperation() method.
    }
}
