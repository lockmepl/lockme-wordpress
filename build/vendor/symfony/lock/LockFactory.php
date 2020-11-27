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
use LockmeDep\Psr\Log\NullLogger;
/**
 * Factory provides method to create locks.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 * @author Hamza Amrouche <hamza.simperfit@gmail.com>
 */
class LockFactory implements \LockmeDep\Psr\Log\LoggerAwareInterface
{
    use LoggerAwareTrait;
    private $store;
    public function __construct(\LockmeDep\Symfony\Component\Lock\PersistingStoreInterface $store)
    {
        $this->store = $store;
        $this->logger = new \LockmeDep\Psr\Log\NullLogger();
    }
    /**
     * Creates a lock for the given resource.
     *
     * @param string     $resource    The resource to lock
     * @param float|null $ttl         Maximum expected lock duration in seconds
     * @param bool       $autoRelease Whether to automatically release the lock or not when the lock instance is destroyed
     */
    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = \true) : \LockmeDep\Symfony\Component\Lock\LockInterface
    {
        $lock = new \LockmeDep\Symfony\Component\Lock\Lock(new \LockmeDep\Symfony\Component\Lock\Key($resource), $this->store, $ttl, $autoRelease);
        $lock->setLogger($this->logger);
        return $lock;
    }
}
