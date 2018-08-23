<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\BaseComponent;

class Component extends BaseComponent
{
    public function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();

        if (!file_exists($this->getDataDir() . '/out')) {
            mkdir($this->getDataDir() . '/out');
        }
        if (!file_exists($this->getDataDir() . '/out/tables')) {
            mkdir($this->getDataDir() . '/out/tables');
        }

        $api = new SklikApi($config->getToken(), $this->getLogger());
        $userStorage = new UserStorage($this->getDataDir() . '/out/tables');
        $extractor = new Extractor($api, $userStorage, $this->getLogger());
        $extractor->run($config);
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
