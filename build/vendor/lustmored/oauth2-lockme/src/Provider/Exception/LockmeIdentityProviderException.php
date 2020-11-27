<?php

namespace LockmeDep\Lockme\OAuth2\Client\Provider\Exception;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeDep\Psr\Http\Message\ResponseInterface;
class LockmeIdentityProviderException extends \League\OAuth2\Client\Provider\Exception\IdentityProviderException
{
    /**
     * Creates client exception from response.
     *
     * @param  ResponseInterface $response
     * @param  array $data Parsed response data
     *
     * @return IdentityProviderException
     */
    public static function clientException(\LockmeDep\Psr\Http\Message\ResponseInterface $response, $data)
    {
        return static::fromResponse($response, isset($data['message']) ? $data['message'] : $response->getReasonPhrase());
    }
    /**
     * Creates oauth exception from response.
     *
     * @param  ResponseInterface $response
     * @param  array $data Parsed response data
     *
     * @return IdentityProviderException
     */
    public static function oauthException(\LockmeDep\Psr\Http\Message\ResponseInterface $response, $data)
    {
        return static::fromResponse($response, isset($data['error']) ? $data['error'] : $response->getReasonPhrase());
    }
    /**
     * Creates identity exception from response.
     *
     * @param  ResponseInterface $response
     * @param  string $message
     *
     * @return IdentityProviderException
     */
    protected static function fromResponse(\LockmeDep\Psr\Http\Message\ResponseInterface $response, $message = null)
    {
        return new static($message, $response->getStatusCode(), (string) $response->getBody());
    }
}
