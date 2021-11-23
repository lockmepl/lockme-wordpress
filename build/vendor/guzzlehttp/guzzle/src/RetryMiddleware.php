<?php

namespace LockmeDep\GuzzleHttp;

use LockmeDep\GuzzleHttp\Promise as P;
use LockmeDep\GuzzleHttp\Promise\PromiseInterface;
use LockmeDep\Psr\Http\Message\RequestInterface;
use LockmeDep\Psr\Http\Message\ResponseInterface;
/**
 * Middleware that retries requests based on the boolean result of
 * invoking the provided "decider" function.
 *
 * @final
 */
class RetryMiddleware
{
    /**
     * @var callable(RequestInterface, array): PromiseInterface
     */
    private $nextHandler;
    /**
     * @var callable
     */
    private $decider;
    /**
     * @var callable(int)
     */
    private $delay;
    /**
     * @param callable                                            $decider     Function that accepts the number of retries,
     *                                                                         a request, [response], and [exception] and
     *                                                                         returns true if the request is to be
     *                                                                         retried.
     * @param callable(RequestInterface, array): PromiseInterface $nextHandler Next handler to invoke.
     * @param null|callable(int): int                             $delay       Function that accepts the number of retries
     *                                                                         and returns the number of
     *                                                                         milliseconds to delay.
     */
    public function __construct(callable $decider, callable $nextHandler, callable $delay = null)
    {
        $this->decider = $decider;
        $this->nextHandler = $nextHandler;
        $this->delay = $delay ?: __CLASS__ . '::exponentialDelay';
    }
    /**
     * Default exponential backoff delay function.
     *
     * @return int milliseconds.
     */
    public static function exponentialDelay(int $retries) : int
    {
        return (int) \pow(2, $retries - 1) * 1000;
    }
    public function __invoke(\LockmeDep\Psr\Http\Message\RequestInterface $request, array $options) : \LockmeDep\GuzzleHttp\Promise\PromiseInterface
    {
        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        }
        $fn = $this->nextHandler;
        return $fn($request, $options)->then($this->onFulfilled($request, $options), $this->onRejected($request, $options));
    }
    /**
     * Execute fulfilled closure
     */
    private function onFulfilled(\LockmeDep\Psr\Http\Message\RequestInterface $request, array $options) : callable
    {
        return function ($value) use($request, $options) {
            if (!($this->decider)($options['retries'], $request, $value, null)) {
                return $value;
            }
            return $this->doRetry($request, $options, $value);
        };
    }
    /**
     * Execute rejected closure
     */
    private function onRejected(\LockmeDep\Psr\Http\Message\RequestInterface $req, array $options) : callable
    {
        return function ($reason) use($req, $options) {
            if (!($this->decider)($options['retries'], $req, null, $reason)) {
                return \LockmeDep\GuzzleHttp\Promise\Create::rejectionFor($reason);
            }
            return $this->doRetry($req, $options);
        };
    }
    private function doRetry(\LockmeDep\Psr\Http\Message\RequestInterface $request, array $options, \LockmeDep\Psr\Http\Message\ResponseInterface $response = null) : \LockmeDep\GuzzleHttp\Promise\PromiseInterface
    {
        $options['delay'] = ($this->delay)(++$options['retries'], $response);
        return $this($request, $options);
    }
}
