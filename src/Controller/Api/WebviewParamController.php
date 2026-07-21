<?php
/**
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Domain\Webview\WebviewParamStore;
use Mallto\Tool\Exception\NotFoundException;

/**
 * WebviewParamController：处理 webview param controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class WebviewParamController extends Controller
{
    /**
     * 处理「store」相关的内部协作步骤。
     */
    public function store(Request $request, WebviewParamStore $store)
    {
        $this->validate($request, [
            'params' => 'required|array',
        ]);

        return response()->json($store->store(
            (string)SubjectUtils::getUUID(),
            $request->input('params')
        ));
    }

    /**
     * 处理「resolve」相关的内部协作步骤。
     */
    public function resolve(Request $request, WebviewParamStore $store)
    {
        $this->validate($request, [
            'id' => [ 'required', 'string', 'max:80', 'regex:/^[A-Za-z0-9]+$/' ],
        ]);

        $params = $store->resolve((string)SubjectUtils::getUUID(), $request->input('id'));
        if (is_null($params)) {
            throw new NotFoundException('WebView 参数不存在或已过期');
        }

        return response()->json([
            'params' => $params,
        ]);
    }
}
