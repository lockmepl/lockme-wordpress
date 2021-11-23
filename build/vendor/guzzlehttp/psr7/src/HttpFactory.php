<?php

declare (strict_types=1);
namespace LockmeDep\GuzzleHttp\Psr7;

use LockmeDep\Psr\Http\Message\RequestFactoryInterface;
use LockmeDep\Psr\Http\Message\RequestInterface;
use LockmeDep\Psr\Http\Message\ResponseFactoryInterface;
use LockmeDep\Psr\Http\Message\ResponseInterface;
use LockmeDep\Psr\Http\Message\ServerRequestFactoryInterface;
use LockmeDep\Psr\Http\Message\ServerRequestInterface;
use LockmeDep\Psr\Http\Message\StreamFactoryInterface;
use LockmeDep\Psr\Http\Message\StreamInterface;
use LockmeDep\Psr\Http\Message\UploadedFileFactoryInterface;
use LockmeDep\Psr\Http\Message\UploadedFileInterface;
use LockmeDep\Psr\Http\Message\UriFactoryInterface;
use LockmeDep\Psr\Http\Message\UriInterface;
/**
 * Implements all of the PSR-17 interfaces.
 *
 * Note: in consuming code it is recommended to require the implemented interfaces
 * and inject the instance of this class multiple times.
 */
final class HttpFactory implements \LockmeDep\Psr\Http\Message\RequestFactoryInterface, \LockmeDep\Psr\Http\Message\ResponseFactoryInterface, \LockmeDep\Psr\Http\Message\ServerRequestFactoryInterface, \LockmeDep\Psr\Http\Message\StreamFactoryInterface, \LockmeDep\Psr\Http\Message\UploadedFileFactoryInterface, \LockmeDep\Psr\Http\Message\UriFactoryInterface
{
    public function createUploadedFile(\LockmeDep\Psr\Http\Message\StreamInterface $stream, int $size = null, int $error = \UPLOAD_ERR_OK, string $clientFilename = null, string $clientMediaType = null) : \LockmeDep\Psr\Http\Message\UploadedFileInterface
    {
        if ($size === null) {
            $size = $stream->getSize();
        }
        return new \LockmeDep\GuzzleHttp\Psr7\UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }
    public function createStream(string $content = '') : \LockmeDep\Psr\Http\Message\StreamInterface
    {
        return \LockmeDep\GuzzleHttp\Psr7\Utils::streamFor($content);
    }
    public function createStreamFromFile(string $file, string $mode = 'r') : \LockmeDep\Psr\Http\Message\StreamInterface
    {
        try {
            $resource = \LockmeDep\GuzzleHttp\Psr7\Utils::tryFopen($file, $mode);
        } catch (\RuntimeException $e) {
            if ('' === $mode || \false === \in_array($mode[0], ['r', 'w', 'a', 'x', 'c'], \true)) {
                throw new \InvalidArgumentException(\sprintf('Invalid file opening mode "%s"', $mode), 0, $e);
            }
            throw $e;
        }
        return \LockmeDep\GuzzleHttp\Psr7\Utils::streamFor($resource);
    }
    public function createStreamFromResource($resource) : \LockmeDep\Psr\Http\Message\StreamInterface
    {
        return \LockmeDep\GuzzleHttp\Psr7\Utils::streamFor($resource);
    }
    public function createServerRequest(string $method, $uri, array $serverParams = []) : \LockmeDep\Psr\Http\Message\ServerRequestInterface
    {
        if (empty($method)) {
            if (!empty($serverParams['REQUEST_METHOD'])) {
                $method = $serverParams['REQUEST_METHOD'];
            } else {
                throw new \InvalidArgumentException('Cannot determine HTTP method');
            }
        }
        return new \LockmeDep\GuzzleHttp\Psr7\ServerRequest($method, $uri, [], null, '1.1', $serverParams);
    }
    public function createResponse(int $code = 200, string $reasonPhrase = '') : \LockmeDep\Psr\Http\Message\ResponseInterface
    {
        return new \LockmeDep\GuzzleHttp\Psr7\Response($code, [], null, '1.1', $reasonPhrase);
    }
    public function createRequest(string $method, $uri) : \LockmeDep\Psr\Http\Message\RequestInterface
    {
        return new \LockmeDep\GuzzleHttp\Psr7\Request($method, $uri);
    }
    public function createUri(string $uri = '') : \LockmeDep\Psr\Http\Message\UriInterface
    {
        return new \LockmeDep\GuzzleHttp\Psr7\Uri($uri);
    }
}
