<?php
/**
 * @package gooddata-writer-v3
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */

namespace Keboola\GoodDataWriter;

use Keboola\GoodData\Client;
use Keboola\GoodData\Datasets;
use Keboola\GoodData\Exception;
use Keboola\GoodData\WebDav;
use Monolog\Logger;

class Upload
{
    /** @var  Client */
    protected $gdClient;
    /** @var  Logger */
    protected $logger;
    protected $tmpDir;
    protected $files = [];

    public function __construct($gdClient, Logger $logger, $tmpDir)
    {
        $this->gdClient = $gdClient;
        $this->logger = $logger;
        $this->tmpDir = $tmpDir;
    }

    public function createCsv($inputFile, $tableDefinition)
    {
        $outputFile = "{$this->tmpDir}/{$tableDefinition['identifier']}.csv";
        $csvHandler = new CsvHandler($this->logger);
        $csvHandler->convert($inputFile, $outputFile, $tableDefinition['columns']);
        $this->files[] = $outputFile;
    }

    public function createSingleLoadManifest($tableDefinition)
    {
        $manifest = Datasets::getDataLoadManifest(
            $tableDefinition['identifier'],
            $tableDefinition['columns'],
            !empty($tableDefinition['incremental'])
        );
        $manifestFile = "{$this->tmpDir}/upload_info.json";
        file_put_contents($manifestFile, json_encode($manifest));
        $this->files[] = $manifestFile;
    }

    public function createMultiLoadManifest($tableDefinitions)
    {
        $manifest = ['dataSetSLIManifestList' => []];
        foreach ($tableDefinitions as $tableDefinition) {
            $manifest['dataSetSLIManifestList'][] = Datasets::getDataLoadManifest(
                $tableDefinition['identifier'],
                $tableDefinition['columns'],
                !empty($tableDefinition['incremental'])
            );
        }
        $manifestFile = "{$this->tmpDir}/upload_info.json";
        file_put_contents($manifestFile, json_encode($manifest));
        $this->files[] = $manifestFile;
    }

    public function upload($config, $tableId = null)
    {
        $folderName = sprintf('kbc-%s-%s', date('Ymd-his'), uniqid());
        $webDav = new WebDav(
            $config['parameters']['user']['login'],
            $config['parameters']['user']['#password'],
            $this->gdClient->getUserUploadUrl()
        );
        $webDav->createFolder($folderName);

        // Retry three times if necessary (sometimes it fails on GD maintenance)
        $repeat = 1;
        $uploadUrl = $webDav->getUrl() . $folderName . '/upload.zip';
        $packageName = $tableId ? "table $tableId" : "multi-load";
        do {
            $webDav->uploadZip($this->files, $webDav->getUrl() . $folderName);
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
            $debugFile = "{$this->tmpDir}/etl.log";
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
}
