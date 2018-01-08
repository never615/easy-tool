<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Encore\Admin\Auth\Database\Subject;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Request;
use Mallto\Tool\Exception\InvalidParamException;

/**
 * 工具类
 * Created by PhpStorm.
 * User: never615
 * Date: 05/11/2016
 * Time: 4:22 PM
 */
class SubjectUtils
{
    private static $subject;


    /**
     * 获取uuid
     *
     * @return mixed
     */
    public static function getUUID()
    {
        if (self::$subject) {
            return self::$subject->uuid;
        }

        $uuid = Request::header("UUID");
        if (is_null($uuid)) {
            $uuid = Input::get("uuid");
        }

        if (empty($uuid) && \Admin::user()) {
            $uuid = \Admin::user()->subject->uuid;
        }

        if (empty($uuid)) {
            throw new InvalidParamException("uuid参数错误");
        }

        return $uuid;
    }

    /**
     * 获取主体id
     *
     * @return mixed
     */
    public static function getSubjectId()
    {

        if (self::$subject) {
            return self::$subject->id;
        }

        try {
            $uuid = self::getUUID();
        } catch (InvalidParamException $e) {
            $uuid = null;
        }

        if (!is_null($uuid)) {
            $subject = Subject::where("uuid", $uuid)->first();
            if ($subject) {
                return $subject->id;
            }
        }

        $user = \Admin::user();
        if ($user) {
            $subject = $user->subject;
            if ($subject) {
                return $subject->id;
            }
        }

        throw new InvalidParamException("uuid参数错误".$uuid);
    }

    /**
     * 设置主体,测试用
     *
     * @param $subject
     */
    public static function setSubject($subject)
    {
        self::$subject = $subject;
    }


    /**
     * 获取主体
     *
     * @return Subject|null|static
     */
    public static function getSubject()
    {
        if (self::$subject) {
            return self::$subject;
        }

        try {
            $uuid = self::getUUID();
        } catch (InvalidParamException $e) {
            $uuid = null;
        }

        if (!is_null($uuid)) {
            $subject = Subject::where("uuid", $uuid)->first();
            if ($subject) {
                return $subject;
            }
        }

        $user = \Admin::user();
        if ($user) {
            $subject = $user->subject;
            if ($subject) {
                return $subject;
            }
        }

        throw new InvalidParamException("uuid参数错误:".$uuid);
    }
}