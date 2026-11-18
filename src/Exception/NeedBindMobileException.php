<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 12/07/2017
 * 时间： 3:03 下午
 */

namespace Mallto\Tool\Exception;

/**
 * NeedBindMobileException：用于表达 need bind mobile exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class NeedBindMobileException extends HttpException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        $message = "需要绑定手机",
        $statusCode = "422"
    ) {
        parent::__construct($statusCode, $message, 4105);
    }
}
