<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\Component\Logger;
use Keboola\GoodData\Client;
use Keboola\GoodData\Identifiers;
use Keboola\GoodDataWriter\Model;
use Keboola\GoodDataWriter\ModelReader;
use PHPUnit\Framework\TestCase;

class ModelReaderTest extends TestCase
{
    /** @var Client  */
    protected $gdClient;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->gdClient = new Client();
        $this->gdClient->setUserAgent('gooddata-writer-v3', 'test');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
        ApiHelper::cleanUpProject($this->gdClient, (string) getenv('GD_PID'));
    }

    public function testModelReaderGetGrain(): void
    {
        $tableId = 'dataset.'.uniqid();
        $model = Model::getProjectLDM([
            'dataSets' => [
                $tableId => [
                    'title' => $tableId,
                    'identifier' => $tableId,
                    'columns' => [
                        'attr1' => [
                            'title' => 'Attr 1',
                            'type' => 'ATTRIBUTE',
                        ],
                        'attr2' => [
                            'title' => 'Attr 2',
                            'type' => 'ATTRIBUTE',
                        ],
                        'fact1' => [
                            'title' => 'Fact 1',
                            'type' => 'FACT',
                        ],
                    ],
                    'grain' => ['attr1', 'attr2'],
                ],
            ],
            'dimensions' => [],
        ]);
        $this->gdClient->getProjectModel()->updateProject((string) getenv('GD_PID'), $model);

        $modelReader = new ModelReader($this->gdClient, new Logger());
        $result = $modelReader->getGrain(
            (string) getenv('GD_PID'),
            Identifiers::getImplicitConnectionPointId($tableId)
        );
        $this->assertCount(2, $result);
        $this->assertTrue(in_array(Identifiers::getAttributeId($tableId, 'attr1'), $result));
        $this->assertTrue(in_array(Identifiers::getAttributeId($tableId, 'attr2'), $result));
    }

    public function testModelReaderGetDateDimensionTemplate(): void
    {
        $dimension = 'Dimension '.uniqid();
        $template = 'keboola';
        $this->gdClient->getDateDimensions()->create((string) getenv('GD_PID'), $dimension, null, $template);

        $modelReader = new ModelReader($this->gdClient, new Logger());
        $this->assertEquals(
            strtoupper($template),
            $modelReader->getDateDimensionTemplate((string) getenv('GD_PID'), $dimension)
        );
    }
}
