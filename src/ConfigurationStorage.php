<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;

class ConfigurationStorage
{
    /** @var Client */
    protected $client;
    /** @var Components  */
    protected $components;

    protected const COMPONENT_ID = 'keboola.gooddata-writer';

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->components = new Components($client);
    }

    public function updateConfiguration(string $configId, array $data, ?string $description = null): void
    {
        $configuration = new Configuration();
        $configuration
            ->setComponentId(self::COMPONENT_ID)
            ->setConfigurationId($configId);
        $configurationData = $this->components->getConfiguration(self::COMPONENT_ID, $configId);

        $configuration->setName($configurationData['name']);
        $configuration->setConfiguration(array_replace_recursive($configurationData['configuration'], $data));
        if ($description) {
            $configuration->setDescription($description);
        }
        $this->components->updateConfiguration($configuration);
    }
}
