<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\GoodData\Datasets;
use Keboola\GoodData\Exception;
use Keboola\GoodData\WebDav;
use Psr\Log\LoggerInterface;

class Upload
{
    /** @var  Client */
    protected $gdClient;
    /** @var  LoggerInterface */
    protected $logger;
    /** @var string  */
    protected $tmpDir;
    /** @var array  */
    protected $files = [];

    public function __construct(Client $gdClient, LoggerInterface $logger, string $tmpDir)
    {
        $this->gdClient = $gdClient;
        $this->logger = $logger;
        $this->tmpDir = $tmpDir;
    }

    public function createCsv(string $inputFile, array $tableDefinition): void
    {
        $outputFile = "{$this->tmpDir}/{$tableDefinition['identifier']}.csv";
        $csvHandler = new CsvHandler($this->logger);
        $csvHandler->convert($inputFile, $outputFile, $tableDefinition['columns']);
        $this->files[] = $outputFile;
    }

    public function createSingleLoadManifest(array $tableDefinition): void
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

    public function createMultiLoadManifest(array $tableDefinitions): void
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

    public function upload(string $pid, ?string $tableId = null): void
    {
        $folderName = sprintf('kbc-%s-%s', date('Ymd-his'), uniqid());
        $webDav = new WebDav(
            $this->gdClient->getUsername(),
            $this->gdClient->getPassword(),
            $this->gdClient->getUserUploadUrl()
        );
        $webDav->createFolder($folderName);

        // Retry three times if necessary (sometimes it fails on GD maintenance)
        $repeat = 1;
        $uploadUrl = $webDav->getUrl() . $folderName . '/upload.zip';
        $packageName = $tableId ? "table $tableId" : 'multi-load';
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
            $this->logger->warning("Transfer of package for $packageName to GoodData failed, running try #{$repeat}");
        } while ($repeat <= 5);

        try {
            $this->gdClient->getDatasets()->loadData($pid, $folderName);
        } catch (Exception $e) {
            $debugFile = "{$this->tmpDir}/etl.log";
            $logSaved = $webDav->saveLogs($folderName, $debugFile);
            if ($logSaved) {
                $this->logger->error('Data load error: ' . file_get_contents($debugFile));
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
