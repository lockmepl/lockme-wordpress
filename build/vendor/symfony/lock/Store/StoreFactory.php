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
            case \str_starts_with($connection, 'flock://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\FlockStore(\substr($connection, 8));
            case 'semaphore' === $connection:
                return new \LockmeDep\Symfony\Component\Lock\Store\SemaphoreStore();
            case \str_starts_with($connection, 'redis:'):
            case \str_starts_with($connection, 'rediss:'):
            case \str_starts_with($connection, 'memcached:'):
                if (!\class_exists(\LockmeDep\Symfony\Component\Cache\Adapter\AbstractAdapter::class)) {
                    throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('Unsupported DSN "%s". Try running "composer require symfony/cache".', $connection));
                }
                $storeClass = \str_starts_with($connection, 'memcached:') ? \LockmeDep\Symfony\Component\Lock\Store\MemcachedStore::class : \LockmeDep\Symfony\Component\Lock\Store\RedisStore::class;
                $connection = \LockmeDep\Symfony\Component\Cache\Adapter\AbstractAdapter::createConnection($connection, ['lazy' => \true]);
                return new $storeClass($connection);
            case \str_starts_with($connection, 'mongodb'):
                return new \LockmeDep\Symfony\Component\Lock\Store\MongoDbStore($connection);
            case \str_starts_with($connection, 'mssql://'):
            case \str_starts_with($connection, 'mysql:'):
            case \str_starts_with($connection, 'mysql2://'):
            case \str_starts_with($connection, 'oci:'):
            case \str_starts_with($connection, 'oci8://'):
            case \str_starts_with($connection, 'pdo_oci://'):
            case \str_starts_with($connection, 'pgsql:'):
            case \str_starts_with($connection, 'postgres://'):
            case \str_starts_with($connection, 'postgresql://'):
            case \str_starts_with($connection, 'sqlsrv:'):
            case \str_starts_with($connection, 'sqlite:'):
            case \str_starts_with($connection, 'sqlite3://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\PdoStore($connection);
            case \str_starts_with($connection, 'pgsql+advisory:'):
            case \str_starts_with($connection, 'postgres+advisory://'):
            case \str_starts_with($connection, 'postgresql+advisory://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\PostgreSqlStore(\preg_replace('/^([^:+]+)\\+advisory/', '$1', $connection));
            case \str_starts_with($connection, 'zookeeper://'):
                return new \LockmeDep\Symfony\Component\Lock\Store\ZookeeperStore(\LockmeDep\Symfony\Component\Lock\Store\ZookeeperStore::createConnection($connection));
            case 'in-memory' === $connection:
                return new \LockmeDep\Symfony\Component\Lock\Store\InMemoryStore();
        }
        throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('Unsupported Connection: "%s".', $connection));
    }
}
