<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodData\Client;
use Keboola\GoodDataWriter\App;
use Keboola\GoodDataWriter\Config;
use Keboola\GoodDataWriter\ConfigDefinition;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ProvisioningTest extends TestCase
{
    /** @var Client  */
    protected $gdClient;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->gdClient = new Client();
        $this->gdClient->setUserAgent('gooddata-writer-v3', 'test');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
    }

    public function testProvisioningAccessHasAccess(): void
    {
        $logger = new NullLogger();

        $temp = new Temp();
        $temp->initRunFolder();

        $app = new App($logger, $temp, $this->gdClient);

        $config = new Config([
            'parameters' => [
                'project' => ['pid' => getenv('GD_PID')],
                'user' => [
                    'login' => getenv('GD_USERNAME'),
                    '#password' => getenv('GD_PASSWORD'),
                ],
                'tables' => [],
            ],
        ], new ConfigDefinition());

        $this->assertTrue($app->checkProjectAccess($config));
    }

    public function testProvisioningProjectAccess(): void
    {
        $logger = new NullLogger();

        $temp = new Temp();
        $temp->initRunFolder();

        $app = new App($logger, $temp, $this->gdClient);

        $config = new Config([
            'parameters' => [
                'project' => ['pid' => getenv('GD_PID_2')],
                'user' => [
                    'login' => getenv('GD_USERNAME'),
                    '#password' => getenv('GD_PASSWORD'),
                ],
                'tables' => [],
            ],
        ], new ConfigDefinition());

        $this->assertTrue($app->checkProjectAccess($config));
    }
}
