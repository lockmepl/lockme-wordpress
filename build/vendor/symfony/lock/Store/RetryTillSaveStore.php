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

use LockmeDep\Psr\Log\LoggerAwareInterface;
use LockmeDep\Psr\Log\LoggerAwareTrait;
use LockmeDep\Psr\Log\NullLogger;
use LockmeDep\Symfony\Component\Lock\BlockingStoreInterface;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Key;
use LockmeDep\Symfony\Component\Lock\Lock;
use LockmeDep\Symfony\Component\Lock\PersistingStoreInterface;
trigger_deprecation('symfony/lock', '5.2', '%s is deprecated, the "%s" class provides the logic when store is not blocking.', \LockmeDep\Symfony\Component\Lock\Store\RetryTillSaveStore::class, \LockmeDep\Symfony\Component\Lock\Lock::class);
/**
 * RetryTillSaveStore is a PersistingStoreInterface implementation which decorate a non blocking PersistingStoreInterface to provide a
 * blocking storage.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 *
 * @deprecated since Symfony 5.2
 */
class RetryTillSaveStore implements \LockmeDep\Symfony\Component\Lock\BlockingStoreInterface, \LockmeDep\Psr\Log\LoggerAwareInterface
{
    use LoggerAwareTrait;
    private $decorated;
    private $retrySleep;
    private $retryCount;
    /**
     * @param int $retrySleep Duration in ms between 2 retry
     * @param int $retryCount Maximum amount of retry
     */
    public function __construct(\LockmeDep\Symfony\Component\Lock\PersistingStoreInterface $decorated, int $retrySleep = 100, int $retryCount = \PHP_INT_MAX)
    {
        $this->decorated = $decorated;
        $this->retrySleep = $retrySleep;
        $this->retryCount = $retryCount;
        $this->logger = new \LockmeDep\Psr\Log\NullLogger();
    }
    /**
     * {@inheritdoc}
     */
    public function save(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->decorated->save($key);
    }
    /**
     * {@inheritdoc}
     */
    public function waitAndSave(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $retry = 0;
        $sleepRandomness = (int) ($this->retrySleep / 10);
        do {
            try {
                $this->decorated->save($key);
                return;
            } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
                \usleep(($this->retrySleep + \random_int(-$sleepRandomness, $sleepRandomness)) * 1000);
            }
        } while (++$retry < $this->retryCount);
        $this->logger->warning('Failed to store the "{resource}" lock. Abort after {retry} retry.', ['resource' => $key, 'retry' => $retry]);
        throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
    }
    /**
     * {@inheritdoc}
     */
    public function putOffExpiration(\LockmeDep\Symfony\Component\Lock\Key $key, float $ttl)
    {
        $this->decorated->putOffExpiration($key, $ttl);
    }
    /**
     * {@inheritdoc}
     */
    public function delete(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->decorated->delete($key);
    }
    /**
     * {@inheritdoc}
     */
    public function exists(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        return $this->decorated->exists($key);
    }
}
