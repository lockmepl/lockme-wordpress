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

use LockmeDep\Doctrine\DBAL\Connection;
use LockmeDep\Symfony\Component\Cache\Adapter\AbstractAdapter;
use LockmeDep\Symfony\Component\Cache\Traits\RedisClusterProxy;
use LockmeDep\Symfony\Component\Cache\Traits\RedisProxy;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException;
use LockmeDep\Symfony\Component\Lock\PersistingStoreInterface;
/**
 * StoreFactory create stores and connections.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class StoreFactory
{
    /**
     * @param \Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|RedisProxy|RedisClusterProxy|\Memcached|\MongoDB\Collection|\PDO|Connection|\Zookeeper|string $connection Connection or DSN or Store short name
     *
     * @return PersistingStoreInterface
     */
    public static function createStore($connection)
    {
        if (!\is_string($connection) && !\is_object($connection)) {
            throw new \TypeError(\sprintf('Argument 1 passed to "%s()" must be a string or a connection object, "%s" given.', __METHOD__, \get_debug_type($connection)));
        }
        switch (\true) {
            case $connection instanceof \Redis:
            case $connection instanceof \RedisArray:
            case $connection instanceof \RedisCluster:
            case $connection instanceof \LockmeDep\Predis\ClientInterface:
            case $connection instanceof \LockmeDep\Symfony\Component\Cache\Traits\RedisProxy:
            case $connection instanceof \LockmeDep\Symfony\Component\Cache\Traits\RedisClusterProxy:
                return new \LockmeDep\Symfony\Component\Lock\Store\RedisStore($connection);
            case $connection instanceof \Memcached:
                return new \LockmeDep\Symfony\Component\Lock\Store\MemcachedStore($connection);
            case $connection instanceof \LockmeDep\MongoDB\Collection:
                return new \LockmeDep\Symfony\Component\Lock\Store\MongoDbStore($connection);
            case $connection instanceof \PDO:
            case $connection instanceof \LockmeDep\Doctrine\DBAL\Connection:
                return new \LockmeDep\Symfony\Component\Lock\Store\PdoStore($connection);
            case $connection instanceof \Zookeeper:
                return new \LockmeDep\Symfony\Component\Lock\Store\ZookeeperStore($connection);
            case !\is_string($connection):
                throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('Unsupported Connection: "%s".', \get_debug_type($connection)));
            case 'flock' === $connection:
                return new \LockmeDep\Symfony\Component\Lock\Store\FlockStore();
            case 0 === \strpos($connection, 'flock://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\FlockStore(\substr($connection, 8));
            case 'semaphore' === $connection:
                return new \LockmeDep\Symfony\Component\Lock\Store\SemaphoreStore();
            case 0 === \strpos($connection, 'redis:'):
            case 0 === \strpos($connection, 'rediss:'):
            case 0 === \strpos($connection, 'memcached:'):
                if (!\class_exists(\LockmeDep\Symfony\Component\Cache\Adapter\AbstractAdapter::class)) {
                    throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('Unsupported DSN "%s". Try running "composer require symfony/cache".', $connection));
                }
                $storeClass = 0 === \strpos($connection, 'memcached:') ? \LockmeDep\Symfony\Component\Lock\Store\MemcachedStore::class : \LockmeDep\Symfony\Component\Lock\Store\RedisStore::class;
                $connection = \LockmeDep\Symfony\Component\Cache\Adapter\AbstractAdapter::createConnection($connection, ['lazy' => \true]);
                return new $storeClass($connection);
            case 0 === \strpos($connection, 'mongodb'):
                return new \LockmeDep\Symfony\Component\Lock\Store\MongoDbStore($connection);
            case 0 === \strpos($connection, 'mssql://'):
            case 0 === \strpos($connection, 'mysql:'):
            case 0 === \strpos($connection, 'mysql2://'):
            case 0 === \strpos($connection, 'oci:'):
            case 0 === \strpos($connection, 'oci8://'):
            case 0 === \strpos($connection, 'pdo_oci://'):
            case 0 === \strpos($connection, 'pgsql:'):
            case 0 === \strpos($connection, 'postgres://'):
            case 0 === \strpos($connection, 'postgresql://'):
            case 0 === \strpos($connection, 'sqlsrv:'):
            case 0 === \strpos($connection, 'sqlite:'):
            case 0 === \strpos($connection, 'sqlite3://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\PdoStore($connection);
            case 0 === \strpos($connection, 'pgsql+advisory:'):
            case 0 === \strpos($connection, 'postgres+advisory://'):
            case 0 === \strpos($connection, 'postgresql+advisory://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\PostgreSqlStore(\preg_replace('/^([^:+]+)\\+advisory/', '$1', $connection));
            case 0 === \strpos($connection, 'zookeeper://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\ZookeeperStore(\LockmeDep\Symfony\Component\Lock\Store\ZookeeperStore::createConnection($connection));
            case 'in-memory' === $connection:
                return new \LockmeDep\Symfony\Component\Lock\Store\InMemoryStore();
        }
        throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('Unsupported Connection: "%s".', $connection));
    }
}
