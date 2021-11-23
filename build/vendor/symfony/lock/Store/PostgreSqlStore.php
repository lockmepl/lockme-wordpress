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
use LockmeDep\Doctrine\DBAL\DriverManager;
use LockmeDep\Symfony\Component\Lock\BlockingSharedLockStoreInterface;
use LockmeDep\Symfony\Component\Lock\BlockingStoreInterface;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Key;
use LockmeDep\Symfony\Component\Lock\SharedLockStoreInterface;
/**
 * PostgreSqlStore is a PersistingStoreInterface implementation using
 * PostgreSql advisory locks.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class PostgreSqlStore implements \LockmeDep\Symfony\Component\Lock\BlockingSharedLockStoreInterface, \LockmeDep\Symfony\Component\Lock\BlockingStoreInterface
{
    private $conn;
    private $dsn;
    private $username = '';
    private $password = '';
    private $connectionOptions = [];
    private static $storeRegistry = [];
    /**
     * You can either pass an existing database connection as PDO instance or
     * a Doctrine DBAL Connection or a DSN string that will be used to
     * lazy-connect to the database when the lock is actually used.
     *
     * List of available options:
     *  * db_username: The username when lazy-connect [default: '']
     *  * db_password: The password when lazy-connect [default: '']
     *  * db_connection_options: An array of driver-specific connection options [default: []]
     *
     * @param \PDO|Connection|string $connOrDsn A \PDO or Connection instance or DSN string or null
     * @param array                  $options   An associative array of options
     *
     * @throws InvalidArgumentException When first argument is not PDO nor Connection nor string
     * @throws InvalidArgumentException When PDO error mode is not PDO::ERRMODE_EXCEPTION
     * @throws InvalidArgumentException When namespace contains invalid characters
     */
    public function __construct($connOrDsn, array $options = [])
    {
        if ($connOrDsn instanceof \PDO) {
            if (\PDO::ERRMODE_EXCEPTION !== $connOrDsn->getAttribute(\PDO::ATTR_ERRMODE)) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s" requires PDO error mode attribute be set to throw Exceptions (i.e. $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)).', __METHOD__));
            }
            $this->conn = $connOrDsn;
            $this->checkDriver();
        } elseif ($connOrDsn instanceof \LockmeDep\Doctrine\DBAL\Connection) {
            $this->conn = $connOrDsn;
            $this->checkDriver();
        } elseif (\is_string($connOrDsn)) {
            $this->dsn = $connOrDsn;
        } else {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s" requires PDO or Doctrine\\DBAL\\Connection instance or DSN string as first argument, "%s" given.', __CLASS__, \get_debug_type($connOrDsn)));
        }
        $this->username = $options['db_username'] ?? $this->username;
        $this->password = $options['db_password'] ?? $this->password;
        $this->connectionOptions = $options['db_connection_options'] ?? $this->connectionOptions;
    }
    public function save(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        // prevent concurrency within the same connection
        $this->getInternalStore()->save($key);
        $sql = 'SELECT pg_try_advisory_lock(:key)';
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->bindValue(':key', $this->getHashedKey($key));
        $result = $stmt->execute();
        // Check if lock is acquired
        if (\true === (\is_object($result) ? $result->fetchOne() : $stmt->fetchColumn())) {
            $key->markUnserializable();
            // release sharedLock in case of promotion
            $this->unlockShared($key);
            return;
        }
        throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
    }
    public function saveRead(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        // prevent concurrency within the same connection
        $this->getInternalStore()->saveRead($key);
        $sql = 'SELECT pg_try_advisory_lock_shared(:key)';
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->bindValue(':key', $this->getHashedKey($key));
        $result = $stmt->execute();
        // Check if lock is acquired
        if (\true === (\is_object($result) ? $result->fetchOne() : $stmt->fetchColumn())) {
            $key->markUnserializable();
            // release lock in case of demotion
            $this->unlock($key);
            return;
        }
        throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
    }
    public function putOffExpiration(\LockmeDep\Symfony\Component\Lock\Key $key, float $ttl)
    {
        // postgresql locks forever.
        // check if lock still exists
        if (!$this->exists($key)) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
        }
    }
    public function delete(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        // Prevent deleting locks own by an other key in the same connection
        if (!$this->exists($key)) {
            return;
        }
        $this->unlock($key);
        // Prevent deleting Readlocks own by current key AND an other key in the same connection
        $store = $this->getInternalStore();
        try {
            // If lock acquired = there is no other ReadLock
            $store->save($key);
            $this->unlockShared($key);
        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
            // an other key exists in this ReadLock
        }
        $store->delete($key);
    }
    public function exists(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $sql = "SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND objid=:key AND pid=pg_backend_pid()";
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->bindValue(':key', $this->getHashedKey($key));
        $result = $stmt->execute();
        if ((\is_object($result) ? $result->fetchOne() : $stmt->fetchColumn()) > 0) {
            // connection is locked, check for lock in internal store
            return $this->getInternalStore()->exists($key);
        }
        return \false;
    }
    public function waitAndSave(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        // prevent concurrency within the same connection
        // Internal store does not allow blocking mode, because there is no way to acquire one in a single process
        $this->getInternalStore()->save($key);
        $sql = 'SELECT pg_advisory_lock(:key)';
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->bindValue(':key', $this->getHashedKey($key));
        $stmt->execute();
        // release lock in case of promotion
        $this->unlockShared($key);
    }
    public function waitAndSaveRead(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        // prevent concurrency within the same connection
        // Internal store does not allow blocking mode, because there is no way to acquire one in a single process
        $this->getInternalStore()->saveRead($key);
        $sql = 'SELECT pg_advisory_lock_shared(:key)';
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->bindValue(':key', $this->getHashedKey($key));
        $stmt->execute();
        // release lock in case of demotion
        $this->unlock($key);
    }
    /**
     * Returns a hashed version of the key.
     */
    private function getHashedKey(\LockmeDep\Symfony\Component\Lock\Key $key) : int
    {
        return \crc32((string) $key);
    }
    private function unlock(\LockmeDep\Symfony\Component\Lock\Key $key) : void
    {
        while (\true) {
            $sql = "SELECT pg_advisory_unlock(objid::bigint) FROM pg_locks WHERE locktype='advisory' AND mode='ExclusiveLock' AND objid=:key AND pid=pg_backend_pid()";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':key', $this->getHashedKey($key));
            $result = $stmt->execute();
            if (0 === (\is_object($result) ? $result : $stmt)->rowCount()) {
                break;
            }
        }
    }
    private function unlockShared(\LockmeDep\Symfony\Component\Lock\Key $key) : void
    {
        while (\true) {
            $sql = "SELECT pg_advisory_unlock_shared(objid::bigint) FROM pg_locks WHERE locktype='advisory' AND mode='ShareLock' AND objid=:key AND pid=pg_backend_pid()";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':key', $this->getHashedKey($key));
            $result = $stmt->execute();
            if (0 === (\is_object($result) ? $result : $stmt)->rowCount()) {
                break;
            }
        }
    }
    /**
     * @return \PDO|Connection
     */
    private function getConnection() : object
    {
        if (null === $this->conn) {
            if (\strpos($this->dsn, '://')) {
                if (!\class_exists(\LockmeDep\Doctrine\DBAL\DriverManager::class)) {
                    throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('Failed to parse the DSN "%s". Try running "composer require doctrine/dbal".', $this->dsn));
                }
                $this->conn = \LockmeDep\Doctrine\DBAL\DriverManager::getConnection(['url' => $this->dsn]);
            } else {
                $this->conn = new \PDO($this->dsn, $this->username, $this->password, $this->connectionOptions);
                $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }
            $this->checkDriver();
        }
        return $this->conn;
    }
    private function checkDriver() : void
    {
        if ($this->conn instanceof \PDO) {
            if ('pgsql' !== ($driver = $this->conn->getAttribute(\PDO::ATTR_DRIVER_NAME))) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('The adapter "%s" does not support the "%s" driver.', __CLASS__, $driver));
            }
        } else {
            $driver = $this->conn->getDriver();
            switch (\true) {
                case $driver instanceof \LockmeDep\Doctrine\DBAL\Driver\PDOPgSql\Driver:
                case $driver instanceof \LockmeDep\Doctrine\DBAL\Driver\PDO\PgSQL\Driver:
                    break;
                default:
                    throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('The adapter "%s" does not support the "%s" driver.', __CLASS__, \get_class($driver)));
            }
        }
    }
    private function getInternalStore() : \LockmeDep\Symfony\Component\Lock\SharedLockStoreInterface
    {
        $namespace = \spl_object_hash($this->getConnection());
        return self::$storeRegistry[$namespace] ?? (self::$storeRegistry[$namespace] = new \LockmeDep\Symfony\Component\Lock\Store\InMemoryStore());
    }
}
