<?php

// scoper-autoload.php @generated by PhpScoper

$loader = require_once __DIR__.'/autoload.php';

// Exposed classes. For more information see:
// https://github.com/humbug/php-scoper/blob/master/README.md#class-whitelisting
if (!class_exists('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', false) && !interface_exists('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', false) && !trait_exists('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', false)) {
    spl_autoload_call('LockmeDep\ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223');
}

// Exposed functions. For more information see:
// https://github.com/humbug/php-scoper/blob/master/README.md#functions-whitelisting
if (!function_exists('booked_apply_custom_timeslots_details_filter')) {
    function booked_apply_custom_timeslots_details_filter() {
        return \LockmeDep\booked_apply_custom_timeslots_details_filter(...func_get_args());
    }
}
if (!function_exists('booked_apply_custom_timeslots_filter')) {
    function booked_apply_custom_timeslots_filter() {
        return \LockmeDep\booked_apply_custom_timeslots_filter(...func_get_args());
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
if (!function_exists('trigger_deprecation')) {
    function trigger_deprecation() {
        return \LockmeDep\trigger_deprecation(...func_get_args());
    }
}

return $loader;
