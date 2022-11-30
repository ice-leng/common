<?php

declare(strict_types=1);

namespace Lengbin\Common;

use redis;
use Swoole\Coroutine;

abstract class AbstractRedisLock
{
    /**
     * @var int / microsecond
     */
    private int $retryDelay = 200;

    private int $retryCount = 2;

    private float $clockDriftFactor = 0.01;

    /**
     * @var redis[]
     */
    private array $instances = [];

    private $quorum;

    /**
     * @param array $instances
     * @return self
     */
    public function setInstances(array $instances): static
    {
        $this->instances = $instances;
        return $this;
    }

    /**
     * @param mixed $quorum
     * @return self
     */
    public function setQuorum($quorum): static
    {
        $this->quorum = $quorum;
        return $this;
    }

    public function getInstances(): array
    {
        return $this->instances;
    }

    /**
     * @param int $retryDelay / microsecond
     * @return $this
     */
    public function setRetryDelay(int $retryDelay = 200): static
    {
        $this->retryDelay = $retryDelay;
        return $this;
    }

    public function setRetryCount(int $retryCount = 2): static
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function setClockDriftFactor(float $clockDriftFactor = 0.01): static
    {
        $this->clockDriftFactor = $clockDriftFactor;
        return $this;
    }


    /**
     * @param $resource
     * @param $ttl / millisecond
     * @return array|false
     */
    public function lock($resource, int $ttl)
    {
        $token = uniqid(gethostname(), true);
        $retry = $this->retryCount;

        do {
            $n = 0;

            $startTime = microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    ++$n;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;

            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token' => $token,
                ];
            }

            //get lock failure unlock all instance
            foreach ($this->instances as $instance) {
                $this->unlockInstance($instance, $resource, $token);
            }

            // Wait a random delay before to retry
            $delay = mt_rand((int)floor($this->retryDelay / 2), $this->retryDelay);

            --$retry;
        } while ($retry > 0 && Coroutine::sleep($delay / 1000));

        return false;
    }

    /**
     * @param array $lock
     */
    public function unlock(array $lock)
    {
        $resource = $lock['resource'];
        $token = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    private function lockInstance($instance, $resource, $token, $ttl)
    {
        return $instance->set($resource, $token, ['NX', 'PX' => $ttl]);
    }

    private function unlockInstance($instance, $resource, $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        return $instance->eval($script, [$resource, $token], 1);
    }
}