<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * User: never615 <never615.com>
 * Date: 2020/11/14
 * Time: 11:51 上午
 */

namespace Mallto\Tool\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Data\AppSecretsPermission;
use Mallto\Tool\Exception\PermissionDeniedException;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Utils\AppUtils;
use Mallto\Tool\Utils\SignUtils;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

trait SignCheckTrait
{

    protected function check(Request $request, Closure $next)
    {
        if (!config('other.auth_sign')) {
            return $next($request);
        }

        $appId = $request->header("app_id");
        $appSecret = AppSecret::where("app_id", $appId)->first();

        if (!$appSecret || !$appSecret->switch) {
            Log::warning("app_id 无效:" . $appId);
            Log::warning($request->url());
            Log::warning($request->headers->get('referer'));
//            Log::warning($request->all());
            throw new SignException("app_id 无效");
        }

        if (is_null($appSecret->app_secret)) {
            throw new ResourceException("未设置秘钥");
        }

        $this->permissionCheck($request, $appSecret);

        $secret = $appSecret->app_secret;

        $inputs = $request->all();

        $signVersion = $request->header('signature_version');
        if ($signVersion === null) {
            $signVersion = $request->header('sign_version', "1");
        }

        switch ($signVersion) {
            case "999":  //用户测试环境,直接通过校验
                if (AppUtils::isProduction()) {
                    throw new PermissionDeniedException("无效的签名版本");
                } else {
                    return $next($request);
                }
                break;
//            case "1": //原始版本,只有签名校验
//                if (SignUtils::verifySign($inputs, $secret)) {
//                    //pass
//                    return $next($request);
//                } else {
//                    throw new SignException(trans("errors.sign_error"));
//                }
//                break;
//            case "2":  //签名校验+时间戳校验
//                $timestamp = $request->header("timestamp");
//                //时间戳格式检查
//                if ( ! Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
//                    throw new ResourceException("InvalidTimeStamp.Format");
//                }
//
//                if (Carbon::now()->subMinutes(15) < $timestamp) {
//                    //和当前时间间隔比较在15分钟内
//                    //检查签名
//                    if (SignUtils::verifySign2($inputs, $secret)) {
//                        //pass
//                        return $next($request);
//                    } else {
//                        throw new SignException(trans("errors.sign_error"));
//                    }
//                } else {
//                    throw new ResourceException("InvalidTimeStamp.Expired");
//                }
//                break;
//            case "3":  //签名校验+时间戳校验,请求头中的appid,uuid,和timestamp需要参与到签名中
//                $timestamp = $request->header("timestamp");
//                $uuid = $request->header("uuid");
//                $appId = $request->header("app_id");
//
//                if ( ! $timestamp | ! $uuid | ! $appId) {
//                    throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
//                }
//
//                //时间戳格式检查
//                if ( ! Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
//                    throw new ResourceException("InvalidTimeStamp.Format");
//                }
//
//                if (Carbon::now()->subMinutes(15) < $timestamp) {
//                    //和当前时间间隔比较在15分钟内
//                    //检查签名
//                    if (SignUtils::verifySign2(array_merge($inputs, [
//                        "timestamp" => $timestamp,
//                        "uuid"      => $uuid,
//                        "app_id"    => $appId,
//                    ]), $secret)) {
//                        //pass
//                        return $next($request);
//                    } else {
//                        throw new SignException(trans("errors.sign_error"));
//                    }
//                } else {
//                    throw new ResourceException("InvalidTimeStamp.Expired");
//                }
                break;
            case "4":  //签名校验+时间戳校验,请求头中的appid,uuid,和timestamp需要参与到签名中
                $timestamp = $request->header("timestamp");
                $uuid = $request->header("x_uuid") ?? $request->header("uuid");

                $uuidKey = $request->header("x_uuid") ? 'x_uuid' : 'uuid';

                $appId = $request->header("app_id");
                $signatureNonce = $request->header("signature_nonce");
                $signature = $request->header("signature");

                if (is_null($timestamp) || is_null($uuid) || is_null($appId) || is_null($signatureNonce) || is_null($signature)) {
                    throw new PreconditionRequiredHttpException('sign auth fail:' . trans("errors.precondition_request"));
                }

                //校验uuid是否属于开发者关联的subject或者他的子主体
                $this->subjectCheck($appSecret);

                //时间戳格式检查
                if (!Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
                    throw new ResourceException("InvalidTimeStamp.Format");
                }

                //随机字符串15分钟内是否使用过,同一个uuid,app_id,同一个接口,同一个随机字符串是否重复

                $requestPath = $request->path();

                $nonce = $uuid . $appId . $requestPath . $signatureNonce;
                if (Cache::get($nonce)) {
                    throw new ResourceException("请求已被接受,signature_nonce:" . $signatureNonce);
                }

                if (Carbon::now()->subMinutes(3) < $timestamp) {
                    //和当前时间间隔比较在15分钟内
                    //检查签名
                    if (SignUtils::verifySign4(array_merge($inputs, [
                        "timestamp" => $timestamp,
                        $uuidKey => $uuid,
                        "app_id" => $appId,
                        "signature_nonce" => $signatureNonce,
                        "signature" => $signature,
                    ]), $secret)) {
                        //pass
                        Cache::put($nonce, 1, 3 * 60);

                        return $next($request);
                    } else {
                        throw new SignException(trans("errors.sign_error"));
                    }
                } else {
                    throw new ResourceException("InvalidTimeStamp.Expired");
                }
                break;
            default:
                throw new PermissionDeniedException("无效的签名版本");
                break;
        }
    }


    public function subjectCheck($appSecret)
    {
        if ($appSecret->app_id == '999' && AppUtils::isTestEnv()) {
            return;
        }

        //获取开发者关联的主体
        $subjects = $appSecret->app_secret_subjects->toarray();
        if (empty($subjects)) {
            throw new ResourceException("该项目未绑定主体,请联系管理员");
        }
        //获取uuid对应的主体id
        $appSecretSubjectId = SubjectUtils::getSubjectId();
        if (!in_array($appSecretSubjectId, array_column($subjects, 'id'), true)) {
            throw new ResourceException("权限不足,请联系管理员");
        }
    }


    /**
     * 接口权限检查
     *
     * @param Request $request
     * @param         $appSecretUser
     *
     * @return bool
     */
    public function permissionCheck(Request $request, $appSecretUser)
    {
        //没有开启的校验的直接跳过
        if (!$appSecretUser->is_check_third_permission) {
            return true;
        }

        $routeName = $request->route()->getName();
        $routenameArr = explode('.', $routeName);

        if (count($routenameArr) == 2) {
            $subRouteName0 = $routenameArr[0];

            if (!AppSecretsPermission::query()->where('slug', $routeName)->exists()) {
                $routeName = $subRouteName0 . '.*';
            }

        }

        //做一下兼容，没有做权限的默认放行
        if (is_null($routeName)) {
            Log::error('第三方接口没有配置route name');
            Log::warning($request->url());

            return true;
        }

        //权限管理有该权限,检查开发者是否有该权限
        if ($appSecretUser->check($routeName)) {
            return true;
        }

        throw new AccessDeniedHttpException(trans("errors.permission_denied"));
    }

}
