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
use LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException;
use LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException;
use LockmeDep\Symfony\Component\Lock\Exception\LockReleasingException;
/**
 * Lock is the default implementation of the LockInterface.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
final class Lock implements \LockmeDep\Symfony\Component\Lock\SharedLockInterface, \LockmeDep\Psr\Log\LoggerAwareInterface
{
    use LoggerAwareTrait;
    private $store;
    private $key;
    private $ttl;
    private $autoRelease;
    private $dirty = \false;
    /**
     * @param float|null $ttl         Maximum expected lock duration in seconds
     * @param bool       $autoRelease Whether to automatically release the lock or not when the lock instance is destroyed
     */
    public function __construct(\LockmeDep\Symfony\Component\Lock\Key $key, \LockmeDep\Symfony\Component\Lock\PersistingStoreInterface $store, float $ttl = null, bool $autoRelease = \true)
    {
        $this->store = $store;
        $this->key = $key;
        $this->ttl = $ttl;
        $this->autoRelease = $autoRelease;
        $this->logger = new \LockmeDep\Psr\Log\NullLogger();
    }
    public function __sleep()
    {
        throw new \BadMethodCallException('Cannot serialize ' . __CLASS__);
    }
    public function __wakeup()
    {
        throw new \BadMethodCallException('Cannot unserialize ' . __CLASS__);
    }
    /**
     * Automatically releases the underlying lock when the object is destructed.
     */
    public function __destruct()
    {
        if (!$this->autoRelease || !$this->dirty || !$this->isAcquired()) {
            return;
        }
        $this->release();
    }
    /**
     * {@inheritdoc}
     */
    public function acquire(bool $blocking = \false) : bool
    {
        $this->key->resetLifetime();
        try {
            if ($blocking) {
                if (!$this->store instanceof \LockmeDep\Symfony\Component\Lock\BlockingStoreInterface) {
                    while (\true) {
                        try {
                            $this->store->save($this->key);
                            break;
                        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
                            \usleep((100 + \random_int(-10, 10)) * 1000);
                        }
                    }
                } else {
                    $this->store->waitAndSave($this->key);
                }
            } else {
                $this->store->save($this->key);
            }
            $this->dirty = \true;
            $this->logger->debug('Successfully acquired the "{resource}" lock.', ['resource' => $this->key]);
            if ($this->ttl) {
                $this->refresh();
            }
            if ($this->key->isExpired()) {
                try {
                    $this->release();
                } catch (\Exception $e) {
                    // swallow exception to not hide the original issue
                }
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException(\sprintf('Failed to store the "%s" lock.', $this->key));
            }
            return \true;
        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
            $this->dirty = \false;
            $this->logger->info('Failed to acquire the "{resource}" lock. Someone else already acquired the lock.', ['resource' => $this->key]);
            if ($blocking) {
                throw $e;
            }
            return \false;
        } catch (\Exception $e) {
            $this->logger->notice('Failed to acquire the "{resource}" lock.', ['resource' => $this->key, 'exception' => $e]);
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException(\sprintf('Failed to acquire the "%s" lock.', $this->key), 0, $e);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function acquireRead(bool $blocking = \false) : bool
    {
        $this->key->resetLifetime();
        try {
            if (!$this->store instanceof \LockmeDep\Symfony\Component\Lock\SharedLockStoreInterface) {
                $this->logger->debug('Store does not support ReadLocks, fallback to WriteLock.', ['resource' => $this->key]);
                return $this->acquire($blocking);
            }
            if ($blocking) {
                if (!$this->store instanceof \LockmeDep\Symfony\Component\Lock\BlockingSharedLockStoreInterface) {
                    while (\true) {
                        try {
                            $this->store->saveRead($this->key);
                            break;
                        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
                            \usleep((100 + \random_int(-10, 10)) * 1000);
                        }
                    }
                } else {
                    $this->store->waitAndSaveRead($this->key);
                }
            } else {
                $this->store->saveRead($this->key);
            }
            $this->dirty = \true;
            $this->logger->debug('Successfully acquired the "{resource}" lock for reading.', ['resource' => $this->key]);
            if ($this->ttl) {
                $this->refresh();
            }
            if ($this->key->isExpired()) {
                try {
                    $this->release();
                } catch (\Exception $e) {
                    // swallow exception to not hide the original issue
                }
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException(\sprintf('Failed to store the "%s" lock.', $this->key));
            }
            return \true;
        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
            $this->dirty = \false;
            $this->logger->info('Failed to acquire the "{resource}" lock. Someone else already acquired the lock.', ['resource' => $this->key]);
            if ($blocking) {
                throw $e;
            }
            return \false;
        } catch (\Exception $e) {
            $this->logger->notice('Failed to acquire the "{resource}" lock.', ['resource' => $this->key, 'exception' => $e]);
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException(\sprintf('Failed to acquire the "%s" lock.', $this->key), 0, $e);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function refresh(float $ttl = null)
    {
        if (null === $ttl) {
            $ttl = $this->ttl;
        }
        if (!$ttl) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException('You have to define an expiration duration.');
        }
        try {
            $this->key->resetLifetime();
            $this->store->putOffExpiration($this->key, $ttl);
            $this->dirty = \true;
            if ($this->key->isExpired()) {
                try {
                    $this->release();
                } catch (\Exception $e) {
                    // swallow exception to not hide the original issue
                }
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException(\sprintf('Failed to put off the expiration of the "%s" lock within the specified time.', $this->key));
            }
            $this->logger->debug('Expiration defined for "{resource}" lock for "{ttl}" seconds.', ['resource' => $this->key, 'ttl' => $ttl]);
        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException $e) {
            $this->dirty = \false;
            $this->logger->notice('Failed to define an expiration for the "{resource}" lock, someone else acquired the lock.', ['resource' => $this->key]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->notice('Failed to define an expiration for the "{resource}" lock.', ['resource' => $this->key, 'exception' => $e]);
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException(\sprintf('Failed to define an expiration for the "%s" lock.', $this->key), 0, $e);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function isAcquired() : bool
    {
        return $this->dirty = $this->store->exists($this->key);
    }
    /**
     * {@inheritdoc}
     */
    public function release()
    {
        try {
            try {
                $this->store->delete($this->key);
                $this->dirty = \false;
            } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockReleasingException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockReleasingException(\sprintf('Failed to release the "%s" lock.', $this->key), 0, $e);
            }
            if ($this->store->exists($this->key)) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockReleasingException(\sprintf('Failed to release the "%s" lock, the resource is still locked.', $this->key));
            }
        } catch (\LockmeDep\Symfony\Component\Lock\Exception\LockReleasingException $e) {
            $this->logger->notice('Failed to release the "{resource}" lock.', ['resource' => $this->key]);
            throw $e;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function isExpired() : bool
    {
        return $this->key->isExpired();
    }
    /**
     * {@inheritdoc}
     */
    public function getRemainingLifetime() : ?float
    {
        return $this->key->getRemainingLifetime();
    }
}
