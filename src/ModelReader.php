<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\UserException;
use Keboola\GoodData\Client;
use Keboola\GoodData\Identifiers;
use Psr\Log\LoggerInterface;

class ModelReader
{
    /** @var  Client */
    protected $gdClient;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(Client $gdClient, LoggerInterface $logger)
    {
        $this->gdClient = $gdClient;
        $this->logger = $logger;
    }

    public function getDefinitionFromLDM(string $bucket, string $pid): array
    {
        $ldm = $this->gdClient->getProjectModel()->view($pid);

        $timeDimensions = [];
        // Dictionary of connection points and their tableIds
        $cpIdsDictionary = [];
        $dataSetsWithConnectionPoints = [];
        $grainDictionary = [];

        // Build basic definition
        $result = ['dataSets' => [], 'dateDimensions' => []];
        if (isset($ldm['projectModel']['datasets'])) {
            foreach ($ldm['projectModel']['datasets'] as $dataSet) {
                if (substr($dataSet['dataset']['identifier'], 0, 13) === 'dataset.time.') {
                    $timeDimensions[] = substr($dataSet['dataset']['identifier'], 13);
                } else {
                    $resultDataset = [
                        'tableId' => $bucket . '.' . Identifiers::getIdentifier($dataSet['dataset']['title']),
                        'identifier' => $dataSet['dataset']['identifier'],
                        'title' => $dataSet['dataset']['title'],
                        'columns' => [],
                        'grain' => [],
                    ];

                    if (isset($dataSet['dataset']['anchor']['attribute']['grain'])) {
                        $resultDataset['grain'] = $dataSet['dataset']['anchor']['attribute']['grain'];
                    }

                    if (isset($dataSet['dataset']['anchor']['attribute'])) {
                        if (isset($dataSet['dataset']['anchor']['attribute']['labels'])) {
                            $column = self::cleanupColumnDefinitionFromLDM(
                                $dataSet['dataset']['anchor']['attribute'],
                                'CONNECTION_POINT'
                            );
                            $resultDataset['columns'] = array_merge($resultDataset['columns'], $column);
                            $grainDictionary[$dataSet['dataset']['anchor']['attribute']['identifier']]
                                = $column[0]['name'];
                            $cpIdsDictionary[$resultDataset['tableId']]
                                = $dataSet['dataset']['anchor']['attribute']['identifier'];
                            $dataSetsWithConnectionPoints[$dataSet['dataset']['anchor']['attribute']['identifier']]
                                = $dataSet['dataset']['identifier'];
                        }
                    }

                    if (isset($dataSet['dataset']['attributes'])) {
                        foreach ($dataSet['dataset']['attributes'] as $attr) {
                            $column = self::cleanupColumnDefinitionFromLDM($attr['attribute'], 'ATTRIBUTE');
                            $resultDataset['columns'] = array_merge($resultDataset['columns'], $column);
                            $grainDictionary[$attr['attribute']['identifier']] = $column[0]['name'];
                        }
                    }
                    if (isset($dataSet['dataset']['facts'])) {
                        foreach ($dataSet['dataset']['facts'] as $fact) {
                            if (substr($fact['fact']['identifier'], 0, 6) !== 'tm.dt.') {
                                $resultDataset['columns'] = array_merge(
                                    $resultDataset['columns'],
                                    self::cleanupColumnDefinitionFromLDM(
                                        $fact['fact'],
                                        'FACT'
                                    )
                                );
                            }
                        }
                    }
                    if (isset($dataSet['dataset']['references'])) {
                        foreach ($dataSet['dataset']['references'] as $reference) {
                            $resultDataset['columns'][] = [
                                'name' => Identifiers::getIdentifier($reference),
                                'type' => 'REFERENCE',
                                'schemaReference' => $reference,
                            ];
                        }
                    }

                    $connectionPointFound = false;
                    foreach ($resultDataset['columns'] as $column) {
                        if ($column['type'] === 'CONNECTION_POINT') {
                            $connectionPointFound = true;
                            break;
                        }
                    }
                    if (!$connectionPointFound) {
                        $resultDataset['anchorIdentifier'] = $dataSet['dataset']['anchor']['attribute']['identifier'];
                    }

                    $tableId = Identifiers::getIdentifier($dataSet['dataset']['title']);
                    $result['dataSets'][$tableId] = $resultDataset;
                }
            }
        }
        if (isset($ldm['projectModel']['dateDimensions'])) {
            foreach ($ldm['projectModel']['dateDimensions'] as $dimension) {
                try {
                    $template = $this->getDateDimensionTemplate($pid, $dimension['dateDimension']['title']);
                } catch (\Throwable $e) {
                    $template = false;
                }
                $result['dateDimensions'][] = [
                    'name' => $dimension['dateDimension']['title'],
                    'identifier' => $dimension['dateDimension']['name'],
                    'includeTime' => null,
                    'template' => $template,
                ];
            }
        }

        // Identify time dimensions
        $dateDimensions = [];
        foreach ($result['dateDimensions'] as &$d) {
            $dateDimensions[$d['identifier']] = $d['name'];
            $key = array_search(Identifiers::getIdentifier($d['name']), $timeDimensions);
            if ($key !== false) {
                $d['includeTime'] = 1;
                unset($timeDimensions[$key]);
            }
        }
        unset($d);

        if (count($timeDimensions)) {
            foreach ($timeDimensions as $td) {
                $this->logger->warning("Dataset with identifier 'dataset.time.$td' has been identified"
                    . 'as a time dimension but no corresponding date dimension found. Dataset has been skipped.');
            }
        }

        // Build dictionary for translating identifiers to column names
        $columnIdsDictionary = [];
        $dataSetIdsDictionary = [];
        foreach ($result['dataSets'] as $d) {
            $columnIdsDictionary[$d['identifier']] = [];
            foreach ($d['columns'] as $i => $c) {
                if (isset($c['identifier'])) {
                    $columnIdsDictionary[$d['identifier']][$c['identifier']] = $c['name'];
                    $dataSetIdsDictionary[$d['identifier']] = $d['tableId'];
                }
                if (isset($c['identifierLabel'])) {
                    $columnIdsDictionary[$d['identifier']][$c['identifierLabel']] = $c['name'];
                }
            }
        }

        // Identify date dimensions and translate reference identifiers to names
        foreach ($result['dataSets'] as &$d) {
            foreach ($d['columns'] as $i => &$c) {
                if ($c['type'] === 'LABEL' || $c['type'] === 'HYPERLINK') {
                    $c['reference'] = $columnIdsDictionary[$d['identifier']][$c['reference']];
                }
                if (isset($c['sortLabel'])) {
                    if (isset($columnIdsDictionary[$d['identifier']][$c['sortLabel']])) {
                        $c['sortLabel'] = $columnIdsDictionary[$d['identifier']][$c['sortLabel']];
                    } else {
                        $this->logger->warning("Sort label '{$c['sortLabel']}' could not be set,"
                            . ' its label was not found.');
                        $c['sortLabel'] = null;
                    }
                }
                if ($c['type'] === 'REFERENCE') {
                    if (in_array($c['schemaReference'], array_keys($dateDimensions))) {
                        $c['type'] = 'DATE';
                        $c['dateDimension'] = $dateDimensions[$c['schemaReference']];
                        $c['format'] = 'yyyy-MM-dd';
                        unset($c['schemaReference']);
                        $grainDictionary[$c['dateDimension']][$d['tableId']] = $c['name'];
                    } elseif (in_array($c['schemaReference'], $dataSetsWithConnectionPoints)
                        && array_key_exists($c['schemaReference'], $dataSetIdsDictionary)) {
                        $c['schemaReference'] = $dataSetIdsDictionary[$c['schemaReference']];
                    } elseif (substr($c['schemaReference'], 0, 13) === 'dataset.time.') {
                        unset($d['columns'][$i]);
                    } else {
                        throw new UserException("Reference '{$c['schemaReference']}' from data set "
                            . "'{$d['identifier']}' not found in the model");
                    }
                }
            }
            unset($c);
        }
        unset($d);

        // Translate grain
        foreach ($result['dataSets'] as &$d) {
            if ($d['grain']) {
                $attributes = [];
                $references = [];
                $dimensions = [];
                foreach ($d['columns'] as $c) {
                    if ($c['type'] === 'ATTRIBUTE') {
                        $attributes[$c['identifier']] = $c['name'];
                    } else if ($c['type'] === 'REFERENCE') {
                        $references[$c['schemaReference']] = $c['name'];
                    } else if ($c['type'] === 'DATE') {
                        $dimensions[$c['dateDimension']] = $c['name'];
                    }
                }
                foreach ($d['grain'] as &$g) {
                    if (isset($g['attribute'])) {
                        if (isset($attributes[$g['attribute']])) {
                            $g = $attributes[$g['attribute']];
                        } elseif (in_array($g['attribute'], $cpIdsDictionary)) {
                            $tableId = array_search($g['attribute'], $cpIdsDictionary);
                            if (isset($references[$tableId])) {
                                $g = $references[$tableId];
                            } else {
                                $this->logger->warning("Grain {$g['attribute']} of dataset {$d['identifier']}"
                                    . ' could not be translated');
                            }
                        } else {
                            $this->logger->warning("Grain {$g['attribute']} of dataset {$d['identifier']}"
                                . ' could not be translated');
                        }
                    } else if (isset($g['dateDimension'])) {
                        if (isset($dimensions[$dateDimensions[$g['dateDimension']]])) {
                            $g = $dimensions[$dateDimensions[$g['dateDimension']]];
                        } else {
                            $this->logger->warning("Grain {$g['dateDimension']} of dataset {$d['identifier']}"
                                . ' could not be translated');
                        }
                    } else {
                        $this->logger->warning('Grain '.json_encode($g)." of dataset {$d['identifier']}"
                            . ' could not be translated');
                    }
                }
            }
        }

        // Transform columns
        foreach ($result['dataSets'] as &$d) {
            $columns = [];
            foreach ($d['columns'] as $c) {
                $columnName = $c['name'];
                unset($c['name']);
                $columns[$columnName] = $c;
            }
            $d['columns'] = $columns;
        }

        // Put names of dimensions to keys
        $dimensions = [];
        foreach ($result['dateDimensions'] as $dimension) {
            $name = $dimension['name'];
            unset($dimension['name']);
            $dimensions[$name] = $dimension;
        }
        $result['dateDimensions'] = $dimensions;

        // Remove tableId from datasets
        foreach ($result['dataSets'] as &$dataSet) {
            unset($dataSet['tableId']);
        }

        return $result;
    }


