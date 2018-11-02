<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use App\Http\Controllers\Controller;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Response\QrCodeResponse;
use Illuminate\Http\Request;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/2
 * Time: 下午5:05
 */
class QrController extends Controller
{

    public function index(Request $request)
    {
        $data = $request->get("data");

        $size = $request->get("size");


        $qrCode = new QrCode($data);
        try {
            if ($size) {
                $sizes = explode("x", $size);
                $qrCode->setLogoSize($sizes[0], $sizes[1]);

            }
        } catch (\Exception $exception) {
            \Log::error("二维码尺寸设置失败");
        }

        header('Content-Type: '.$qrCode->getContentType());

        return $response = new QrCodeResponse($qrCode);
    }
}