<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Illuminate\Http\UploadedFile;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/5/21
 * Time: 3:41 PM
 */
class FileUtils
{

    /**
     * create Illuminate\Http\UploadedFile
     *
     * @param      $url
     * @param bool $uniqid
     *
     * @return UploadedFile
     */
    public static function createFile($url, $uniqid = false)
    {
        if ($uniqid) {
            $fileName = md5(uniqid()) . md5($url);
        } else {
            $fileName = md5($url);
        }

        $contents = file_get_contents($url);
        $file = '/tmp/' . $fileName;
        file_put_contents($file, $contents);
        $uploaded_file = new UploadedFile($file, $fileName);

        return $uploaded_file;
    }
}
