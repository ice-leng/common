<?php

namespace Lengbin\Common\Component;

use Lengbin\Helper\YiiSoft\Arrays\ArrayHelper;
use Lengbin\Helper\YiiSoft\StringHelper;

/**
 * Class RedisCacheHelper
 * @package Lengbin\Helper\Util
 */
abstract class AbstractRedisCache
{
    use Singleton;

    /**
     * redis
     *
     * @return mixed
     */
    abstract public function getRedis();

    /**
     * config
     * @return array
     */
    abstract public function getConfig(): array;

    /**
     * 获得 时间
     *
     * @param int|null $ttl 过期时间， 如果为 0 表示 不设置过期时间， null 表示redis配置的过期时间
     *
     * @return int|null
     */
    protected function getRedisTtl(?int $ttl = null): ?int
    {
        if (is_null($ttl)) {
            $config = $this->getConfig();
            $ttl = ArrayHelper::get($config, 'ttl', 3600 * 2);
            [$min, $max] = ArrayHelper::get($config, 'random', [1, 60]);
            return $ttl + rand($min, $max);
        }

        if ($ttl === 0) {
            return null;
        }
        return $ttl;
    }

    /**
     * @param string      $key
     * @param string|null $prefix
     *
     * @return string
     */
    protected function getCacheKey(string $key, ?string $prefix = null): string
    {
        $mc = ArrayHelper::get($this->getConfig(), 'mc', 'app');
        if (!is_null($prefix)) {
            $key = $prefix . ':' . $key;
        }
        return sprintf('mc:%s:%s', $mc, $key);
    }

    /**
     * 通过key获得缓存
     *
     * @param string        $key
     * @param callable|null $call
     * @param string|null   $prefix
     * @param int|null      $ttl 过期时间， 如果为 0 表示 不设置过期时间， null 表示redis配置的过期时间
     *
     * @return mixed
     */
    public function getCacheByKey(string $key, ?callable $call = null, ?string $prefix = null, ?int $ttl = null)
    {
        // redis
        $redis = $this->getRedis();
        // get
        $k = $this->getCacheKey($key, $prefix);
        $data = $redis->get($k);
        if ($data) {
            return unserialize($data);
        }
        // call back
        if (!is_null($call)) {
            $ttl = $this->getRedisTtl($ttl);
            $data = call_user_func($call, $key);
        }
        if (StringHelper::isEmpty($data)) {
            $data = [];
            $ttl = 60;
        }
        $redis->set($k, serialize($data), $ttl);
        return $data;
    }

    /**
     * 获得 多缓存
     *
     * @param array         $keys
     * @param callable|null $call
     * @param string|null   $prefix
     * @param int|null      $ttl
     *
     * @return array
     */
    public function getCacheByKeys(array $keys, ?callable $call = null, ?string $prefix = null, ?int $ttl = null): array
    {
        if (count($keys) === 0) {
            return [];
        }

        $redis = $this->getRedis();

        $ks = [];
        foreach ($keys as $key) {
            $ks[] = $this->getCacheKey($key, $prefix);
        }

        $data = $redis->mGet($ks);

        $output = [];
        $missed = [];
        foreach ($data as $index => $item) {
            // 获得 未缓存 key
            if (StringHelper::isEmpty($item) || is_null($item)) {
                $key = $keys[$index];
                $missed[$index] = $key;
                continue;
            }
            $output[$index] = unserialize($item);
        }
        $models = [];
        if (!is_null($call)) {
            $models = call_user_func($call, $missed);
            $models = $models ?? [];
        }

        foreach ($models as $index => $model) {
            $output[$index] = $model;
            $targetIntersectKey = $this->getCacheKey($keys[$index], $prefix);
            $redis->set($targetIntersectKey, serialize($model), $ttl);
        }
        return $output;
    }

    /**
     * remove
     *
     * @param             $keys
     * @param string|null $prefix
     *
     * @return int
     */
    public function removeCacheByKey($keys, ?string $prefix = null): int
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }
        $ks = [];
        foreach ($keys as $key) {
            $ks[] = $this->getCacheKey($key, $prefix);
        }
        return $this->getRedis()->del($ks);
    }
}
