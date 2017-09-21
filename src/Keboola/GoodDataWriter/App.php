<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Keboola\GoodData\Client;
use Keboola\GoodData\Datasets;
use Keboola\GoodData\Exception;
use Keboola\GoodData\WebDav;
use Keboola\Temp\Temp;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

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

    public function run($config, $inputPath)
    {
        $this->checkConfig($config);
        $pid = $config['parameters']['project']['pid'];

        $this->gdClient = $this->initGoodDataClient($config);
        $this->gdClient->setLogger($this->logger);

        // Date dimensions
        if (isset($config['parameters']['dimensions']) && count($config['parameters']['dimensions'])) {
            foreach ($config['parameters']['dimensions'] as $dimensionName => $dimension) {
                $this->createDateDimension($pid, $dimensionName, $dimension);
            }
        }

        // Update model
        $projectDefinition = [
            'dataSets' => $config['parameters']['tables'],
            'dimensions' => $config['parameters']['dimensions']
        ];
        $projectModel = Model::getProjectLDM($projectDefinition);
        $this->gdClient->getProjectModel()->updateProject($pid, $projectModel);

        // Load data
        if (!empty($config['parameters']['multi'])) {
            $tmpDir = $this->temp->getTmpFolder();
            $filesToUpload = [];
            $definitions = [];
            foreach ($config['storage']['input']['tables'] as $table) {
                $tableId = $table['source'];
                $tableDef = Model::enhanceDefinition(
                    $tableId,
                    $config['parameters']['tables'][$tableId],
                    $projectDefinition
                );

                $filesToUpload[] = $this->createCsv("$inputPath/{$table['destination']}", $tableDef, $tmpDir);
                $definitions[] = $tableDef;
            }
            $filesToUpload[] = $this->createMultiLoadManifest($definitions, $tmpDir);
            $this->uploadFiles($config, $filesToUpload, $tmpDir);
        } else {
            foreach ($config['storage']['input']['tables'] as $table) {
                $tableId = $table['source'];
                $tableDef = Model::enhanceDefinition(
                    $tableId,
                    $config['parameters']['tables'][$tableId],
                    $projectDefinition
                );

                $filesToUpload = [];
                $tmpDir = $this->temp->getTmpFolder() . '/' . $tableId;
                mkdir($tmpDir);

                $filesToUpload[] = $this->createManifest($tableDef, $tmpDir);
                $filesToUpload[] = $this->createCsv("$inputPath/{$table['destination']}", $tableDef, $tmpDir);

                $this->uploadFiles($config, $filesToUpload, $tmpDir, $tableId);
            }
        }
    }

    public function checkConfig($config)
    {
        if (!isset($config['parameters']['user']['login'])) {
            throw new UserException('User login is missing from configuration');
        }
        if (!isset($config['parameters']['user']['#password'])) {
            throw new UserException('User password is missing from configuration');
        }
        if (!isset($config['parameters']['project']['pid'])) {
            throw new UserException('Project pid is missing from configuration');
        }
        if (!isset($config['storage']['input']['tables']) || !count($config['storage']['input']['tables'])) {
            throw new UserException('There are no tables on input');
        }
        if (!isset($config['parameters']['tables']) || !count($config['parameters']['tables'])) {
            throw new UserException('There are no configured tables');
        }
        foreach ($config['storage']['input']['tables'] as $table) {
            if (!isset($table['source']) || !isset($table['destination'])) {
                throw new \Exception('Wrong storage configuration: '
                    . (new JsonEncode())->encode($config['storage'], JsonEncoder::FORMAT));
            }
            if (!isset($config['parameters']['tables'][$table['source']])) {
                throw new UserException("Table {$table['source']} is not configured");
            }
        }
    }

    public function createDateDimension($pid, $dimensionName, $def)
    {
        $identifier = !empty($def['identifier']) ? $def['identifier']
            : $this->gdClient->getDateDimensions()->getDefaultIdentifier($dimensionName);
        $template = !empty($def['template']) ? $def['template'] : null;
        if (!$this->gdClient->getDateDimensions()->exists($pid, $dimensionName, $template)) {
            $this->gdClient->getDateDimensions()->executeCreateMaql($pid, $dimensionName, $identifier, $template);
        }
        if (!empty($def['includeTime'])) {
            $tmpDir = $this->temp->getTmpFolder() . '/' . $identifier;
            mkdir($tmpDir);
            $td = new \Keboola\GoodData\TimeDimension($this->gdClient);
            if (!$td->exists($pid, $dimensionName, $identifier)) {
                $td->executeCreateMaql($pid, $dimensionName, $identifier);
                $td->loadData($pid, $dimensionName, $tmpDir);
            }
        }
    }

    public function createMultiLoadManifest($tableDefinitions, $tmpDir)
    {
        $manifest = ['dataSetSLIManifestList' => []];
        foreach ($tableDefinitions as $tableDefinition) {
            $manifest['dataSetSLIManifestList'][] = Datasets::getDataLoadManifest(
                $tableDefinition['identifier'],
                $tableDefinition['columns'],
                !empty($tableDefinition['incremental'])
            );
        }
        $manifestFile = "$tmpDir/upload_info.json";
        file_put_contents($manifestFile, json_encode($manifest));
        return $manifestFile;
    }

    public function createManifest($tableDefinition, $tmpDir)
    {
        $manifest = Datasets::getDataLoadManifest(
            $tableDefinition['identifier'],
            $tableDefinition['columns'],
            !empty($tableDefinition['incremental'])
        );
        $manifestFile = "$tmpDir/upload_info.json";
        file_put_contents($manifestFile, json_encode($manifest));
        return $manifestFile;
    }

    public function createCsv($inputFile, $tableDefinition, $tmpDir)
    {
        $csvHandler = new CsvHandler($this->logger);
        $csvHandler->convert(
            $inputFile,
            "$tmpDir/{$tableDefinition['identifier']}.csv",
            $tableDefinition['columns']
        );
        return "$tmpDir/{$tableDefinition['identifier']}.csv";
    }

    public function uploadFiles($config, $filesToUpload, $tmpDir, $tableId = null)
    {
        $folderName = 'kbc-' . date('Ymd-his');
        $webDav = new WebDav(
            $config['parameters']['user']['login'],
            $config['parameters']['user']['#password'],
            $this->gdClient->getUserUploadUrl()
        );
        $webDav->createFolder($folderName);

        // Retry three times if necessary (sometimes it fails on GD maintenance)
        $repeat = 1;
        $uploadUrl = $webDav->getUrl() . $folderName . '/upload.zip';
        do {
            $webDav->uploadZip($filesToUpload, $webDav->getUrl() . $folderName);
            $packageName = $tableId ? "table $tableId" : "multi-load";
            if ($webDav->fileExists($uploadUrl)) {
                $this->logger->debug("Upload package for $packageName transferred to GoodData");
                break;
            }
            if ($repeat >= 5) {
                throw new UserException("Transfer package for $packageName has not been uploaded to '$uploadUrl'");
            }
            $repeat++;
            $this->logger->warn("Transfer of package for $packageName to GoodData failed, running try #{$repeat}");
        } while ($repeat <= 5);

        try {
            $this->gdClient->getDatasets()->loadData($config['parameters']['project']['pid'], $folderName);
        } catch (Exception $e) {
            $debugFile = "$tmpDir/etl.log";
            $logSaved = $webDav->saveLogs($folderName, $debugFile);
            if ($logSaved) {
                $this->logger->err('Data load error: ' . file_get_contents($debugFile));
            }

            $data = $e->getData();
            if (!empty($data['error']['message'])) {
                $data['error']['message'] = str_replace(
                    'Adjust the manifest or create the object.',
                    'Have you updated dataset\'s model after changes?',
                    $data['error']['message']
                );
                $e = new Exception($data['error']['message'], $e->getCode(), $e);
            }

            throw $e;
        }
    }

    protected function initGoodDataClient($config)
    {
        $this->gdClient = new Client();
        $this->gdClient->setUserAgent('gooddata-writer-v3', getenv('KBC_RUNID'));
        if (isset($config['parameters']['project']['backendUrl'])) {
            $this->gdClient->setApiUrl($config['parameters']['project']['backendUrl']);
        }
        $this->gdClient->login($config['parameters']['user']['login'], $config['parameters']['user']['#password']);
        return $this->gdClient;
    }
}
