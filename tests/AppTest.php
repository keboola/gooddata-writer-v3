<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodData\Client;
use Keboola\GoodData\Exception;
use Keboola\GoodDataWriter\App;
use Keboola\GoodDataWriter\Config;
use Keboola\GoodDataWriter\ConfigDefinition;
use Keboola\GoodDataWriter\ProvisioningClient;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Keboola\GoodDataWriter\App
 * @covers \Keboola\GoodDataWriter\Upload
 */
class AppTest extends TestCase
{
    /** @var Client  */
    protected $gdClient;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->gdClient = new Client();
        $this->gdClient->setUserAgent('gooddata-writer-v3', 'test');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
        $this->cleanUpProject(getenv('GD_PID'));
    }

    protected function initApp(): App
    {
        $logger = new NullLogger();

        $temp = new Temp();
        $temp->initRunFolder();

        $provisioning = new ProvisioningClient(
            getenv('PROVISIONING_URL'),
            getenv('KBC_TOKEN'),
            $logger
        );

        return new App($logger, $temp, $this->gdClient, $provisioning);
    }

    public function testGetEnabledTables(): void
    {
        $app = $this->initApp();
        $params = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
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
        $app = $this->initApp();
        $params = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');

        $this->assertCount(0, $this->getDataSets(getenv('GD_PID')));
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
        $this->assertCount(5, $this->getDataSets(getenv('GD_PID')));
    }

    public function testAppRunMulti(): void
    {
        $this->cleanUpProject(getenv('GD_PID'));
        system('rm -rf ' . sys_get_temp_dir() . '/productdate');

        $app = $this->initApp();
        $params = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
        $params['parameters']['multiLoad'] = true;
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');

        $this->assertCount(0, $this->getDataSets(getenv('GD_PID')));
        $app->run(new Config($params, new ConfigDefinition()), __DIR__ . '/tables');
        $this->assertCount(5, $this->getDataSets(getenv('GD_PID')));
    }

    protected function cleanUpProject(string $pid): void
    {
        do {
            $error = false;
            $datasets = $this->gdClient->get("/gdc/md/$pid/data/sets");
            foreach ($datasets['dataSetsInfo']['sets'] as $dataset) {
                try {
                    $this->gdClient->getDatasets()->executeMaql(
                        $pid,
                        'DROP ALL IN {' . $dataset['meta']['identifier'] . '} CASCADE'
                    );
                } catch (Exception $e) {
                    $error = true;
                }
            }
        } while ($error);

        $folders = $this->gdClient->get("/gdc/md/$pid/query/folders");
        foreach ($folders['query']['entries'] as $folder) {
            try {
                $this->gdClient->getDatasets()->executeMaql(
                    $pid,
                    'DROP {'.$folder['identifier'].'};'
                );
            } catch (Exception $e) {
            }
        }
        $dimensions = $this->gdClient->get("/gdc/md/$pid/query/dimensions");
        foreach ($dimensions['query']['entries'] as $folder) {
            try {
                $this->gdClient->getDatasets()->executeMaql($pid, 'DROP {'.$folder['identifier'].'};');
            } catch (Exception $e) {
            }
        }
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
}
