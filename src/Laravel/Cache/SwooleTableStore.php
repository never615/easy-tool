<?php
/**
 * 版权所有 (c) 2020. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Laravel\Cache;

use Illuminate\Contracts\Cache\Store;

/**
 * 作者： never615 <never615.com>
 * 日期： 2020/12/29
 * 时间： 10:42 下午
 */
class SwooleTableStore implements Store
{

    /**
     * 需要添加到键名前的字符串。
     *
     * @var string
     */
    protected $prefix;

    protected $cacheTable;

    //protected $bcacheTable;


    /**
     * SwooleTableStore 构造函数。
     */
    public function __construct()
    {
        $this->cacheTable = null;
        if ((bool) config('laravels.swoole_table_enable', false)) {
            try {
                $this->cacheTable = app('swoole')->cacheTable ?? null;
            } catch (\Throwable) {
                // 全局关闭或非 LaravelS 进程中保持不可用，调用方回退共享 Redis。
            }
        }
        //$this->bcacheTable = app('swoole')->bcacheTable;
    }


    /**
     * 按键读取缓存项。
     *
     * @param string|array $key
     *
     * @return mixed
     */
    public function get($key)
    {
        if (!$this->cacheTable) {
            return null;
        }

        $value = $this->cacheTable->get($key)['value'] ?? null;

        return ! is_null($value) ? $this->unserialize($value) : null;
    }


    //public function getBig($key)
    //{
    //    $value = $this->bcacheTable->get($key)['value'] ?? null;
    //
    //    return ! is_null($value) ? $this->unserialize($value) : null;
    //}

    /**
     * 按键批量读取缓存项。
     *
     * 未在缓存中找到的项返回 null。
     *
     * @param array $keys
     *
     * @return array
     */
    public function many(array $keys)
    {
        throw new \Exception('not support method');
    }


    /**
     * 将缓存项保存指定秒数。
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds
     *
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        return $this->forever($key, $value);
    }


    /**
     * 将多个缓存项保存指定秒数。
     *
     * @param array $values
     * @param int   $seconds
     *
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        throw new \Exception('not support method');
    }


    /**
     * 递增缓存项的值。
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        if (!$this->cacheTable) {
            return false;
        }

        try {
            return $this->cacheTable->incr($key, 'value', $value);
        } catch (\Throwable) {
            return false;
        }
    }


    /**
     * 递减缓存项的值。
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        if (!$this->cacheTable) {
            return false;
        }

        try {
            return $this->cacheTable->decr($key, 'value', $value);
        } catch (\Throwable) {
            return false;
        }
    }


    /**
     * 永久保存缓存项。
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return bool
     */
    public function forever($key, $value)
    {
        if (!$this->cacheTable) {
            return false;
        }

        try {
            return $this->cacheTable->set($key, [ 'value' => $this->serialize($value) ]);
        } catch (\Throwable) {
            // 固定容量 Table 写满时只放弃 L1，不向业务抛出 ErrorException。
            return false;
        }
    }


    /**
     * 移除指定缓存项。
     *
     * @param string $key
     *
     * @return bool
     */
    public function forget($key)
    {
        return $this->cacheTable ? $this->cacheTable->del($key) : false;
    }


    /**
     * 移除全部缓存项。
     *
     * @return bool
     */
    public function flush()
    {
        throw new \Exception('not support method');
    }


    /**
     * @param $key
     *
     * @return bool
     */
    public function exist($key)
    {
        return $this->cacheTable ? $this->cacheTable->exist($key) : false;
    }


    /**
     * @return int
     */
    public function count()
    {
        return $this->cacheTable ? $this->cacheTable->count() : 0;
    }


    /**
     * 获取缓存键前缀。
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }


    /**
     * 设置缓存键前缀。
     *
     * @param string $prefix
     *
     * @return void
     */
    public function setPrefix($prefix)
    {
        $this->prefix = ! empty($prefix) ? $prefix . ':' : '';
    }


    /**
     * 序列化值。
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function serialize($value)
    {
        return is_numeric($value) && ! in_array($value,
            [ INF, -INF ]) && ! is_nan($value) ? $value : serialize($value);
    }


    /**
     * 反序列化值。
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function unserialize($value)
    {
        return is_numeric($value) ? $value : unserialize($value);
    }

}
