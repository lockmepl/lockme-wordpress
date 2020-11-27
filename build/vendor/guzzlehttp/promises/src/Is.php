<?php

namespace LockmeDep\GuzzleHttp\Promise;

final class Is
{
    /**
     * Returns true if a promise is pending.
     *
     * @return bool
     */
    public static function pending(\LockmeDep\GuzzleHttp\Promise\PromiseInterface $promise)
    {
        return $promise->getState() === \LockmeDep\GuzzleHttp\Promise\PromiseInterface::PENDING;
    }
    /**
     * Returns true if a promise is fulfilled or rejected.
     *
     * @return bool
     */
    public static function settled(\LockmeDep\GuzzleHttp\Promise\PromiseInterface $promise)
    {
        return $promise->getState() !== \LockmeDep\GuzzleHttp\Promise\PromiseInterface::PENDING;
    }
    /**
     * Returns true if a promise is fulfilled.
     *
     * @return bool
     */
    public static function fulfilled(\LockmeDep\GuzzleHttp\Promise\PromiseInterface $promise)
    {
        return $promise->getState() === \LockmeDep\GuzzleHttp\Promise\PromiseInterface::FULFILLED;
    }
    /**
     * Returns true if a promise is rejected.
     *
     * @return bool
     */
    public static function rejected(\LockmeDep\GuzzleHttp\Promise\PromiseInterface $promise)
    {
        return $promise->getState() === \LockmeDep\GuzzleHttp\Promise\PromiseInterface::REJECTED;
    }
}
