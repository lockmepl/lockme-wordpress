<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'LockmeDep\\Symfony\\Component\\Lock\\' => array($vendorDir . '/symfony/lock'),
    'LockmeDep\\Psr\\Log\\' => array($vendorDir . '/psr/log/src'),
    'LockmeDep\\Psr\\Http\\Message\\' => array($vendorDir . '/psr/http-factory/src', $vendorDir . '/psr/http-message/src'),
    'LockmeDep\\Psr\\Http\\Client\\' => array($vendorDir . '/psr/http-client/src'),
    'LockmeDep\\Lockme\\SDK\\' => array($vendorDir . '/lustmored/lockme-sdk/src'),
    'LockmeDep\\Lockme\\OAuth2\\Client\\' => array($vendorDir . '/lustmored/oauth2-lockme/src'),
    'LockmeDep\\LockmeIntegration\\' => array($baseDir . '/src'),
    'LockmeDep\\GuzzleHttp\\Psr7\\' => array($vendorDir . '/guzzlehttp/psr7/src'),
    'LockmeDep\\GuzzleHttp\\Promise\\' => array($vendorDir . '/guzzlehttp/promises/src'),
    'LockmeDep\\GuzzleHttp\\' => array($vendorDir . '/guzzlehttp/guzzle/src'),
    'League\\OAuth2\\Client\\' => array($vendorDir . '/league/oauth2-client/src'),
);
