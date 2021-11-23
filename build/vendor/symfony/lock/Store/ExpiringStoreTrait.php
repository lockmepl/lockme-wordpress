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

use LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException;
use LockmeDep\Symfony\Component\Lock\Key;
trait ExpiringStoreTrait
{
    private function checkNotExpired(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        if ($key->isExpired()) {
            try {
                $this->delete($key);
            } catch (\Exception $e) {
                // swallow exception to not hide the original issue
            }
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException(\sprintf('Failed to store the "%s" lock.', $key));
        }
    }
}
