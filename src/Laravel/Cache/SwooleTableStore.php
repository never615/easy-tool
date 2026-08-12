<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Laravel\Cache;

use Illuminate\Contracts\Cache\Store;

/**
 * User: never615 <never615.com>
 * Date: 2020/12/29
 * Time: 10:42 下午
 */
class SwooleTableStore implements Store
{

    /**
     * A string that should be prepended to keys.
     *
     * @var string
     */
    protected $prefix;

    protected $cacheTable;

    //protected $bcacheTable;


    /**
     * SwooleTableStore constructor.
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
     * Retrieve an item from the cache by key.
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
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
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
     * Store an item in the cache for a given number of seconds.
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
     * Store multiple items in the cache for a given number of seconds.
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
     * Increment the value of an item in the cache.
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
     * Decrement the value of an item in the cache.
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
     * Store an item in the cache indefinitely.
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
     * Remove an item from the cache.
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
     * Remove all items from the cache.
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
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }


    /**
     * Set the cache key prefix.
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
     * Serialize the value.
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
     * Unserialize the value.
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
