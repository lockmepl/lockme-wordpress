<?php

namespace LockmeDep;

// Don't redefine the functions if included multiple times.
if (!\function_exists('LockmeDep\\GuzzleHttp\\Psr7\\str')) {
    require __DIR__ . '/functions.php';
}
