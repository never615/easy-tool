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

class WebviewParamController extends Controller
{
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
