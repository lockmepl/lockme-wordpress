<?php

// scoper-autoload.php @generated by PhpScoper

$loader = require_once __DIR__.'/autoload.php';

// Aliases for the whitelisted classes. For more information see:
// https://github.com/humbug/php-scoper/blob/master/README.md#class-whitelisting
if (!class_exists('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', false) && !interface_exists('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', false) && !trait_exists('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', false)) {
    spl_autoload_call('LockmeDep\ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223');
}
if (!class_exists('Error', false) && !interface_exists('Error', false) && !trait_exists('Error', false)) {
    spl_autoload_call('LockmeDep\Error');
}
if (!class_exists('TypeError', false) && !interface_exists('TypeError', false) && !trait_exists('TypeError', false)) {
    spl_autoload_call('LockmeDep\TypeError');
}
if (!class_exists('UnhandledMatchError', false) && !interface_exists('UnhandledMatchError', false) && !trait_exists('UnhandledMatchError', false)) {
    spl_autoload_call('LockmeDep\UnhandledMatchError');
}
if (!class_exists('ValueError', false) && !interface_exists('ValueError', false) && !trait_exists('ValueError', false)) {
    spl_autoload_call('LockmeDep\ValueError');
}
if (!class_exists('Attribute', false) && !interface_exists('Attribute', false) && !trait_exists('Attribute', false)) {
    spl_autoload_call('LockmeDep\Attribute');
}
if (!class_exists('Stringable', false) && !interface_exists('Stringable', false) && !trait_exists('Stringable', false)) {
    spl_autoload_call('LockmeDep\Stringable');
}

// Functions whitelisting. For more information see:
// https://github.com/humbug/php-scoper/blob/master/README.md#functions-whitelisting
if (!function_exists('composerRequired4cbf4ed8610848fdd2afdd92decc223')) {
    function composerRequired4cbf4ed8610848fdd2afdd92decc223() {
        return \LockmeDep\composerRequired4cbf4ed8610848fdd2afdd92decc223(...func_get_args());
    }
}
if (!function_exists('RandomCompat_strlen')) {
    function RandomCompat_strlen() {
        return \LockmeDep\RandomCompat_strlen(...func_get_args());
    }
}
if (!function_exists('RandomCompat_substr')) {
    function RandomCompat_substr() {
        return \LockmeDep\RandomCompat_substr(...func_get_args());
    }
}
if (!function_exists('RandomCompat_intval')) {
    function RandomCompat_intval() {
        return \LockmeDep\RandomCompat_intval(...func_get_args());
    }
}

return $loader;
