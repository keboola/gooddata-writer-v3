<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\Temp\Temp;

class Component extends BaseComponent
{
    protected function initConfig(): Config
    {
        /** @var Config $config */
        $config = $this->getConfig();

        if (!isset($config->getImageParameters()['provisioning_url'])) {
            throw new \Exception('Provisioning url is missing from image parameters');
        }

        return $config;
    }

    protected function initApp(Config $config): App
    {
        $gdClient = $this->initGoodDataClient($config);

        $provisioning = new ProvisioningClient(
            $config->getImageParameters()['provisioning_url'],
            (string) getenv('KBC_TOKEN'),
            $this->getLogger()
        );

        return new App($this->getLogger(), new Temp(), $gdClient, $provisioning);
    }

    public function execute(): void
    {
        $config = $this->initConfig();

        if (!count($config->getInputTables())) {
            throw new UserException('There are no tables on input');
        }

        $configTables = $config->getTables();
        foreach ($config->getInputTables() as $table) {
            if (!isset($configTables[$table['source']])) {
                throw new UserException("Table {$table['source']} is not configured");
            }
        }

        $app = $this->initApp($config);
        $app->run($config, "{$this->getDataDir()}/in/tables");
    }

    public function readModelAction(): array
    {
        $config = $this->initConfig();
        if (!$config->getBucket()) {
            throw new UserException('Bucket for data tables is not configured in parameters');
        }
        if (!$config->getConfigurationId()) {
            throw new UserException('ConfigurationId is not configured in parameters');
        }
        $app = $this->initApp($config);
        $app->readModel($this->getManifestManager(), $config, $this->getDataDir());
        return [];
    }

    public function initGoodDataClient(Config $config): Client
    {
        $gdClient = new Client($config->getImageParameters()['gooddata_url']);
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

    protected function getSyncActions(): array
    {
        return ['readModel' => 'readModelAction'];
    }
}
