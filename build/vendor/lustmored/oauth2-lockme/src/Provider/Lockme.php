<?php

namespace LockmeDep\Lockme\OAuth2\Client\Provider;

use LockmeDep\GuzzleHttp\Client as HttpClient;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use LockmeDep\Lockme\OAuth2\Client\Provider\Exception\LockmeIdentityProviderException;
use LockmeDep\Psr\Http\Message\ResponseInterface;
class Lockme extends \League\OAuth2\Client\Provider\AbstractProvider
{
    use BearerAuthorizationTrait;
    /**
     * Api domain
     *
     * @var string
     */
    public $apiDomain = 'https://api.lock.me';
    /**
     * API version
     * @var string
     */
    public $version = 'v2.1';
    public function __construct($options)
    {
        $collaborators = [];
        if (isset($options['api_domain'])) {
            $this->apiDomain = $options['api_domain'];
        }
        if (isset($options['apiDomain'])) {
            $this->apiDomain = $options['apiDomain'];
        }
        if (isset($options['version'])) {
            $this->version = $options['version'];
        }
        if (isset($options['ignoreSslErrors']) && $options['ignoreSslErrors']) {
            $collaborators['httpClient'] = new \LockmeDep\GuzzleHttp\Client(['verify' => \false]);
        }
        parent::__construct($options, $collaborators);
    }
    public function getBaseAuthorizationUrl()
    {
        return $this->apiDomain . '/authorize';
    }
    public function getBaseAccessTokenUrl(array $params)
    {
        return $this->apiDomain . '/access_token';
    }
    public function getResourceOwnerDetailsUrl(\League\OAuth2\Client\Token\AccessToken $token)
    {
        return $this->apiDomain . '/' . $this->version . '/me';
    }
    protected function getDefaultScopes()
    {
        return [];
    }
    protected function checkResponse(\LockmeDep\Psr\Http\Message\ResponseInterface $response, $data)
    {
        if ($response->getStatusCode() >= 400) {
            throw \LockmeDep\Lockme\OAuth2\Client\Provider\Exception\LockmeIdentityProviderException::clientException($response, $data);
        }
        if (isset($data['error'])) {
            throw \LockmeDep\Lockme\OAuth2\Client\Provider\Exception\LockmeIdentityProviderException::oauthException($response, $data);
        }
    }
    protected function createResourceOwner(array $response, \League\OAuth2\Client\Token\AccessToken $token)
    {
        return new \LockmeDep\Lockme\OAuth2\Client\Provider\LockmeUser($response);
    }
    /**
     * Generate request, execute it and return parsed response
     * @param string                  $method
     * @param string                  $url
     * @param AccessToken|string|null $token
     * @param mixed                   $body
     * @return mixed
     * @throws IdentityProviderException
     */
    public function executeRequest($method, $url, $token, $body = null)
    {
        $options = [];
        if ($body) {
            $options['body'] = \json_encode($body);
            $options['headers']['Content-Type'] = 'application/json';
        }
        $request = $this->getAuthenticatedRequest($method, $this->apiDomain . '/' . $this->version . $url, $token, $options);
        return $this->getParsedResponse($request);
    }
}
