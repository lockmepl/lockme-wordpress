<?php

namespace LockmeDep;

// Don't redefine the functions if included multiple times.
if (!\function_exists('LockmeDep\\GuzzleHttp\\Promise\\promise_for')) {
    require __DIR__ . '/functions.php';
}
