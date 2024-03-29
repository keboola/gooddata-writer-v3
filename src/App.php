<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\UserException;
use Keboola\Csv\CsvFile;
use Keboola\GoodData\Client;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;
use stdClass;

class App
{
    /** @var  LoggerInterface */
    protected $logger;
    /** @var  Temp */
    protected $temp;
    /** @var  Client */
    protected $gdClient;

    public function __construct(
        LoggerInterface $logger,
        Temp $temp,
        Client $gdClient
    ) {
        $this->logger = $logger;
        $this->temp = $temp;
        $this->gdClient = $gdClient;
    }

    public function checkProjectAccess(Config $config): bool
    {
        $this->gdClient->get("/gdc/projects/{$config->getProjectPid()}");
        return true;
    }

    protected function updateModel(Config $config, array $projectDefinition): void
    {
        $projectModel = Model::getProjectLDM($projectDefinition);

        // Date dimensions
        if (count($config->getDimensions())) {
            foreach ($config->getDimensions() as $dimensionName => $dimension) {
                $this->gdClient->createDateDimension([
                    'pid' => $config->getProjectPid(),
                    'name' => $dimensionName,
                    'includeTime' => !empty($dimension['includeTime']),
                    'template' => !empty($dimension['template']) ? $dimension['template'] : null,
                    'identifier' => !empty($dimension['identifier']) ? $dimension['identifier'] : null,
                ]);
                $this->logger->info("Created dimension $dimensionName");
            }
        }

        // Update model
        $result = $this->gdClient->getProjectModel()->updateProject($config->getProjectPid(), $projectModel);
        $log = !empty($result['description']) ? (array) $result['description'] : [];
        $this->logger->info($log ? 'Model updated' : 'Model is up to date, nothing to update', $log);
    }

    protected function getFilenameForTable(array $table): string
    {
        $fileName = $table['source']; // aka $tableId
        if (isset($table['destination'])) {
            $fileName = $table['destination'];
        }
        return $fileName;
    }

    public function enhanceTableDefinitionFromMapping(string $tableId, array $mapping, array $def): array
    {
        $def['columns'] = $this->resortColumns($tableId, $mapping, $def);
        $def['incremental'] = !empty($mapping['changed_since']);
        return $def;
    }

    protected function loadMulti(Config $config, array $projectDefinition, string $inputPath): void
    {
        $tmpDir = $this->temp->getTmpFolder();
        $upload = new Upload($this->gdClient, $this->logger, $tmpDir);
        $definitions = [];
        foreach ($config->getInputTables() as $mapping) {
            $tableId = $mapping['source'];
            $tableDefinition = $config->getTables()[$tableId];
            if (!$this->isTableEnabled($tableDefinition)) {
                continue;
            }

            $tableDefinition = $this->enhanceTableDefinitionFromMapping($tableId, $mapping, $tableDefinition);

            $tableDef = Model::enhanceDefinition(
                $tableId,
                $tableDefinition,
                $projectDefinition
            );

            $definitions[] = $tableDef;
            $fileName = $this->getFilenameForTable($mapping);
            $upload->createCsv("$inputPath/{$fileName}", $tableDef);
            $this->logger->info("Csv for table $tableId created");
        }
        $upload->createMultiLoadManifest($definitions);
        $upload->upload($config->getProjectPid());
    }

    protected function loadSingle(Config $config, array $projectDefinition, string $inputPath): void
    {
        foreach ($config->getInputTables() as $mapping) {
            $tableId = $mapping['source'];
            $tableDefinition = $config->getTables()[$tableId];
            if (!$this->isTableEnabled($tableDefinition)) {
                continue;
            }

            $tableDefinition = $this->enhanceTableDefinitionFromMapping($tableId, $mapping, $tableDefinition);

            $tableDef = Model::enhanceDefinition(
                $tableId,
                $tableDefinition,
                $projectDefinition
            );

            $tmpDir = $this->temp->getTmpFolder() . '/' . $tableId;
            mkdir($tmpDir);
            $upload = new Upload($this->gdClient, $this->logger, $tmpDir);

            $upload->createSingleLoadManifest($tableDef);
            $fileName = $this->getFilenameForTable($mapping);
            $upload->createCsv("$inputPath/{$fileName}", $tableDef);
            $this->logger->info("Csv for table $tableId created");
            $upload->upload($config->getProjectPid(), $tableId);
        }
    }

