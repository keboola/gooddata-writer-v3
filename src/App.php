<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Keboola\GoodData\Client;
use Keboola\GoodData\Exception;
use Keboola\Temp\Temp;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Output\ConsoleOutput;

class App
{
    /** @var  ConsoleOutput */
    private $consoleOutput;

    /** @var  Client */
    protected $gdClient;

    /** @var  Temp */
    protected $temp;

    /** @var  Logger */
    protected $logger;

    public function __construct($consoleOutput)
    {
        $this->consoleOutput = $consoleOutput;
        $this->logger = new Logger('gooddata-writer', [new StreamHandler('php://stdout')]);
        $this->temp = new Temp();
        $this->temp->initRunFolder();
    }

    public function checkProjectAccess(Client $gdClient, ProvisioningClient $provisioningClient, array $config)
    {
        try {
            $gdClient->get("/gdc/projects/{$config['parameters']['project']['pid']}");
        } catch (Exception $e) {
            if ($e->getCode() !== 403) {
                throw $e;
            }

            if (!getenv('KBC_TOKEN')) {
                throw new \Exception('KBC Token is missing from the environment');
            }
            $provisioningClient->addUserToProject(
                $config['parameters']['user']['login'],
                $config['parameters']['project']['pid']
            );
        }
        return true;
    }

    public function run($config, $inputPath)
    {
        Config::check($config);
        $gdClient = $this->getGoodDataClient($config);
        $provisioningClient = new ProvisioningClient(
            $config['image_parameters']['provisioning_url'],
            getenv('KBC_TOKEN'),
            $this->logger
        );
        $this->checkProjectAccess($gdClient, $provisioningClient, $config);
        $projectDefinition = [
            'dataSets' => $config['parameters']['tables'],
            'dimensions' => $config['parameters']['dimensions']
        ];

        if (empty($config['parameters']['loadOnly'])) {
            $projectModel = Model::getProjectLDM($projectDefinition);

            // Date dimensions
            if (isset($config['parameters']['dimensions']) && count($config['parameters']['dimensions'])) {
                foreach ($config['parameters']['dimensions'] as $dimensionName => $dimension) {
                    $gdClient->createDateDimension([
                        'pid' => $config['parameters']['project']['pid'],
                        'name' => $dimensionName,
                        'includeTime' => !empty($dimension['includeTime']),
                        'template' => !empty($dimension['template']) ? $dimension['template'] : null,
                        'identifier' => !empty($dimension['identifier']) ? $dimension['identifier'] : null
                    ]);
                }
            }

            // Update model
            $gdClient->getProjectModel()->updateProject($config['parameters']['project']['pid'], $projectModel);
        }

        // Load data
        if (!empty($config['parameters']['multiLoad'])) {
            $tmpDir = $this->temp->getTmpFolder();
            $upload = new Upload($gdClient, $this->logger, $tmpDir);
            $definitions = [];
            foreach ($config['storage']['input']['tables'] as $table) {
                $tableId = $table['source'];
                $tableDef = Model::enhanceDefinition(
                    $tableId,
                    $config['parameters']['tables'][$tableId],
                    $projectDefinition
                );

                $definitions[] = $tableDef;
            }
            $upload->createMultiLoadManifest($definitions);
            $upload->upload($config);
        } else {
            foreach ($config['storage']['input']['tables'] as $table) {
                $tableId = $table['source'];
                $tableDef = Model::enhanceDefinition(
                    $tableId,
                    $config['parameters']['tables'][$tableId],
                    $projectDefinition
                );

                $tmpDir = $this->temp->getTmpFolder() . '/' . $tableId;
                mkdir($tmpDir);
                $upload = new Upload($gdClient, $this->logger, $tmpDir);

                $upload->createSingleLoadManifest($tableDef);
                $upload->createCsv("$inputPath/{$table['destination']}", $tableDef);
                $upload->upload($config, $tableId);
            }
        }
    }

    public function getGoodDataClient($config)
    {
        if (!$this->gdClient) {
            $this->gdClient = new Client();
            $this->gdClient->setUserAgent('gooddata-writer-v3', getenv('KBC_RUNID'));
            if (isset($config['parameters']['project']['backendUrl'])) {
                $this->gdClient->setApiUrl($config['parameters']['project']['backendUrl']);
                $this->gdClient->disableCheckDomain();
            }
            $this->gdClient->login($config['parameters']['user']['login'], $config['parameters']['user']['#password']);
            $this->gdClient->setLogger($this->logger);
        }
        return $this->gdClient;
    }
}