    public static function getDateDimensionTemplateFromUrn(string $urn): string
    {
        $urn = explode(':', $urn);
        if (count($urn) === 3) {
            return $urn[1];
        }
        throw new \Exception('Template not found');
    }

    public function getGrain(string $pid, string $cpIdentifier): array
    {
        $existingGrain = [];
        $uri = $this->gdClient->getDatasets()->getUriForIdentifier($pid, $cpIdentifier);
        if ($uri) {
            $attrMetaData = $this->gdClient->get($uri.'?grain=1');
            if (isset($attrMetaData['attribute']['content']['grain'])) {
                foreach ($attrMetaData['attribute']['content']['grain'] as $g) {
                    $grainMetaData = $this->gdClient->get($g);
                    $existingGrain[] = $grainMetaData['attribute']['meta']['identifier'];
                }
            }
        }
        return $existingGrain;
    }

    public function getDateDimensionTemplate(string $pid, string $name): string
    {
        $result = $this->gdClient->get("/gdc/md/{$pid}/data/sets");
        foreach ($result['dataSetsInfo']['sets'] as $d) {
            if ($d['meta']['title'] === "Date ({$name})" && !empty($d['dataUploads'])) {
                $result = $this->gdClient->get($d['dataUploads']);
                return self::getDateDimensionTemplateFromUrn(
                    $result['dataUploads']['uploads'][0]['dataUpload']['file']
                );
            }
        }
        throw new \Exception('Template not found');
    }

