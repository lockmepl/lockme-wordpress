<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace LockmeDep\Symfony\Component\Lock\Store;

use LockmeDep\Symfony\Component\Cache\Traits\RedisClusterProxy;
use LockmeDep\Symfony\Component\Cache\Traits\RedisProxy;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidTtlException;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Key;
use LockmeDep\Symfony\Component\Lock\PersistingStoreInterface;
/**
 * RedisStore is a PersistingStoreInterface implementation using Redis as store engine.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class RedisStore implements \LockmeDep\Symfony\Component\Lock\PersistingStoreInterface
{
    use ExpiringStoreTrait;
    private $redis;
    private $initialTtl;
    /**
     * @param \Redis|\RedisArray|\RedisCluster|RedisProxy|RedisClusterProxy|\Predis\ClientInterface $redisClient
     * @param float                                                                                 $initialTtl  the expiration delay of locks in seconds
     */
    public function __construct($redisClient, float $initialTtl = 300.0)
    {
        if (!$redisClient instanceof \Redis && !$redisClient instanceof \RedisArray && !$redisClient instanceof \RedisCluster && !$redisClient instanceof \LockmeDep\Predis\ClientInterface && !$redisClient instanceof \LockmeDep\Symfony\Component\Cache\Traits\RedisProxy && !$redisClient instanceof \LockmeDep\Symfony\Component\Cache\Traits\RedisClusterProxy) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s()" expects parameter 1 to be Redis, RedisArray, RedisCluster, RedisProxy, RedisClusterProxy or Predis\\ClientInterface, "%s" given.', __METHOD__, \get_debug_type($redisClient)));
        }
        if ($initialTtl <= 0) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidTtlException(\sprintf('"%s()" expects a strictly positive TTL. Got %d.', __METHOD__, $initialTtl));
        }
        $this->redis = $redisClient;
        $this->initialTtl = $initialTtl;
    }
    /**
     * {@inheritdoc}
     */
    public function save(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("PEXPIRE", KEYS[1], ARGV[2])
            elseif redis.call("SET", KEYS[1], ARGV[1], "NX", "PX", ARGV[2]) then
                return 1
            else
                return 0
            end
        ';
        $key->reduceLifetime($this->initialTtl);
        if (!$this->evaluate($script, (string) $key, [$this->getUniqueToken($key), (int) \ceil($this->initialTtl * 1000)])) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
        }
        $this->checkNotExpired($key);
    }
    /**
     * {@inheritdoc}
     */
    public function putOffExpiration(\LockmeDep\Symfony\Component\Lock\Key $key, float $ttl)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("PEXPIRE", KEYS[1], ARGV[2])
            else
                return 0
            end
        ';
        $key->reduceLifetime($ttl);
        if (!$this->evaluate($script, (string) $key, [$this->getUniqueToken($key), (int) \ceil($ttl * 1000)])) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
        }
        $this->checkNotExpired($key);
    }
    /**
     * {@inheritdoc}
     */
    public function delete(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';
        $this->evaluate($script, (string) $key, [$this->getUniqueToken($key)]);
    }
    /**
     * {@inheritdoc}
     */
    public function exists(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        return $this->redis->get((string) $key) === $this->getUniqueToken($key);
    }
    /**
     * Evaluates a script in the corresponding redis client.
     *
     * @return mixed
     */
    private function evaluate(string $script, string $resource, array $args)
    {
        if ($this->redis instanceof \Redis || $this->redis instanceof \RedisCluster || $this->redis instanceof \LockmeDep\Symfony\Component\Cache\Traits\RedisProxy || $this->redis instanceof \LockmeDep\Symfony\Component\Cache\Traits\RedisClusterProxy) {
            return $this->redis->eval($script, \array_merge([$resource], $args), 1);
        }
        if ($this->redis instanceof \RedisArray) {
            return $this->redis->_instance($this->redis->_target($resource))->eval($script, \array_merge([$resource], $args), 1);
        }
        if ($this->redis instanceof \LockmeDep\Predis\ClientInterface) {
            return $this->redis->eval(...\array_merge([$script, 1, $resource], $args));
        }
        throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s()" expects being initialized with a Redis, RedisArray, RedisCluster or Predis\\ClientInterface, "%s" given.', __METHOD__, \get_debug_type($this->redis)));
    }
    private function getUniqueToken(\LockmeDep\Symfony\Component\Lock\Key $key) : string
    {
        if (!$key->hasState(__CLASS__)) {
            $token = \base64_encode(\random_bytes(32));
            $key->setState(__CLASS__, $token);
        }
        return $key->getState(__CLASS__);
    }
}
