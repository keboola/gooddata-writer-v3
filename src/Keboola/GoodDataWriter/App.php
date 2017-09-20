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
use Keboola\GoodData\Identifiers;
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

    public function __construct($consoleOutput)
    {
        $this->consoleOutput = $consoleOutput;
    }

    public function run($config, $inputPath)
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
        $pid = $config['parameters']['project']['pid'];

        if (!isset($config['storage']['input']['tables']) || !count($config['storage']['input']['tables'])) {
            throw new UserException('There are no tables on input');
        }
        if (!isset($config['parameters']['tables']) || !count($config['parameters']['tables'])) {
            throw new UserException('There are no configured tables');
        }

        $logger = new Logger('gooddata-writer', [new StreamHandler('php://stdout')]);
        $gdClient = $this->initGoodDataClient($config);
        $gdClient->setLogger($logger);
        $temp = new Temp();
        $temp->initRunFolder();


        // Date dimensions
        if (isset($config['parameters']['dimensions']) && count($config['parameters']['dimensions'])) {
            foreach ($config['parameters']['dimensions'] as $dimensionName => $dimension) {
                $identifier = !empty($dimension['identifier']) ? $dimension['identifier']
                    : $gdClient->getDateDimensions()->getDefaultIdentifier($dimensionName);
                $template = !empty($dimension['template']) ? $dimension['template'] : null;
                if (!$gdClient->getDateDimensions()->exists($pid, $dimensionName, $template)) {
                    $gdClient->getDateDimensions()->executeCreateMaql($pid, $dimensionName, $identifier, $template);
                }
                if (!empty($dimension['includeTime'])) {
                    $tmpDir = $temp->getTmpFolder() . '/' . $identifier;
                    mkdir($tmpDir);
                    $td = new \Keboola\GoodData\TimeDimension($gdClient);
                    if (!$td->exists($pid, $dimensionName, $identifier)) {
                        $td->executeCreateMaql($pid, $dimensionName, $identifier);
                        $td->loadData($pid, $dimensionName, $tmpDir);
                    }
                }
            }
        }

        $projectDefinition = [
            'dataSets' => $config['parameters']['tables'],
            'dimensions' => $config['parameters']['dimensions']
        ];
        $model = Model::getProjectLDM($projectDefinition);
        $gdClient->getProjectModel()->updateProject($pid, $model);

        $folderName = 'kbc-' . date('Ymd-his');
        $webDav = new WebDav(
            $config['parameters']['user']['login'],
            $config['parameters']['user']['#password'],
            $gdClient->getUserUploadUrl()
        );
        $webDav->createFolder($folderName);
        $csvHandler = new CsvHandler($logger);

        $tables = $config['parameters']['tables'];
        foreach ($config['storage']['input']['tables'] as $table) {
            if (!isset($table['source']) || !isset($table['destination'])) {
                throw new \Exception('Missing table source in storage: '
                    . (new JsonEncode())->encode($config['storage'], JsonEncoder::FORMAT));
            }
            $tableId = $table['source'];
            if (!isset($tables[$table['source']])) {
                throw new UserException("Table $tableId is not configured");
            }
            $tableDefinition = Model::enhanceDefinition($tableId, $tables[$tableId], $projectDefinition);

            $filesToUpload = [];
            $tmpDir = $temp->getTmpFolder() . '/' . $tableId;
            mkdir($tmpDir);

            // Manifest
            $manifest = Datasets::getDataLoadManifest(
                $tableDefinition['identifier'],
                $tableDefinition['columns'],
                !empty($tableDefinition['incremental'])
            );
            $manifestFile = "$tmpDir/upload_info.json";
            file_put_contents($manifestFile, json_encode($manifest));
            $filesToUpload[] = $manifestFile;

            // Prepare csv
            $csvHandler->convert(
                "$inputPath/{$table['destination']}",
                "$tmpDir/{$tableDefinition['identifier']}.csv",
                $tableDefinition['columns']
            );
            $filesToUpload[] = "$tmpDir/{$tableDefinition['identifier']}.csv";

            // Upload
            // Retry three times if necessary (sometimes it fails on GD maintenance)
            $repeat = 1;
            $uploadUrl = $webDav->getUrl() . $folderName . '/upload.zip';
            do {
                $webDav->uploadZip($filesToUpload, $webDav->getUrl() . $folderName);
                if ($webDav->fileExists($uploadUrl)) {
                    $logger->debug("Upload package for table $tableId transferred to GoodData");
                    break;
                }
                if ($repeat >= 5) {
                    throw new UserException("Transfer package for table $tableId has not been uploaded to '$uploadUrl'");
                }
                $repeat++;
                $logger->warn("Transfer of package for table $tableId to GoodData failed, running try #{$repeat}");
            } while ($repeat <= 5);

            // Run ETL task
            try {
                $gdClient->getDatasets()->loadData($pid, $folderName);
            } catch (Exception $e) {
                $debugFile = "$tmpDir/etl.log";
                $logSaved = $webDav->saveLogs($folderName, $debugFile);
                if ($logSaved) {
                    $logger->err('Data load error: ' . file_get_contents($debugFile));
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
    }

    protected function initGoodDataClient($config)
    {
        $gdClient = new Client();
        $gdClient->setUserAgent('gooddata-writer-v3', getenv('KBC_RUNID'));
        if (isset($config['parameters']['project']['backendUrl'])) {
            $gdClient->setApiUrl($config['parameters']['project']['backendUrl']);
        }
        $gdClient->login($config['parameters']['user']['login'], $config['parameters']['user']['#password']);
        return $gdClient;
    }
}
