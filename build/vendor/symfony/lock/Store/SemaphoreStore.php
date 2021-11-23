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

use LockmeDep\Symfony\Component\Lock\BlockingStoreInterface;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Key;
/**
 * SemaphoreStore is a PersistingStoreInterface implementation using Semaphore as store engine.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class SemaphoreStore implements \LockmeDep\Symfony\Component\Lock\BlockingStoreInterface
{
    /**
     * Returns whether or not the store is supported.
     *
     * @internal
     */
    public static function isSupported() : bool
    {
        return \extension_loaded('sysvsem');
    }
    public function __construct()
    {
        if (!static::isSupported()) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException('Semaphore extension (sysvsem) is required.');
        }
    }
    /**
     * {@inheritdoc}
     */
    public function save(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->lock($key, \false);
    }
    /**
     * {@inheritdoc}
     */
    public function waitAndSave(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->lock($key, \true);
    }
    private function lock(\LockmeDep\Symfony\Component\Lock\Key $key, bool $blocking)
    {
        if ($key->hasState(__CLASS__)) {
            return;
        }
        $keyId = \crc32($key);
        $resource = \sem_get($keyId);
        $acquired = @\sem_acquire($resource, !$blocking);
        while ($blocking && !$acquired) {
            $resource = \sem_get($keyId);
            $acquired = @\sem_acquire($resource);
        }
        if (!$acquired) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
        }
        $key->setState(__CLASS__, $resource);
        $key->markUnserializable();
    }
    /**
     * {@inheritdoc}
     */
    public function delete(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        // The lock is maybe not acquired.
        if (!$key->hasState(__CLASS__)) {
            return;
        }
        $resource = $key->getState(__CLASS__);
        \sem_remove($resource);
        $key->removeState(__CLASS__);
    }
    /**
     * {@inheritdoc}
     */
    public function putOffExpiration(\LockmeDep\Symfony\Component\Lock\Key $key, float $ttl)
    {
        // do nothing, the semaphore locks forever.
    }
    /**
     * {@inheritdoc}
     */
    public function exists(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        return $key->hasState(__CLASS__);
    }
}
