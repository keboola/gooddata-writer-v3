<?php
namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodData\Client;
use Keboola\GoodData\Exception;
use Keboola\GoodDataWriter\App;
use Keboola\GoodDataWriter\ProvisioningClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutput;

class ProvisioningTest extends TestCase
{
    protected $gdClient;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->gdClient = new Client();
        $this->gdClient->setUserAgent('gooddata-writer-v3', 'test');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
    }

    public function testProvisioningAccessHasAccess()
    {
        $config = [
            'parameters' => [
                'project' => ['pid' => getenv('GD_PID')],
                'user' => ['login' => getenv('GD_USERNAME'), '#password' => getenv('GD_PASSWORD')],
            ],
            'image_parameters' => ['provisioning_url' => getenv('PROVISIONING_URL')],
        ];

        $app = new App(new ConsoleOutput());
        $provisioningClient = \Mockery::mock(ProvisioningClient::class);
        $provisioningClient->shouldNotReceive('addUserToProject');
        $this->assertTrue($app->checkProjectAccess($this->gdClient, $provisioningClient, $config));
    }

    public function testProvisioningProjectAccess()
    {
        $config = [
            'parameters' => [
                'project' => ['pid' => getenv('GD_PID_2')],
                'user' => ['login' => getenv('GD_USERNAME'), '#password' => getenv('GD_PASSWORD')],
            ],
            'image_parameters' => ['provisioning_url' => getenv('PROVISIONING_URL')],
        ];

        $app = new App(new ConsoleOutput());
        $provisioningClient = \Mockery::mock(ProvisioningClient::class);
        $provisioningClient->shouldReceive('addUserToProject')->times(1);
        $this->assertTrue($app->checkProjectAccess($this->gdClient, $provisioningClient, $config));
    }
}