    public function resortColumns(string $tableId, array $inputMapping, array $definition): array
    {
        if (!count($inputMapping['columns'])) {
            throw new UserException("Columns definition for input mapping table {$tableId} is missing.");
        }
        $resortedColumns = [];
        foreach ($inputMapping['columns'] as $c) {
            $resortedColumns[$c] = $definition['columns'][$c];
        }
        return $resortedColumns;
    }

    protected function isTableEnabled(array $tableDefinition): bool
    {
        return !isset($tableDefinition['disabled']) || !$tableDefinition['disabled'];
    }

    public function getEnabledTables(Config $config): array
    {
        return array_filter($config->getTables(), function ($table) {
            return $this->isTableEnabled($table);
        });
    }

    public function run(Config $config, string $inputPath): void
    {
        $this->checkProjectAccess($config);

        if (!$config->getLoadOnly()) {
            // Send only enabled tables to update model.
            $projectDefinition = [
                'dataSets' => $this->getEnabledTables($config),
                'dimensions' => $config->getDimensions(),
            ];
            $this->updateModel($config, $projectDefinition);
        }

        // Add all tables including the disabled to the definition.
        // Otherwise, creating of data load manifest would not
        // work for datasets referencing disabled tables.
        $projectDefinition = [
            'dataSets' => $config->getTables(),
            'dimensions' => $config->getDimensions(),
        ];

        if ($config->getMultiLoad()) {
            $this->loadMulti($config, $projectDefinition, $inputPath);
            return;
        }

        $this->loadSingle($config, $projectDefinition, $inputPath);
    }

    public function readModel(Config $config): array
    {
        $reader = new ModelReader($this->gdClient, $this->logger);
        $model = $reader->getDefinitionFromLDM($config->getBucket(), $config->getProjectPid());

        $configuration = ['dimensions' => $model['dateDimensions'], 'tables' => []];

        $storageClient = new \Keboola\StorageApi\Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);

        // Create data tables
        if (!$storageClient->bucketExists($config->getBucket())) {
            $bucketParts = explode('.', $config->getBucket());
            $storageClient->createBucket(substr($bucketParts[1], 2), $bucketParts[0]);
        }
        $mapping = [];
        foreach ($model['dataSets'] as $tableName => $d) {
            $dataFile = "{$this->temp->getTmpFolder()}/$tableName.csv";
            $columns = array_keys($d['columns']);
            $csv = new CsvFile($dataFile);
            $csv->writeRow($columns);
            $storageClient->createTable($config->getBucket(), $tableName, $csv);
            $configuration['tables']["{$config->getBucket()}.$tableName"] = $d;
            $mapping[] = ['columns' => $columns, 'source' => "{$config->getBucket()}.$tableName"];
            $this->logger->info("Table {$config->getBucket()}.$tableName created");
        }

        // Update configuration
        if (count($configuration['tables']) === 0) {
            $configuration['tables'] = new stdClass();
        }
        if (count($configuration['dimensions']) === 0) {
            $configuration['dimensions'] = new stdClass();
        }

        $branchId = null;
        if (getenv('KBC_BRANCHID')) {
            $branchId = (string) getenv('KBC_BRANCHID');
        }

        $storageApiClient = StorageApiClientFactory::getClient($config, $branchId);

        $storage = new ConfigurationStorage($storageApiClient);
        $storage->updateConfiguration($config->getConfigurationId(), [
            'parameters' => $configuration,
            'storage' => ['input' => ['tables' => $mapping]],
        ], 'Configuration read from GoodData model');
        return $configuration;
    }
}