    private static function cleanupColumnDefinitionFromLDM(array $column, string $type): array
    {
        switch ($type) {
            case 'CONNECTION_POINT':
                $namePrefix = 'cp_';
                break;
            case 'ATTRIBUTE':
                $namePrefix = 'a_';
                break;
            case 'FACT':
                $namePrefix = 'f_';
                break;
            default:
                $namePrefix = '';
        }

        $result = [[
            'name' => $namePrefix . Identifiers::getIdentifier($column['title']),
            'identifier' => $column['identifier'],
            'title' => $column['title'],
            'type' => $type,
        ]];

        if (in_array($type, ['CONNECTION_POINT', 'ATTRIBUTE'])) {
            if (!isset($column['defaultLabel'])) {
                // if there is no default label set, find the first label which is not hyperlink
                foreach ($column['labels'] as $label) {
                    if ($label['label']['type'] === 'GDC.text') {
                        $column['defaultLabel'] = $label['label']['identifier'];
                    }
                }
                if (!isset($column['defaultLabel'])) {
                    $column['defaultLabel'] = $column['labels'][0]['label']['identifier'];
                }
            }
            foreach ($column['labels'] as $label) {
                if ($column['defaultLabel'] === $label['label']['identifier']) {
                    $result[0]['identifierLabel'] = $column['defaultLabel'];
                    if (isset($column['labels'][0]['label']['dataType'])) {
                        $result[0]
                            = self::cleanupDataTypeDefinition($result[0], $column['labels'][0]['label']['dataType']);
                    }
                } else {
                    $result[] = [
                        'name' => Identifiers::getIdentifier($label['label']['title']),
                        'identifier' => $label['label']['identifier'],
                        'title' => $label['label']['title'],
                        'type' => $label['label']['type'] === 'GDC.link' ? 'HYPERLINK' : 'LABEL',
                        'reference' => $result[0]['identifier'],
                    ];
                }
            }
            if (isset($column['sortOrder']['attributeSortOrder'])) {
                $result[0]['sortLabel'] = $column['sortOrder']['attributeSortOrder']['label'];
                $result[0]['sortOrder'] = $column['sortOrder']['attributeSortOrder']['direction'];
            }
        }

        if ($type === 'FACT' && isset($column['dataType'])) {
            $result[0] = self::cleanupDataTypeDefinition($result[0], $column['dataType']);
        }

        return $result;
    }

    private static function cleanupDataTypeDefinition(array $column, string $dataType): array
    {
        $column['dataType'] = strtoupper($dataType);
        if (in_array(substr($column['dataType'], 0, 7), ['VARCHAR', 'DECIMAL'])) {
            $column['dataTypeSize'] = rtrim(substr($column['dataType'], 8), ')');
            $column['dataType'] = substr($column['dataType'], 0, 7);
        }
        return $column;
    }
}
