<?php

namespace LockmeDep\GuzzleHttp\Promise;

/**
 * Exception that is set as the reason for a promise that has been cancelled.
 */
class CancellationException extends \LockmeDep\GuzzleHttp\Promise\RejectionException
{
}
