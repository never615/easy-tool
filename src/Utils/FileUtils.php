<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

use Illuminate\Http\UploadedFile;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2019/5/21
 * 时间： 3:41 下午
 */
class FileUtils
{

    /**
     * 创建 Illuminate\Http\UploadedFile
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
