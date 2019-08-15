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

        // 1.
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
        // Assert that last data load occurred within a minute
        $this->assertTrue(time() < 60 + strtotime($res['dataSetsInfo']['sets'][0]['lastSuccess']));

        // 2.
        // Run again load data only after disabling of table which is being referenced to
        $config['parameters']['tables']['out.c-main.categories']['disabled'] = true;
        $config['parameters']['loadOnly'] = true;
        file_put_contents($tempDatadir->getTmpFolder() . '/config.json', \GuzzleHttp\json_encode($config));
        $process = $this->runScript($tempDatadir->getTmpFolder());
        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        // 3.
        // Read model
        $configId = uniqid();
        $config = $this->config;
        $config['action'] = 'readModel';
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
            '{
    "dimensions": {
        "product date": {
            "identifier": "productdate",
            "includeTime": 1,
            "template": "GOODDATA"
        }
    },
    "tables": {
        "in.c-gd-model.categories": {
            "identifier": "dataset.outcmaincategories",
            "title": "categories",
            "columns": {
                "cp_id": {
                    "identifier": "attr.outcmaincategories.id",
                    "title": "id",
                    "type": "CONNECTION_POINT",
                    "identifierLabel": "label.outcmaincategories.id",
                    "dataType": "VARCHAR",
                    "dataTypeSize": "128"
                },
                "a_name": {
                    "identifier": "attr.outcmaincategories.name",
                    "title": "name",
                    "type": "ATTRIBUTE",
                    "identifierLabel": "label.outcmaincategories.name",
                    "dataType": "VARCHAR",
                    "dataTypeSize": "128"
                },
                "f_order": {
                    "identifier": "fact.outcmaincategories.order",
                    "title": "order",
                    "type": "FACT",
                    "dataType": "DECIMAL",
                    "dataTypeSize": "12,2"
                }
            },
            "grain": []
        },
        "in.c-gd-model.products": {
            "identifier": "dataset.outcmainproducts",
            "title": "products",
            "columns": {
                "cp_id": {
                    "identifier": "attr.outcmainproducts.id",
                    "title": "id",
                    "type": "CONNECTION_POINT",
                    "identifierLabel": "label.outcmainproducts.id",
                    "dataType": "VARCHAR",
                    "dataTypeSize": "128"
                },
                "info": {
                    "identifier": "label.outcmainproducts.id.info",
                    "title": "info",
                    "type": "LABEL",
                    "reference": "cp_id"
                },
                "a_name": {
                    "identifier": "attr.outcmainproducts.name",
                    "title": "name",
                    "type": "ATTRIBUTE",
                    "identifierLabel": "label.outcmainproducts.name",
                    "dataType": "VARCHAR",
                    "dataTypeSize": "128"
                },
                "f_price": {
                    "identifier": "fact.outcmainproducts.price",
                    "title": "price",
                    "type": "FACT",
                    "dataType": "DECIMAL",
                    "dataTypeSize": "12,2"
                },
                "datasetoutcmaincategories": {
                    "type": "REFERENCE",
                    "schemaReference": "in.c-gd-model.categories"
                },
                "productdate": {
                    "type": "DATE",
                    "dateDimension": "product date",
                    "format": "yyyy-MM-dd"
                }
            },
            "grain": []
        },
        "in.c-gd-model.productsgrain": {
            "identifier": "dataset.outcmainproductsgrain",
            "title": "products-grain",
            "columns": {
                "a_id": {
                    "identifier": "attr.outcmainproductsgrain.id",
                    "title": "id",
                    "type": "ATTRIBUTE",
                    "identifierLabel": "label.outcmainproductsgrain.id",
                    "dataType": "VARCHAR",
                    "dataTypeSize": "128"
                },
                "info": {
                    "identifier": "label.outcmainproductsgrain.id.info",
                    "title": "info",
                    "type": "LABEL",
                    "reference": "a_id"
                },
                "a_name": {
                    "identifier": "attr.outcmainproductsgrain.name",
                    "title": "name",
                    "type": "ATTRIBUTE",
                    "identifierLabel": "label.outcmainproductsgrain.name",
                    "dataType": "VARCHAR",
                    "dataTypeSize": "128"
                },
                "f_price": {
                    "identifier": "fact.outcmainproductsgrain.price",
                    "title": "price",
                    "type": "FACT",
                    "dataType": "DECIMAL",
                    "dataTypeSize": "12,2"
                },
                "datasetoutcmaincategories": {
                    "type": "REFERENCE",
                    "schemaReference": "in.c-gd-model.categories"
                },
                "productdate": {
                    "type": "DATE",
                    "dateDimension": "product date",
                    "format": "yyyy-MM-dd"
                }
            },
            "grain": [
                "productdate",
                "a_id",
                "datasetoutcmaincategories"
            ],
            "anchorIdentifier": "attr.outcmainproductsgrain.factsof"
        }
    }
}',
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
        $this->assertCount(1, $res['configuration']['parameters']['dimensions']);
        $this->assertArrayHasKey('tables', $res['configuration']['parameters']);
        $this->assertCount(3, $res['configuration']['parameters']['tables']);
        $this->assertArrayHasKey('storage', $res['configuration']);
        $this->assertArrayHasKey('input', $res['configuration']['storage']);
        $this->assertArrayHasKey('tables', $res['configuration']['storage']['input']);
        $this->assertCount(3, $res['configuration']['storage']['input']['tables']);

        $components->deleteConfiguration('keboola.gooddata-writer', $configId);
        $storageClient->dropBucket('in.c-gd-model', ['force' => true]);
    }
}
