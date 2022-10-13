<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodDataWriter\Config;
use Keboola\GoodDataWriter\StorageApiClientFactory;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class StorageApiClientFactoryTest extends TestCase
{
    public function testGetClient(): void
    {
        $config = new Config([]);
        $storageApiClient = StorageApiClientFactory::getClient($config);

        Assert::assertEquals(Client::class, get_class($storageApiClient));
    }

    public function testGetBranchClient(): void
    {
        $config = new Config([]);
        $storageApiClient = StorageApiClientFactory::getClient($config, 'branchId');

        Assert::assertEquals(BranchAwareClient::class, get_class($storageApiClient));
    }
}
