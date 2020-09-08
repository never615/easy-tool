<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * Class XmlUtils
 *
 * @package Mallto\Tool\Utils
 */
class XmlUtils
{

    /**
     * 数组转xml字符
     *
     * @param $array
     *
     * @return bool|string
     */
    public static function arrayToXml($array)
    {
        if ( ! is_array($array) || count($array) <= 0) {
            return false;
        }

        $xml = "<xml>";
        foreach ($array as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }

        $xml .= "</xml>";

        return $xml;
    }


    /**
     * xml转array
     *
     * @param $xml
     *
     * @return array
     */
    public static function xmlToArray($xml)
    {
        $object = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        return get_object_vars($object);
    }
}
