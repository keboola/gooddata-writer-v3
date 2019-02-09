<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\GoodData\Exception;
use Keboola\Temp\Temp;

class Component extends BaseComponent
{
    /** @var ProvisioningClient */
    protected $provisioning;

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

        $this->provisioning = new ProvisioningClient(
            $config->getImageParameters()['provisioning_url'],
            getenv('KBC_TOKEN'),
            $this->getLogger()
        );

        $gdClient = $this->initGoodDataClient($config);

        $app = new App($this->getLogger(), $temp, $gdClient, $this->provisioning);
        $app->run($config, "{$this->getDataDir()}/in/tables");
    }

    public function initGoodDataClient(Config $config): Client
    {
        $gdClient = new Client();
        $gdClient->setUserAgent('gooddata-writer-v3', getenv('KBC_RUNID'));
        $gdClient->setLogger($this->getLogger());
        if ($config->getProjectBackendUrl()) {
            $gdClient->setApiUrl($config->getProjectBackendUrl());
            $gdClient->disableCheckDomain();
        }
        try {
            $gdClient->login($config->getUserLogin(), $config->getUserPassword());
        } catch (Exception $e) {
            if ($e->getCode() !== 403) {
                throw $e;
            }

            // Provisioning does not add user to the project on its creation, do it now.
            $this->provisioning->addUserToProject($config->getUserLogin(), $config->getProjectPid());
            $this->getLogger()->debug("Service account for data loads ({$config->getUserLogin()}) added to "
                . "the project using GoodData Provisioning");
            $gdClient->login($config->getUserLogin(), $config->getUserPassword());
        }

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
