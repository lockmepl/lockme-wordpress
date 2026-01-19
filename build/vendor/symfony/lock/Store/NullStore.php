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

use LockmeDep\Symfony\Component\Lock\BlockingSharedLockStoreInterface;
use LockmeDep\Symfony\Component\Lock\Key;
class NullStore implements BlockingSharedLockStoreInterface
{
    public function save(Key $key): void
    {
    }
    public function delete(Key $key): void
    {
    }
    public function exists(Key $key): bool
    {
        return \false;
    }
    public function putOffExpiration(Key $key, float $ttl): void
    {
    }
    public function saveRead(Key $key): void
    {
    }
    public function waitAndSaveRead(Key $key): void
    {
    }
}
