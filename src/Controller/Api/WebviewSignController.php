<?php
/**
 * Copyright (c) 2025. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Utils\SignUtils;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

class WebviewSignController extends \App\Http\Controllers\Controller
{
    /**
     * 校验前端 WebView URL 的签名与时间有效性
     */
    public function verify(Request $request): \Illuminate\Http\Response
    {
        $this->validate($request, [
            'page_url' => 'required|string',
            'timestamp' => 'required',
            'signature' => 'required|string',
        ]);

        $pageUrl = $request->input('page_url');
        $timestamp = $request->input('timestamp');
        $signature = $request->input('signature');
        $appId = $request->input('app_id');


        // 从请求头中读取项目 UUID（SubjectUtils 内部从请求头中读取 uuid）
        $uuid = SubjectUtils::getUUID();
        if (empty($uuid)) {
            throw new PreconditionRequiredHttpException('Missing uuid in headers');
        } else {
            $appSecret = AppSecret::query()
                ->where('app_id', $appId)
                ->first();
            if (!$appSecret || !$appSecret->switch) {
                throw new SignException('app_id is invalid');
            }

            if (is_null($appSecret->app_secret)) {
                throw new SignException('App secret is not set');
            }

            // 1) 签名校验（使用 SignUtils v4）
            $arr = [
                'app_id' => $appId,
                'uuid' => $uuid,
                'page_url' => $pageUrl,
                'timestamp' => $timestamp,
                'signature' => $signature
            ];

//            Log::debug($arr);
//            Log::debug($appSecret->app_secret);

            $isValid = SignUtils::verifySign4($arr, $appSecret->app_secret);
            if (!$isValid) {
                throw new SignException("Invalid signature");
            } else {
                // 2) 时间戳校验：必须在 24 小时内有效
                //时间戳格式检查
                if (!Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
                    throw new PreconditionRequiredHttpException("InvalidTimeStamp.Format");
                }
                try {
                    if (is_numeric($timestamp)) {
                        $signedAt = Carbon::createFromTimestamp((int)$timestamp);
                    } else {
                        $signedAt = Carbon::parse($timestamp);
                    }

                    if ($signedAt->lt(Carbon::now()->subHours(24))) {
                        throw new SignException('Timestamp expired');
                    }
                } catch (\Throwable $e) {
                    Log::warning('Timestamp parsing error: ' . $e->getMessage());
                    throw new SignException('Invalid timestamp format');
                }
            }
        }

        return response()->noContent();
    }
}
