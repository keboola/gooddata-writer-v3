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
        $fp = file_get_contents(__DIR__.'/../config.json');
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

        $this->gdClient = new Client('https://secure.gooddata.com');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
    }


    public function testRun(): void
    {
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));

        // Run
        $config = $this->config;
        $config['action'] = 'run';

        $specification = new DatadirTestSpecification(
            __DIR__ . '/run/source/data',
            0,
            '',
            '',
            __DIR__ . '/run/expected/data/out'
        );
        $tempDatadir = $this->getTempDatadir($specification);
        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $res = $this->gdClient->get('/gdc/md/' . getenv('GD_PID') . '/data/sets');
        $this->assertCount(5, $res['dataSetsInfo']['sets']);
        $this->assertArrayHasKey('lastSuccess', $res['dataSetsInfo']['sets'][0]);
        // Assert that last data load occured within a minute
        $this->assertTrue(time() < 60 + strtotime($res['dataSetsInfo']['sets'][0]['lastSuccess']));

        // Read model
        $configId = uniqid();
        $config = $this->config;
        $config['action'] = 'readModel';
        $config['parameters']['bucket'] = 'in.c-gd-model';
        $config['parameters']['configurationId'] = $configId;

        $components = new Components(new \Keboola\StorageApi\Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]));
        $components->addConfiguration((new Configuration())
            ->setName($configId)
            ->setComponentId('keboola.gooddata-writer')
            ->setConfigurationId($configId));

        $specification = new DatadirTestSpecification(
            __DIR__ . '/read-model/source/data',
            0,
            '[]',
            null,
            __DIR__ . '/read-model/expected/data/out'
        );
        $tempDatadir = $this->getTempDatadir($specification);

        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $res = $components->getConfiguration('keboola.gooddata-writer', $configId);
        $this->assertArrayHasKey('configuration', $res);
        $this->assertArrayHasKey('dimensions', $res['configuration']);
        $this->assertCount(1, $res['configuration']['dimensions']);
        $this->assertArrayHasKey('tables', $res['configuration']);
        $this->assertCount(3, $res['configuration']['tables']);

        $components->deleteConfiguration('keboola.gooddata-writer', $configId);
    }
}
