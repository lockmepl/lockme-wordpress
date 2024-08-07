<?php

// scoper-autoload.php @generated by PhpScoper

// Backup the autoloaded Composer files
if (isset($GLOBALS['__composer_autoload_files'])) {
    $existingComposerAutoloadFiles = $GLOBALS['__composer_autoload_files'];
}

$loader = require_once __DIR__.'/autoload.php';
// Ensure InstalledVersions is available
$installedVersionsPath = __DIR__.'/composer/InstalledVersions.php';
if (file_exists($installedVersionsPath)) require_once $installedVersionsPath;

// Restore the backup
if (isset($existingComposerAutoloadFiles)) {
    $GLOBALS['__composer_autoload_files'] = $existingComposerAutoloadFiles;
} else {
    unset($GLOBALS['__composer_autoload_files']);
}

// Class aliases. For more information see:
// https://github.com/humbug/php-scoper/blob/master/docs/further-reading.md#class-aliases
if (!function_exists('humbug_phpscoper_expose_class')) {
    function humbug_phpscoper_expose_class(string $exposed, string $prefixed): void {
        if (!class_exists($exposed, false) && !interface_exists($exposed, false) && !trait_exists($exposed, false)) {
            spl_autoload_call($prefixed);
        }
    }
}
humbug_phpscoper_expose_class('Error', 'LockmeDep\Error');
humbug_phpscoper_expose_class('TypeError', 'LockmeDep\TypeError');
humbug_phpscoper_expose_class('ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223', 'LockmeDep\ComposerAutoloaderInitd4cbf4ed8610848fdd2afdd92decc223');

// Function aliases. For more information see:
// https://github.com/humbug/php-scoper/blob/master/docs/further-reading.md#function-aliases
if (!function_exists('RandomCompat_intval')) { function RandomCompat_intval() { return \LockmeDep\RandomCompat_intval(...func_get_args()); } }
if (!function_exists('RandomCompat_strlen')) { function RandomCompat_strlen() { return \LockmeDep\RandomCompat_strlen(...func_get_args()); } }
if (!function_exists('RandomCompat_substr')) { function RandomCompat_substr() { return \LockmeDep\RandomCompat_substr(...func_get_args()); } }
if (!function_exists('getallheaders')) { function getallheaders() { return \LockmeDep\getallheaders(...func_get_args()); } }
if (!function_exists('random_bytes')) { function random_bytes() { return \LockmeDep\random_bytes(...func_get_args()); } }
if (!function_exists('random_int')) { function random_int() { return \LockmeDep\random_int(...func_get_args()); } }
if (!function_exists('trigger_deprecation')) { function trigger_deprecation() { return \LockmeDep\trigger_deprecation(...func_get_args()); } }

return $loader;
