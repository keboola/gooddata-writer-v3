<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;

class StorageApiClientFactory
{

    public static function getClient(Config $config, ?string $branch = null): Client
    {
        $clientConfig = [
            'url' => $config->getKbcUrl(),
            'token' => $config->getKbcStorageToken(),
        ];

        if ($branch) {
            return new BranchAwareClient($branch, $clientConfig);
        }

        return new Client($clientConfig);
    }
}
