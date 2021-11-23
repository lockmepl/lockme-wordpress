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
use LockmeDep\Symfony\Component\Lock\Exception\LockStorageException;
use LockmeDep\Symfony\Component\Lock\Key;
use LockmeDep\Symfony\Component\Lock\SharedLockStoreInterface;
/**
 * FlockStore is a PersistingStoreInterface implementation using the FileSystem flock.
 *
 * Original implementation in \Symfony\Component\Filesystem\LockHandler.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 * @author Romain Neutron <imprec@gmail.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class FlockStore implements \LockmeDep\Symfony\Component\Lock\BlockingStoreInterface, \LockmeDep\Symfony\Component\Lock\SharedLockStoreInterface
{
    private $lockPath;
    /**
     * @param string|null $lockPath the directory to store the lock, defaults to the system's temporary directory
     *
     * @throws LockStorageException If the lock directory doesn’t exist or is not writable
     */
    public function __construct(string $lockPath = null)
    {
        if (null === $lockPath) {
            $lockPath = \sys_get_temp_dir();
        }
        if (!\is_dir($lockPath)) {
            if (\false === @\mkdir($lockPath, 0777, \true) && !\is_dir($lockPath)) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('The FlockStore directory "%s" does not exists and cannot be created.', $lockPath));
            }
        } elseif (!\is_writable($lockPath)) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('The FlockStore directory "%s" is not writable.', $lockPath));
        }
        $this->lockPath = $lockPath;
    }
    /**
     * {@inheritdoc}
     */
    public function save(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->lock($key, \false, \false);
    }
    /**
     * {@inheritdoc}
     */
    public function saveRead(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->lock($key, \true, \false);
    }
    /**
     * {@inheritdoc}
     */
    public function waitAndSave(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->lock($key, \false, \true);
    }
    /**
     * {@inheritdoc}
     */
    public function waitAndSaveRead(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->lock($key, \true, \true);
    }
    private function lock(\LockmeDep\Symfony\Component\Lock\Key $key, bool $read, bool $blocking)
    {
        $handle = null;
        // The lock is maybe already acquired.
        if ($key->hasState(__CLASS__)) {
            [$stateRead, $handle] = $key->getState(__CLASS__);
            // Check for promotion or demotion
            if ($stateRead === $read) {
                return;
            }
        }
        if (!$handle) {
            $fileName = \sprintf('%s/sf.%s.%s.lock', $this->lockPath, \substr(\preg_replace('/[^a-z0-9\\._-]+/i', '-', $key), 0, 50), \strtr(\substr(\base64_encode(\hash('sha256', $key, \true)), 0, 7), '/', '_'));
            // Silence error reporting
            \set_error_handler(function ($type, $msg) use(&$error) {
                $error = $msg;
            });
            if (!($handle = \fopen($fileName, 'r+') ?: \fopen($fileName, 'r'))) {
                if ($handle = \fopen($fileName, 'x')) {
                    \chmod($fileName, 0666);
                } elseif (!($handle = \fopen($fileName, 'r+') ?: \fopen($fileName, 'r'))) {
                    \usleep(100);
                    // Give some time for chmod() to complete
                    $handle = \fopen($fileName, 'r+') ?: \fopen($fileName, 'r');
                }
            }
            \restore_error_handler();
        }
        if (!$handle) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockStorageException($error, 0, null);
        }
        // On Windows, even if PHP doc says the contrary, LOCK_NB works, see
        // https://bugs.php.net/54129
        if (!\flock($handle, ($read ? \LOCK_SH : \LOCK_EX) | ($blocking ? 0 : \LOCK_NB))) {
            \fclose($handle);
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException();
        }
        $key->setState(__CLASS__, [$read, $handle]);
        $key->markUnserializable();
    }
    /**
     * {@inheritdoc}
     */
    public function putOffExpiration(\LockmeDep\Symfony\Component\Lock\Key $key, float $ttl)
    {
        // do nothing, the flock locks forever.
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
        $handle = $key->getState(__CLASS__)[1];
        \flock($handle, \LOCK_UN | \LOCK_NB);
        \fclose($handle);
        $key->removeState(__CLASS__);
    }
    /**
     * {@inheritdoc}
     */
    public function exists(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        return $key->hasState(__CLASS__);
    }
}
