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

use LockmeDep\Psr\Log\LoggerAwareInterface;
use LockmeDep\Psr\Log\LoggerAwareTrait;
/**
 * Factory provides method to create locks.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 * @author Hamza Amrouche <hamza.simperfit@gmail.com>
 */
class LockFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(private PersistingStoreInterface $store)
    {
    }
    /**
     * Creates a lock for the given resource.
     *
     * @param string     $resource    The resource to lock
     * @param float|null $ttl         Maximum expected lock duration in seconds
     * @param bool       $autoRelease Whether to automatically release the lock or not when the lock instance is destroyed
     *
     * @return SharedLockInterface
     */
    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = \true): LockInterface
    {
        return $this->createLockFromKey(new Key($resource), $ttl, $autoRelease);
    }
    /**
     * Creates a lock from the given key.
     *
     * @param Key        $key         The key containing the lock's state
     * @param float|null $ttl         Maximum expected lock duration in seconds
     * @param bool       $autoRelease Whether to automatically release the lock or not when the lock instance is destroyed
     *
     * @return SharedLockInterface
     */
    public function createLockFromKey(Key $key, ?float $ttl = 300.0, bool $autoRelease = \true): LockInterface
    {
        $lock = new Lock($key, $this->store, $ttl, $autoRelease);
        if ($this->logger) {
            $lock->setLogger($this->logger);
        }
        return $lock;
    }
}
