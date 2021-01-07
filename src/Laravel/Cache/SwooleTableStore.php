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
        $this->cacheTable = app('swoole')->cacheTable;
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
        return app('swoole')->cacheTable
            ->incr($key, 'value', $value);
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
        return app('swoole')->cacheTable
            ->decr($key, 'value', $value);
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

        return $this->cacheTable->set($key, [ 'value' => $this->serialize($value) ]);
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
        return $this->cacheTable->del($key);
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
        return $this->cacheTable->exist($key);
    }


    /**
     * @return int
     */
    public function count()
    {
        return $this->cacheTable->count();
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
