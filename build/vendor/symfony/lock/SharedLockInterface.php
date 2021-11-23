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
/**
 * SharedLockInterface defines an interface to manipulate the status of a shared lock.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
interface SharedLockInterface extends \LockmeDep\Symfony\Component\Lock\LockInterface
{
    /**
     * Acquires the lock for reading. If the lock is acquired by someone else in write mode, the parameter `blocking`
     * determines whether or not the call should block until the release of the lock.
     *
     * @return bool whether or not the lock had been acquired
     *
     * @throws LockConflictedException If the lock is acquired by someone else in blocking mode
     * @throws LockAcquiringException  If the lock can not be acquired
     */
    public function acquireRead(bool $blocking = \false);
}
