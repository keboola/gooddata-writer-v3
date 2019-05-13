<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodDataWriter\ConfigurationStorage;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use PHPUnit\Framework\TestCase;

class ConfigurationStorageTest extends TestCase
{

    public function testUpdateConfiguration(): void
    {
        $configId = uniqid();
        $client = new \Keboola\StorageApi\Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
        $components = new Components($client);

        $backendUrl = uniqid();
        $pid = uniqid();
        $login = uniqid();
        $pass = uniqid();

        $config = (new Configuration())
            ->setComponentId('keboola.gooddata-writer')
            ->setConfigurationId($configId)
            ->setName($configId)
            ->setConfiguration([
                'project' => [
                    'backendUrl' => $backendUrl,
                    'pid' => $pid,
                ],
                'user' => [
                    'login' => $login,
                    'password' => $pass,
                ],
                'tables' => [
                    't1' => ['tt1'],
                ],
            ]);
        $components->addConfiguration($config);

        $storage = new ConfigurationStorage($client);
        $storage->updateConfiguration($configId, [
            'tables' => [
                't2' => ['tt2'],
            ],
            'dimensions' => [
                'd1' => ['dd1'],
            ],
        ]);

        $result = $components->getConfiguration('keboola.gooddata-writer', $configId);
        $resConfig = $result['configuration'];
        $this->assertArrayHasKey('project', $resConfig);
        $this->assertArrayHasKey('pid', $resConfig['project']);
        $this->assertEquals($pid, $resConfig['project']['pid']);
        $this->assertArrayHasKey('user', $resConfig);
        $this->assertArrayHasKey('login', $resConfig['user']);
        $this->assertEquals($login, $resConfig['user']['login']);
        $this->assertArrayHasKey('tables', $resConfig);
        $this->assertArrayHasKey('t1', $resConfig['tables']);
        $this->assertEquals(['tt1'], $resConfig['tables']['t1']);
        $this->assertArrayHasKey('t2', $resConfig['tables']);
        $this->assertEquals(['tt2'], $resConfig['tables']['t2']);
        $this->assertArrayHasKey('dimensions', $resConfig);
        $this->assertArrayHasKey('d1', $resConfig['dimensions']);
        $this->assertEquals(['dd1'], $resConfig['dimensions']['d1']);
    }
}
