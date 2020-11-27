<?php

namespace LockmeDep\Composer;

use LockmeDep\Composer\Semver\VersionParser;
class InstalledVersions
{
    private static $installed = array('root' => array('pretty_version' => 'dev-master', 'version' => 'dev-master', 'aliases' => array(), 'reference' => '9326686bbdf70537deffd808a87639455ff1809a', 'name' => 'lustmored/lockme-wordpress'), 'versions' => array('guzzlehttp/guzzle' => array('pretty_version' => '7.2.0', 'version' => '7.2.0.0', 'aliases' => array(), 'reference' => '0aa74dfb41ae110835923ef10a9d803a22d50e79'), 'guzzlehttp/promises' => array('pretty_version' => '1.4.0', 'version' => '1.4.0.0', 'aliases' => array(), 'reference' => '60d379c243457e073cff02bc323a2a86cb355631'), 'guzzlehttp/psr7' => array('pretty_version' => '1.7.0', 'version' => '1.7.0.0', 'aliases' => array(), 'reference' => '53330f47520498c0ae1f61f7e2c90f55690c06a3'), 'league/oauth2-client' => array('pretty_version' => '2.6.0', 'version' => '2.6.0.0', 'aliases' => array(), 'reference' => 'badb01e62383430706433191b82506b6df24ad98'), 'lustmored/lockme-sdk' => array('pretty_version' => '2.0.1', 'version' => '2.0.1.0', 'aliases' => array(), 'reference' => '0fe9cd8f61576d11c9d400a372df6a700fcdd620'), 'lustmored/lockme-wordpress' => array('pretty_version' => 'dev-master', 'version' => 'dev-master', 'aliases' => array(), 'reference' => '9326686bbdf70537deffd808a87639455ff1809a'), 'lustmored/oauth2-lockme' => array('pretty_version' => '1.1.2', 'version' => '1.1.2.0', 'aliases' => array(), 'reference' => '9812d62ea709341c38d9e548917033a833464c10'), 'paragonie/random_compat' => array('pretty_version' => 'v2.0.19', 'version' => '2.0.19.0', 'aliases' => array(), 'reference' => '446fc9faa5c2a9ddf65eb7121c0af7e857295241'), 'psr/http-client' => array('pretty_version' => '1.0.1', 'version' => '1.0.1.0', 'aliases' => array(), 'reference' => '2dfb5f6c5eff0e91e20e913f8c5452ed95b86621'), 'psr/http-client-implementation' => array('provided' => array(0 => '1.0')), 'psr/http-message' => array('pretty_version' => '1.0.1', 'version' => '1.0.1.0', 'aliases' => array(), 'reference' => 'f6561bf28d520154e4b0ec72be95418abe6d9363'), 'psr/http-message-implementation' => array('provided' => array(0 => '1.0')), 'psr/log' => array('pretty_version' => '1.1.3', 'version' => '1.1.3.0', 'aliases' => array(), 'reference' => '0f73288fd15629204f9d42b7055f72dacbe811fc'), 'ralouphie/getallheaders' => array('pretty_version' => '3.0.3', 'version' => '3.0.3.0', 'aliases' => array(), 'reference' => '120b605dfeb996808c31b6477290a714d356e822'), 'roave/security-advisories' => array('pretty_version' => 'dev-master', 'version' => 'dev-master', 'aliases' => array(), 'reference' => '563faed0e5e24a5b639fbd30d8f3921bb11fccc0'), 'symfony/lock' => array('pretty_version' => 'v5.1.8', 'version' => '5.1.8.0', 'aliases' => array(), 'reference' => '9ff3c3bfc08da89d307464276aa206514a8c97e5'), 'symfony/polyfill-php80' => array('pretty_version' => 'v1.20.0', 'version' => '1.20.0.0', 'aliases' => array(), 'reference' => 'e70aa8b064c5b72d3df2abd5ab1e90464ad009de')));
    public static function getInstalledPackages()
    {
        return \array_keys(self::$installed['versions']);
    }
    public static function isInstalled($packageName)
    {
        return isset(self::$installed['versions'][$packageName]);
    }
    public static function satisfies(\LockmeDep\Composer\Semver\VersionParser $parser, $packageName, $constraint)
    {
        $constraint = $parser->parseConstraints($constraint);
        $provided = $parser->parseConstraints(self::getVersionRanges($packageName));
        return $provided->matches($constraint);
    }
    public static function getVersionRanges($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        $ranges = array();
        if (isset(self::$installed['versions'][$packageName]['pretty_version'])) {
            $ranges[] = self::$installed['versions'][$packageName]['pretty_version'];
        }
        if (\array_key_exists('aliases', self::$installed['versions'][$packageName])) {
            $ranges = \array_merge($ranges, self::$installed['versions'][$packageName]['aliases']);
        }
        if (\array_key_exists('replaced', self::$installed['versions'][$packageName])) {
            $ranges = \array_merge($ranges, self::$installed['versions'][$packageName]['replaced']);
        }
        if (\array_key_exists('provided', self::$installed['versions'][$packageName])) {
            $ranges = \array_merge($ranges, self::$installed['versions'][$packageName]['provided']);
        }
        return \implode(' || ', $ranges);
    }
    public static function getVersion($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        if (!isset(self::$installed['versions'][$packageName]['version'])) {
            return null;
        }
        return self::$installed['versions'][$packageName]['version'];
    }
    public static function getPrettyVersion($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        if (!isset(self::$installed['versions'][$packageName]['pretty_version'])) {
            return null;
        }
        return self::$installed['versions'][$packageName]['pretty_version'];
    }
    public static function getReference($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        if (!isset(self::$installed['versions'][$packageName]['reference'])) {
            return null;
        }
        return self::$installed['versions'][$packageName]['reference'];
    }
    public static function getRootPackage()
    {
        return self::$installed['root'];
    }
    public static function getRawData()
    {
        return self::$installed;
    }
    public static function reload($data)
    {
        self::$installed = $data;
    }
}