<?php

namespace LockmeDep\GuzzleHttp;

use LockmeDep\Psr\Http\Message\RequestInterface;
use LockmeDep\Psr\Http\Message\ResponseInterface;
interface MessageFormatterInterface
{
    /**
     * Returns a formatted message string.
     *
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface|null $response Response that was received
     * @param \Throwable|null        $error    Exception that was received
     */
    public function format(\LockmeDep\Psr\Http\Message\RequestInterface $request, ?\LockmeDep\Psr\Http\Message\ResponseInterface $response = null, ?\Throwable $error = null) : string;
}
