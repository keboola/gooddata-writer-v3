<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\Component\Config\BaseConfig;
use Keboola\Component\Logger;
use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\GoodDataWriter\App;
use Keboola\GoodDataWriter\Config;
use Keboola\GoodDataWriter\ConfigDefinition;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * @covers \Keboola\GoodDataWriter\App
 * @covers \Keboola\GoodDataWriter\Upload
 */
class AppTest extends TestCase
{
    /** @var Client  */
    protected $gdClient;
    /** @var Temp */
    protected $temp;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->gdClient = new Client('https://keboola-fork-bomb.on.gooddata.com');
        $this->gdClient->setUserAgent('gooddata-writer-v3', 'test');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
    }

    protected function initApp(): App
    {
        system('rm -rf ' . sys_get_temp_dir() . '/productdate');
        $logger = new Logger();

        $this->temp = new Temp();
        $this->temp->initRunFolder();

        return new App($logger, $this->temp, $this->gdClient);
    }

    public function testGetEnabledTables(): void
    {
        $app = $this->initApp();
        $params = json_decode((string) file_get_contents(__DIR__ . '/../config.json'), true);
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');
        $config = new Config($params, new ConfigDefinition());
        $this->assertCount(3, $app->getEnabledTables($config));
        $params['parameters']['tables']['out.c-main.categories']['disabled'] = true;
        $config = new Config($params, new ConfigDefinition());
        $this->assertCount(2, $app->getEnabledTables($config));
    }

    public function testResortColumns(): void
    {
        $app = $this->initApp();
        $this->assertEquals(
            ['c1' => [], 'c2' => [], 'c3' => []],
            $app->resortColumns(
                'tableId',
                ['columns' => ['c1', 'c2', 'c3']],
                ['columns' => ['c3' => [], 'c1' => [], 'c2' => []]]
            )
        );
    }

    public function testEnhanceTableDefinitionFromMapping(): void
    {
        $app = $this->initApp();
        $this->assertEquals(
            ['columns' => ['c1' => [], 'c2' => [], 'c3' => []], 'incremental' => true],
            $app->enhanceTableDefinitionFromMapping(
                'tableId',
                ['columns' => ['c1', 'c2', 'c3'], 'changed_since' => 'yesterday'],
                ['columns' => ['c3' => [], 'c1' => [], 'c2' => []]]
            )
        );
    }

    public function testAppRunSingle(): void
    {
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));
        $app = $this->initApp();
        $params = json_decode((string) file_get_contents(__DIR__ . '/../config.json'), true);
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');

        $this->assertCount(0, $this->getDataSets((string) getenv('GD_PID')));
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
        $this->assertCount(6, $this->getDataSets((string) getenv('GD_PID')));

        // Run again to test update model on empty model change
        system("rm -rf {$this->temp->getTmpFolder()}/*");
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');

        // Run again with loadOnly
        system("rm -rf {$this->temp->getTmpFolder()}/*");
        $params['parameters']['loadOnly'] = true;
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
    }

    public function testAppRunMulti(): void
    {
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));
        system('rm -rf ' . sys_get_temp_dir() . '/productdate');

        $app = $this->initApp();
        $params = json_decode((string) file_get_contents(__DIR__ . '/../config.json'), true);
        $params['parameters']['multiLoad'] = true;
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');

        $this->assertCount(0, $this->getDataSets((string) getenv('GD_PID')));
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
        $this->assertCount(6, $this->getDataSets((string) getenv('GD_PID')));

        // Run again to test update model on empty model change
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');

        // Run again with loadOnly
        $params['parameters']['loadOnly'] = true;
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
    }

    public function testProjectNotFound(): void
    {
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));
        $app = $this->initApp();
        $params = json_decode((string) file_get_contents(__DIR__ . '/../config.json'), true);
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = 'invalid-pid';

        $this->expectExceptionMessage("GoodData user does not have access to resource '/gdc/projects/invalid-pid'. " .
            'Invalid operation');
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
    }

    protected function getDataSets(string $pid): array
    {
        $call = $this->gdClient->get("/gdc/md/$pid/data/sets");
        $existingDataSets = [];
        foreach ($call['dataSetsInfo']['sets'] as $r) {
            $existingDataSets[] = $r['meta']['identifier'];
        }
        return $existingDataSets;
    }

    public function testReadModel(): void
    {
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));

        $app = $this->initApp();
        $params = json_decode((string) file_get_contents(__DIR__ . '/../config.json'), true);
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');

        $config = new Config($params, new ConfigDefinition());

        $this->assertCount(0, $this->getDataSets((string) getenv('GD_PID')));
        $app->run($config, __DIR__ . '/tables');
        $this->assertCount(6, $this->getDataSets((string) getenv('GD_PID')));

        $configId = uniqid();
        unset($params['parameters']['tables']);
        unset($params['parameters']['dimensions']);
        $params['parameters']['bucket'] = 'in.c-data';
        $params['parameters']['configurationId'] = $configId;
        $config = new Config($params, new ConfigDefinition());

        $client = new \Keboola\StorageApi\Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
        $components = new Components($client);
        $temp = new Temp();
        $temp->initRunFolder();
        $components->addConfiguration((new Configuration())
            ->setComponentId('keboola.gooddata-writer')
            ->setConfigurationId($configId)
            ->setName($configId));
        $app->readModel($config);

        $config = $components->getConfiguration('keboola.gooddata-writer', $configId);
        $this->assertArrayHasKey('parameters', $config['configuration']);
        $resConfig = $config['configuration']['parameters'];
        $this->assertArrayHasKey('dimensions', $resConfig);
        $this->assertCount(2, $resConfig['dimensions']);
        $this->assertEquals('productdate', current($resConfig['dimensions'])['identifier']);
        $this->assertArrayHasKey('tables', $resConfig);
        $this->assertCount(3, $resConfig['tables']);
        $this->assertArrayHasKey('in.c-data.categories', $resConfig['tables']);
        $this->assertArrayHasKey('in.c-data.products', $resConfig['tables']);
        $this->assertArrayHasKey('in.c-data.productsgrain', $resConfig['tables']);
        $this->assertArrayHasKey('storage', $config['configuration']);
        $this->assertArrayHasKey('input', $config['configuration']['storage']);
        $this->assertArrayHasKey('tables', $config['configuration']['storage']['input']);
        $this->assertCount(3, $config['configuration']['storage']['input']['tables']);

        try {
            $config = new BaseConfig($config, new ConfigDefinition());
        } catch (InvalidConfigurationException $e) {
            $this->fail('Check of model read against ConfigDefinition failed');
        }

        $components->deleteConfiguration('keboola.gooddata-writer', $configId);
        $client->dropBucket('in.c-data', ['force' => true]);
    }
}
