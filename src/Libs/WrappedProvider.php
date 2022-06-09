<?php
declare(strict_types=1);

namespace LockmeIntegration\Libs;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Lockme\OAuth2\Client\Provider\Lockme;

class WrappedProvider extends Lockme
{
    public function executeRequest($method, $url, $token, $body = null)
    {
        global $wpdb;

        try {
            return parent::executeRequest($method, $url, $token, $body);
        } catch (IdentityProviderException $exception) {
            $response = $exception->getResponseBody();
            $data = [
                'method' => $method,
                'uri' => $url,
                'params' => json_encode($body),
                'response' => is_string($response) ? $response : json_encode($response)
            ];
            $wpdb->insert(
                $wpdb->prefix.'lockme_log',
                $data
            );

            throw $exception;
        }
    }
}
