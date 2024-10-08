<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace LockmeDep\Symfony\Component\Lock;

use LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Exception\LockReleasingException;
/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
interface PersistingStoreInterface
{
    /**
     * Stores the resource if it's not locked by someone else.
     *
     * @return void
     *
     * @throws LockAcquiringException
     * @throws LockConflictedException
     */
    public function save(Key $key);
    /**
     * Removes a resource from the storage.
     *
     * @return void
     *
     * @throws LockReleasingException
     */
    public function delete(Key $key);
    /**
     * Returns whether or not the resource exists in the storage.
     */
    public function exists(Key $key): bool;
    /**
     * Extends the TTL of a resource.
     *
     * @param float $ttl amount of seconds to keep the lock in the store
     *
     * @return void
     *
     * @throws LockConflictedException
     */
    public function putOffExpiration(Key $key, float $ttl);
}
