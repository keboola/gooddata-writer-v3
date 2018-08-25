<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\Temp\Temp;

class Component extends BaseComponent
{
    public function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();

        if (!count($config->getInputTables())) {
            throw new UserException('There are no tables on input');
        }
        $configTables = $config->getTables();
        foreach ($config->getInputTables() as $table) {
            if (!isset($configTables[$table['source']])) {
                throw new UserException("Table {$table['source']} is not configured");
            }
        }
        if (!isset($config->getImageParameters()['provisioning_url'])) {
            throw new \Exception('Provisioning url is missing from image parameters');
        }

        $temp = new Temp();
        $temp->initRunFolder();

        $gdClient = $this->initGoodDataClient($config);

        $provisioning = new ProvisioningClient(
            $config->getImageParameters()['provisioning_url'],
            getenv('KBC_TOKEN'),
            $this->getLogger()
        );

        $app = new App($this->getLogger(), $temp, $gdClient, $provisioning);
        $app->run($config, "{$this->getDataDir()}/in/tables");
    }

    public function initGoodDataClient(Config $config): Client
    {
        $gdClient = new Client();
        $gdClient->setUserAgent('gooddata-writer-v3', getenv('KBC_RUNID'));
        if ($config->getProjectBackendUrl()) {
            $gdClient->setApiUrl($config->getProjectBackendUrl());
            $gdClient->disableCheckDomain();
        }
        $gdClient->login($config->getUserLogin(), $config->getUserPassword());
        $gdClient->setLogger($this->getLogger());
        return $gdClient;
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }
    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }
}
