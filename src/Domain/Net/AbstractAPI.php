<?php

namespace Mallto\Tool\Domain\Net;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mallto\Admin\Exception\NotSettingByProjectOwnerException;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Exception\InternalHttpException;
use Mallto\Tool\Exception\ThirdPartException;

/**
 * Class AbstractAPI.
 */
abstract class AbstractAPI extends API
{


    /**
     * 主体动态配置中配置的项目的key  SubjectConfig
     *
     * @var string
     */
    protected $SETTING_KEY_BASE_URL;


    /**
     * 请求的日志标识
     *
     * @var
     */
    protected $slug;

    /**
     * 默认的请求基地址
     *
     * @var
     */
    protected $baseUrl;


    /**
     * AbstractAPI constructor.
     */
    public function __construct()
    {
        if (!$this->slug) {
            Log::warning(new \Exception());
            throw new InternalHttpException('继承自AbstractAPI的类 没有设置slug');
        }
    }


    /**
     * 获取请求的基础url,url后已经拼接好/
     *
     * @param int $subjectId
     *
     * @return mixed
     */
    protected function getBaseUrl(int $subjectId)
    {
        if ($this->baseUrl) {
            return $this->baseUrl;
        }

        if (empty($this->SETTING_KEY_BASE_URL)) {
            throw new NotSettingByProjectOwnerException('未设置SETTING_KEY_BASE_URL');
        }

        $baseUrl = SubjectUtils::getDynamicKeyConfigByOwner($this->SETTING_KEY_BASE_URL, $subjectId);

        return $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }


    /**
     * @param mixed $baseUrl
     */
    protected function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }


    /**
     * Parse JSON from response and check error.
     *
     * @param string $method
     * @param array $args
     *
     * @return Collection
     */
    public function parseJSON($method, array $args)
    {
        $http = $this->getHttp();

        $contents = $http->parseJSON(call_user_func_array([$http, $method], $args));

        if (is_array($contents)) {
            $this->checkAndThrow($contents);
        }

        return new Collection($contents);
    }


    /**
     *
     *
     * 标准的json请求使用,即parseJSON()方法使用
     *
     * 检查响应,并返回
     *
     * 不同的实现需要重写此方法
     * Check the array data errors, and Throw exception when the contents contains error.
     *
     * @param array $contents
     *
     * @return array
     * @throws ThirdPartException
     */
    abstract protected function checkAndThrow(
        array $contents
    );


}
