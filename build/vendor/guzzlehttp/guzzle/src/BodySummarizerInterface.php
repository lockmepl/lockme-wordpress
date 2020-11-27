<?php

namespace LockmeDep\GuzzleHttp;

use LockmeDep\Psr\Http\Message\MessageInterface;
interface BodySummarizerInterface
{
    /**
     * Returns a summarized message body.
     */
    public function summarize(\LockmeDep\Psr\Http\Message\MessageInterface $message) : ?string;
}
