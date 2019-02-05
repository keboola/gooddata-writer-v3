<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\GoodData\Exception;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;

class App
{
    /** @var  LoggerInterface */
    protected $logger;
    /** @var  Temp */
    protected $temp;
    /** @var  Client */
    protected $gdClient;
    /** @var ProvisioningClient  */
    protected $gdProvisioning;

    public function __construct(
        LoggerInterface $logger,
        Temp $temp,
        Client $gdClient,
        ProvisioningClient $gdProvisioning
    ) {
        $this->logger = $logger;
        $this->temp = $temp;
        $this->gdClient = $gdClient;
        $this->gdProvisioning = $gdProvisioning;
    }

    public function checkProjectAccess(Config $config): bool
    {
        try {
            $this->gdClient->get("/gdc/projects/{$config->getProjectPid()}");
        } catch (Exception $e) {
            if ($e->getCode() !== 403) {
                throw $e;
            }

            if (!getenv('KBC_TOKEN')) {
                throw new \Exception('KBC Token is missing from the environment');
            }
            $this->gdProvisioning->addUserToProject($config->getUserLogin(), $config->getProjectPid());
            $this->logger->debug("Service account for data loads ({$config->getUserLogin()}) added to "
                . "the project using GoodData Provisioning");
        }
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
            }
        }

        // Update model
        $this->gdClient->getProjectModel()->updateProject($config->getProjectPid(), $projectModel);
    }

    protected function getFilenameForTable(array $table): string
    {
        $fileName = $table['source']; // aka $tableId
        if (isset($table['destination'])) {
            $fileName = $table['destination'];
        }
        return $fileName;
    }

    protected function loadMulti(Config $config, array $projectDefinition, string $inputPath): void
    {
        $tmpDir = $this->temp->getTmpFolder();
        $upload = new Upload($this->gdClient, $this->logger, $tmpDir);
        $definitions = [];
        foreach ($config->getInputTables() as $table) {
            $tableId = $table['source'];
            $tableDefinition = $config->getTables()[$tableId];
            if (!$this->isTableEnabled($tableDefinition)) {
                continue;
            }

            // Resort columns according to input mapping
            $tableDefinition['columns'] = $this->resortColumns($tableId, $table, $tableDefinition);

            $tableDef = Model::enhanceDefinition(
                $tableId,
                $tableDefinition,
                $projectDefinition
            );

            $definitions[] = $tableDef;
            $fileName = $this->getFilenameForTable($table);
            $upload->createCsv("$inputPath/{$fileName}", $tableDef);
        }
        $upload->createMultiLoadManifest($definitions);
        $upload->upload($config->getProjectPid());
    }

    protected function loadSingle(Config $config, array $projectDefinition, string $inputPath): void
    {
        foreach ($config->getInputTables() as $table) {
            $tableId = $table['source'];
            $tableDefinition = $config->getTables()[$tableId];
            if (!$this->isTableEnabled($tableDefinition)) {
                continue;
            }

            // Resort columns according to input mapping
            $tableDefinition['columns'] = $this->resortColumns($tableId, $table, $tableDefinition);

            $tableDef = Model::enhanceDefinition(
                $tableId,
                $tableDefinition,
                $projectDefinition
            );

            $tmpDir = $this->temp->getTmpFolder() . '/' . $tableId;
            mkdir($tmpDir);
            $upload = new Upload($this->gdClient, $this->logger, $tmpDir);

            $upload->createSingleLoadManifest($tableDef);
            $fileName = $fileName = $this->getFilenameForTable($table);
            $upload->createCsv("$inputPath/{$fileName}", $tableDef);
            $upload->upload($config->getProjectPid(), $tableId);
        }
    }

    protected function resortColumns(string $tableId, array $inputMapping, array $definition) : array
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

    protected function isTableEnabled(array $tableDefinition) : bool
    {
        return !isset($tableDefinition['disabled']) || !$tableDefinition['disabled'];
    }

    public function getEnabledTables(Config $config) : array
    {
        return array_filter($config->getTables(), function ($table) {
            return $this->isTableEnabled($table);
        });
    }

    public function run(Config $config, string $inputPath): void
    {
        $this->checkProjectAccess($config);
        $projectDefinition = [
            'dataSets' => $this->getEnabledTables($config),
            'dimensions' => $config->getDimensions(),
        ];

        if (!$config->getLoadOnly()) {
            $this->updateModel($config, $projectDefinition);
        }

        if ($config->getMultiLoad()) {
            $this->loadMulti($config, $projectDefinition, $inputPath);
            return;
        }

        $this->loadSingle($config, $projectDefinition, $inputPath);
    }
}
