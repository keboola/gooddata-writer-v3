<?php

namespace Keboola\GoodDataWriter;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Monolog\Formatter\FormatterInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class ProvisioningClient
{
    const RETRIES_COUNT = 5;

    /** @var array  */
    protected $guzzleOptions;
    /** @var LoggerInterface  */
    protected $logger;
    /** @var MessageFormatter  */
    protected $loggerFormatter;
    /** @var Client */
    protected $client;

    public function __construct(
        string $url,
        string $token,
        LoggerInterface $logger = null,
        FormatterInterface $loggerFormatter = null,
        array $options = []
    ) {
        $this->guzzleOptions = $options;
        $this->guzzleOptions['base_uri'] = $url;
        $this->guzzleOptions['headers'] = ['X-StorageApi-Token' => $token];

        if ($logger) {
            $this->logger = $logger;
        }
        $this->loggerFormatter = $loggerFormatter ?: new MessageFormatter("{hostname} {req_header_User-Agent} - [{ts}] "
            . "\"{method} {resource} {protocol}/{version}\" {code} {res_header_Content-Length}");
        $this->initClient();
    }

    protected function initClient(): void
    {
        $handlerStack = HandlerStack::create();

        /** @noinspection PhpUnusedParameterInspection */
        $handlerStack->push(Middleware::retry(
            function ($retries, RequestInterface $request, ResponseInterface $response = null, $error = null) {
                return $response && $response->getStatusCode() == 503;
            },
            function ($retries) {
                return rand(60, 600) * 1000;
            }
        ));
        /** @noinspection PhpUnusedParameterInspection */
        $handlerStack->push(Middleware::retry(
            function ($retries, RequestInterface $request, ResponseInterface $response = null, $error = null) {
                if ($retries >= self::RETRIES_COUNT) {
                    return false;
                } elseif ($response && $response->getStatusCode() > 499) {
                    return true;
                } elseif ($error) {
                    return true;
                } else {
                    return false;
                }
            },
            function ($retries) {
                return (int) pow(2, $retries - 1) * 1000;
            }
        ));

        $handlerStack->push(Middleware::cookies());
        if ($this->logger) {
            $handlerStack->push(Middleware::log($this->logger, $this->loggerFormatter));
        }
        $this->client = new Client(array_merge([
            'handler' => $handlerStack,
            'cookies' => true
        ], $this->guzzleOptions));
    }

    public function addUserToProject(string $login, string $pid)
    {
        $options = $this->guzzleOptions;
        $options['json'] = ['role' => 'admin'];
        return $this->client->request('POST', "/projects/$pid/users/$login", $options);
    }
}
