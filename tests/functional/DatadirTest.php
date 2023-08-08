<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Tests\Functional;

use Keboola\DatadirTests\AbstractDatadirTestCase;
use Keboola\DatadirTests\DatadirTestSpecification;
use Keboola\GoodData\Client;
use Keboola\GoodDataWriter\Test\ApiHelper;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;

class DatadirTest extends AbstractDatadirTestCase
{
    /** @var array */
    protected $config;
    /** @var Client */
    protected $gdClient;

    public function setup(): void
    {
        parent::setUp();
        system('rm -rf ' . sys_get_temp_dir() . '/productdate');
        $this->loadConfig(__DIR__ . '/../config.json');
        $this->gdClient = new Client('https://keboola-fork-bomb.on.gooddata.com');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
    }


    public function testRun(): void
    {
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));

        // 1.
        // Run
        $config = $this->config;
        $config['action'] = 'run';
        $config['parameters']['project']['backendUrl'] = 'https://keboola-fork-bomb.on.gooddata.com/';

        $specification = new DatadirTestSpecification(
            __DIR__ . '/run/source/data',
            0,
            'Created dimension product date
Created dimension test-date
Model updated
Csv for table out.c-main.categories created
Upload package manifest for table out.c-main.categories transferred to GoodData
table out.c-main.categories data fully loaded to GoodData
Csv for table out.c-main.products created
Upload package manifest for table out.c-main.products transferred to GoodData
table out.c-main.products data fully loaded to GoodData
',
            '',
            __DIR__ . '/run/expected/data/out'
        );
        $tempDatadir = $this->getTempDatadir($specification);
        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $res = $this->gdClient->get('/gdc/md/' . getenv('GD_PID') . '/data/sets');
        $this->assertCount(6, $res['dataSetsInfo']['sets']);
        $this->assertArrayHasKey('lastSuccess', $res['dataSetsInfo']['sets'][0]);
        // Assert that last data load occurred within a minute
        $this->assertTrue(time() < 60 + strtotime($res['dataSetsInfo']['sets'][0]['lastSuccess']));

        // 2.
        // Run again load data only after disabling of table which is being referenced to
        $specification = new DatadirTestSpecification(
            __DIR__ . '/run/source/data',
            0,
            'Csv for table out.c-main.products created
Upload package manifest for table out.c-main.products transferred to GoodData
table out.c-main.products data fully loaded to GoodData
',
            '',
            __DIR__ . '/run/expected/data/out'
        );
        $config['parameters']['tables']['out.c-main.categories']['disabled'] = true;
        $config['parameters']['loadOnly'] = true;
        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        // 3.
        // Read model
        $configId = uniqid();
        $config = $this->config;
        $config['parameters']['action'] = 'readModel';
        $config['parameters']['bucket'] = 'in.c-gd-model';
        $config['parameters']['configurationId'] = $configId;

        $storageClient = new \Keboola\StorageApi\Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
        $components = new Components($storageClient);
        $components->addConfiguration((new Configuration())
            ->setName($configId)
            ->setComponentId('keboola.gooddata-writer')
            ->setConfigurationId($configId));

        $specification = new DatadirTestSpecification(
            __DIR__ . '/read-model/source/data',
            0,
            'Table in.c-gd-model.categories created
Table in.c-gd-model.products created
Table in.c-gd-model.productsgrain created
',
            null,
            __DIR__ . '/read-model/expected/data/out'
        );
        $tempDatadir = $this->getTempDatadir($specification);

        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $res = $components->getConfiguration('keboola.gooddata-writer', $configId);
        $this->assertArrayHasKey('configuration', $res);
        $this->assertArrayHasKey('parameters', $res['configuration']);
        $this->assertArrayHasKey('dimensions', $res['configuration']['parameters']);
        $this->assertCount(2, $res['configuration']['parameters']['dimensions']);
        $this->assertArrayHasKey('tables', $res['configuration']['parameters']);
        $this->assertCount(3, $res['configuration']['parameters']['tables']);
        $this->assertArrayHasKey('storage', $res['configuration']);
        $this->assertArrayHasKey('input', $res['configuration']['storage']);
        $this->assertArrayHasKey('tables', $res['configuration']['storage']['input']);
        $this->assertCount(3, $res['configuration']['storage']['input']['tables']);

        $components->deleteConfiguration('keboola.gooddata-writer', $configId);
        $storageClient->dropBucket('in.c-gd-model', ['force' => true]);
    }

    public function testRunWithRelationships(): void
    {
        $this->loadConfig(__DIR__ . '/run-with-relationships/source/data/config.json');
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));

        // 1.
        // Run
        $config = $this->config;

        $specification = new DatadirTestSpecification(
            __DIR__ . '/run/source/data',
            0,
            'Created dimension product date
Created dimension test-date
Model updated
Csv for table out.c-main.categories created
Upload package manifest for table out.c-main.categories transferred to GoodData
table out.c-main.categories data fully loaded to GoodData
Csv for table out.c-main.products created
Upload package manifest for table out.c-main.products transferred to GoodData
table out.c-main.products data fully loaded to GoodData
',
            '',
            __DIR__ . '/run/expected/data/out'
        );
        $tempDatadir = $this->getTempDatadir($specification);
        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $res = $this->gdClient->get('/gdc/md/' . getenv('GD_PID') . '/data/sets');
        $this->assertCount(6, $res['dataSetsInfo']['sets']);
        $this->assertArrayHasKey('lastSuccess', $res['dataSetsInfo']['sets'][0]);
        // Assert that last data load occurred within a minute
        $this->assertTrue(time() < 60 + strtotime($res['dataSetsInfo']['sets'][0]['lastSuccess']));

        // 2.
        // Run again load data only after disabling of table which is being referenced to
        $specification = new DatadirTestSpecification(
            __DIR__ . '/run/source/data',
            0,
            'Csv for table out.c-main.products created
Upload package manifest for table out.c-main.products transferred to GoodData
table out.c-main.products data fully loaded to GoodData
',
            '',
            __DIR__ . '/run/expected/data/out'
        );
        $config['parameters']['tables']['out.c-main.categories']['disabled'] = true;
        $config['parameters']['loadOnly'] = true;
        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        // 3.
        // Read model
        $configId = uniqid();
        $config = $this->config;
        $config['parameters']['action'] = 'readModel';
        $config['parameters']['bucket'] = 'in.c-gd-model';
        $config['parameters']['configurationId'] = $configId;

        $storageClient = new \Keboola\StorageApi\Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
        $components = new Components($storageClient);
        $components->addConfiguration((new Configuration())
            ->setName($configId)
            ->setComponentId('keboola.gooddata-writer')
            ->setConfigurationId($configId));

        $specification = new DatadirTestSpecification(
            __DIR__ . '/read-model/source/data',
            0,
            'Table in.c-gd-model.categories created
Table in.c-gd-model.products created
Table in.c-gd-model.productsgrain created
',
            null,
            __DIR__ . '/read-model/expected/data/out'
        );
        $tempDatadir = $this->getTempDatadir($specification);

        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $res = $components->getConfiguration('keboola.gooddata-writer', $configId);
        $this->assertArrayHasKey('configuration', $res);
        $this->assertArrayHasKey('parameters', $res['configuration']);
        $this->assertArrayHasKey('dimensions', $res['configuration']['parameters']);
        $this->assertCount(2, $res['configuration']['parameters']['dimensions']);
        $this->assertArrayHasKey('tables', $res['configuration']['parameters']);
        $this->assertCount(3, $res['configuration']['parameters']['tables']);
        $this->assertArrayHasKey('storage', $res['configuration']);
        $this->assertArrayHasKey('input', $res['configuration']['storage']);
        $this->assertArrayHasKey('tables', $res['configuration']['storage']['input']);
        $this->assertCount(3, $res['configuration']['storage']['input']['tables']);

        $components->deleteConfiguration('keboola.gooddata-writer', $configId);
        $storageClient->dropBucket('in.c-gd-model', ['force' => true]);
    }

    protected function loadConfig(string $path): void
    {
        $fp = file_get_contents($path);
        if ($fp === false) {
            throw new \Exception('config.json not found');
        }
        $this->config = \GuzzleHttp\json_decode($fp, true);
        $this->config['parameters']['user'] = [
            'login' => getenv('GD_USERNAME'),
            '#password' => getenv('GD_PASSWORD'),
        ];
        $this->config['parameters']['project'] = [
            'pid' => getenv('GD_PID'),
        ];
    }
}
