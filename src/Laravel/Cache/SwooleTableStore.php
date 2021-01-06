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
     * Retrieve an item from the cache by key.
     *
     * @param string|array $key
     *
     * @return mixed
     */
    public function get($key)
    {
        $value = app('swoole')->cacheTable->get($key)['value'] ?? null;
        if (starts_with($value, 'array:')) {
            $value = ltrim($value, 'array:');
            $value = json_decode($value, true);
        }

        return $value;
    }


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
        if (is_array($value)) {
            $value = json_encode($value);
            $value = 'array:' . $value;
        }
        app('swoole')->cacheTable->set($key, [ 'value' => $value ]);
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
        throw new \Exception('not support method');
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
        throw new \Exception('not support method');
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
        if (is_array($value)) {
            $value = json_encode($value);
            $value = 'array:' . $value;
        }
        app('swoole')->cacheTable->set($key, [ 'value' => $value ]);
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
        throw new \Exception('not support method');
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
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        throw new \Exception('not support method');
    }
}
