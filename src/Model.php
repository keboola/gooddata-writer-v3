<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\UserException;
use Keboola\GoodData\DateDimensions;
use Keboola\GoodData\Identifiers;
use Keboola\GoodData\TimeDimension;

class Model
{
    public static function getProjectLDM(array $def): array
    {
        $result = [
            'projectModel' => [
                'datasets' => [],
                'dateDimensions' => [],
            ],
        ];
        if (isset($def['dataSets'])) {
            $dataSets = [];
            foreach ($def['dataSets'] as $id => $dataSet) {
                $columns = [];
                foreach ($dataSet['columns'] as $columnName => $column) {
                    if ($column['type'] === 'DATE') {
                        $column = self::addDateDimensionDefinition($columnName, $column, $id, $def);
                    }
                    if ($column['type'] === 'REFERENCE') {
                        $column = self::addReferenceDefinition($columnName, $column, $id, $def);
                        if (isset($column['multivalue']) && $column['multivalue'] === true) {
                            $result['multivalueAttributes'][] = $column['schemaReferenceConnection'];
                        }
                    }
                    $columns[$columnName] = $column;
                }
                $dataSet['columns'] = $columns;
                $dataSets[$id] = $dataSet;
            }
            foreach ($dataSets as $id => $dataSet) {
                $result['projectModel']['datasets'][] = self::getDataSetLDM((string) $id, $dataSet);
            }
        }
        if (isset($def['dimensions'])) {
            foreach ($def['dimensions'] as $name => $dim) {
                $result['projectModel']['dateDimensions'][] = self::getDateDimensionLDM($name, $dim);
                if (!empty($dim['includeTime'])) {
                    $result['projectModel']['datasets'][] = self::getTimeDimensionLDM($name, $dim);
                }
            }
        }
        return $result;
    }

    public static function getDataSetLDM(string $id, array $def): array
    {
        // add default connection point
        $dataSet = [
            'identifier' => self::getDatasetIdFromDefinition($id, $def),
            'title' => self::getTitleFromDefinition($id, $def),
            'anchor' => [
                'attribute' => [
                    'identifier' => !empty($def['anchorIdentifier']) ? $def['anchorIdentifier']
                        : Identifiers::getImplicitConnectionPointId($id),
                    'title' => sprintf('Records of %s', self::getTitleFromDefinition($id, $def)),
                ],
            ],
        ];

        if (!empty($def['grain'])) {
            $dataSet['anchor']['attribute']['grain'] = self::getGrain($id, $def);
        }

        $facts = [];
        $attributes = [];
        $references = [];
        $labels = [];
        $connectionPoint = null;
        foreach ($def['columns'] as $columnName => $column) {
            $columnTitle = !empty($column['title']) ? $column['title'] : $columnName;
            if (!isset($column['type'])) {
                continue;
            }
            switch ($column['type']) {
                case 'CONNECTION_POINT':
                    $connectionPoint = $columnName;
                    $dataSet['anchor'] = self::getAttributeLDM($id, $def, $columnName, $column);

                    $label = self::getLabelLDM($id, $columnName, $column);
                    $dataSet['anchor']['attribute']['defaultLabel'] = $label['label']['identifier'];
                    $labels[$columnName][] = $label;
                    break;
                case 'FACT':
                    $facts[] = self::getFactLDM($id, $columnName, $column);
                    break;
                case 'ATTRIBUTE':
                    $attributes[$columnName] = self::getAttributeLDM($id, $def, $columnName, $column);
                    $labels[$columnName][] = self::getLabelLDM($id, $columnName, $column);
                    break;
                case 'HYPERLINK':
                case 'LABEL':
                    if (!isset($labels[$column['reference']])) {
                        $labels[$column['reference']] = [];
                    }
                    $column['identifierLabel'] = !empty($column['identifier']) ? $column['identifier']
                        : Identifiers::getRefLabelId($id, $column['reference'], $columnName);
                    $labels[$column['reference']][] = self::getLabelLDM($id, $columnName, $column);
                    break;
                case 'REFERENCE':
                    $references[] = isset($column['schemaReferenceIdentifier']) ? $column['schemaReferenceIdentifier']
                        : Identifiers::getDatasetId($column['schemaReference']);
                    break;
                case 'DATE':
                    $template = !empty($column['template']) ? $column['template'] : null;
                    $references[] = !empty($column['identifier'])
                        ? $column['identifier']
                        : DateDimensions::getDefaultIdentifierForReference($column['dateDimension'], $template);
                    if (!empty($column['includeTime'])) {
                        $references[] = 'dataset.time.' . Identifiers::getIdentifier($column['dateDimension']);
                        $facts[] = self::getFactLDM($id, $columnName, [
                            'identifier' => !empty($column['identifierTimeFact']) ? $column['identifierTimeFact']
                                : TimeDimension::getTimeFactIdentifier($id, $columnName),
                            'title' => "$columnTitle Time",
                            'dataType' => 'INT',
                        ]);
                    }
                    break;
            }
        }

        foreach ($labels as $attributeId => $labelArray) {
            if (isset($attributes[$attributeId])) {
                $attributes[$attributeId]['attribute']['labels'] = $labelArray;
            } elseif ($attributeId === $connectionPoint) {
                $dataSet['anchor']['attribute']['labels'] = $labelArray;
            }
        }

        if (count($facts)) {
            $dataSet['facts'] = $facts;
        }
        if (count($attributes)) {
            $dataSet['attributes'] = \array_values($attributes);
        }
        if (count($references)) {
            $dataSet['references'] = $references;
        }
        return ['dataset' => $dataSet];
    }

    public static function getDatasetIdFromDefinition(string $id, array $def): string
    {
        return !empty($def['identifier']) ? $def['identifier'] : Identifiers::getDatasetId($id);
    }

    public static function getTitleFromDefinition(string $id, array $def): string
    {
        return !empty($def['title']) ? $def['title'] : $id;
    }

    public static function getDefaultLabelId(string $datasetId, string $name, array $column): string
    {
        return !empty($column['identifierLabel'])
            ? $column['identifierLabel'] : Identifiers::getLabelId($datasetId, $name);
    }

    public static function getColumnDataType(array $column): string
    {
        if (!empty($column['dataType'])) {
            $res = $column['dataType'];
            if (!empty($column['dataTypeSize'])) {
                $res .= "({$column['dataTypeSize']})";
            }
            return $res;
        }
        return '';
    }

    public static function getDateDimensionLDM(string $name, array $def): array
    {
        return [
            'dateDimension' => [
                'name' => !empty($def['identifier']) ? $def['identifier'] : Identifiers::getIdentifier($name),
                'title' => $name,
            ],
        ];
    }

    public static function getTimeDimensionLDM(string $name, array $def): array
    {
        return ['dataset' => TimeDimension::getLDM(
            !empty($def['identifier']) ? $def['identifier'] : Identifiers::getIdentifier($name),
            $name
        )];
    }

    public static function getFactLDM(string $datasetId, string $name, array $column): array
    {
        $fact = [
            'identifier' => !empty($column['identifier']) ? $column['identifier']
                : Identifiers::getFactId($datasetId, $name),
            'title' => self::getTitleFromDefinition($name, $column),
            'deprecated' => false,
        ];
        $dataType = self::getColumnDataType($column);
        if ($dataType) {
            $fact['dataType'] = $dataType;
        }
        return ['fact' => $fact];
    }

    public static function getAttributeLDM(string $datasetId, array $datasetDef, string $name, array $column): array
    {
        $attribute = [
            'identifier' => !empty($column['identifier'])
                ? $column['identifier'] : Identifiers::getAttributeId($datasetId, $name),
            'title' => self::getTitleFromDefinition($name, $column),
            'defaultLabel' => self::getDefaultLabelId($datasetId, $name, $column),
            'folder' => self::getTitleFromDefinition($datasetId, $datasetDef),
            'deprecated' => false,
        ];

        if (!empty($column['sortLabel'])) {
            if (empty($column['identifierSortLabel'])) {
                if (!isset($datasetDef['columns'][$column['sortLabel']])) {
                    throw new UserException("Sort label for column $name on dataset $datasetId is invalid");
                }
                $sortLabelCol = $datasetDef['columns'][$column['sortLabel']];
                $column['identifierSortLabel'] = isset($sortLabelCol['identifier']) ? $sortLabelCol['identifier']
                    : Identifiers::getRefLabelId($datasetId, $name, $column['sortLabel']);
            }

            $attribute['sortOrder'] = [
                'attributeSortOrder' => [
                    'label' => $column['identifierSortLabel'],
                    'direction' => (!empty($column['sortOrder']) && $column['sortOrder'] === 'DESC') ? 'DESC' : 'ASC',
                ],
            ];
        }
        return ['attribute' => $attribute];
    }

    public static function getLabelLDM(string $datasetId, string $name, array $column): array
    {
        $label = [
            'identifier' => self::getDefaultLabelId($datasetId, $name, $column),
            'title' => self::getTitleFromDefinition($name, $column),
            'type' => 'GDC.' . ($column['type'] === 'HYPERLINK' ? 'link' : 'text'),
        ];
        $dataType = self::getColumnDataType($column);
        if ($dataType) {
            $label['dataType'] = $dataType;
        } elseif ($column['type'] === 'HYPERLINK') {
            $label['dataType'] = 'VARCHAR(255)';
        }
        return ['label' => $label];
    }

    public static function addDateDimensionDefinition(
        string $columnName,
        array $column,
        string $dataSetId,
        array $def
    ): array {
        if (empty($column['dateDimension']) || !isset($def['dimensions'][$column['dateDimension']])) {
            throw new UserException("Date column '{$columnName}' of dataset $dataSetId does not have "
                . 'a valid date dimension assigned');
        }
        $column['includeTime'] = $def['dimensions'][$column['dateDimension']]['includeTime'];
        $column['template'] = $def['dimensions'][$column['dateDimension']]['template'];
        if (!empty($def['dimensions'][$column['dateDimension']]['identifier'])) {
            $column['identifier'] = $def['dimensions'][$column['dateDimension']]['identifier'];
        }
        return $column;
    }

    public static function addReferenceDefinition(
        string $columnName,
        array $column,
        string $dataSetId,
        array $def
    ): array {
        if (empty($column['schemaReference']) || !isset($def['dataSets'][$column['schemaReference']])) {
            throw new UserException("Schema reference of column '{$columnName}' of dataset $dataSetId is invalid");
        }
        $refTableId = $column['schemaReference'];
        $refTableDefinition = $def['dataSets'][$column['schemaReference']];
        $column['schemaReference'] = empty($refTableDefinition['title'])
            ? $refTableId : $refTableDefinition['title'];
        $column['schemaReferenceIdentifier'] = self::getDatasetIdFromDefinition($refTableId, $refTableDefinition);
        foreach ($refTableDefinition['columns'] as $key => $c) {
            if (isset($c['type']) && $c['type'] === 'CONNECTION_POINT') {
                $column['reference'] = $key;
                if (!empty($c['identifierLabel'])) {
                    $column['referenceIdentifier'] = $c['identifierLabel'];
                } elseif (!empty($c['identifier'])) {
                    $column['referenceIdentifier'] = $c['identifier'];
                }
                $column['schemaReferenceConnection'] = !empty($c['identifier'])
                    ? $c['identifier'] : Identifiers::getAttributeId($refTableId, $key);
                $column['schemaReferenceConnectionLabel'] = !empty($c['identifierLabel'])
                    ? $c['identifierLabel'] : Identifiers::getLabelId($refTableId, $key);
                break;
            }
        }
        if (!isset($column['schemaReferenceConnection'])) {
            throw new UserException("Dataset '{$refTableId}' referenced from $dataSetId is missing a connection point");
        }
        return $column;
    }

    public static function removeIgnoredColumns(array $columns): array
    {
        $result = [];
        foreach ($columns as $columnName => $column) {
            if (isset($column['type']) && $column['type'] !== 'IGNORE') {
                $result[$columnName] = $column;
            }
        }
        return $result;
    }

    public static function addDefaultIdentifiers(string $tableId, array $def): array
    {
        if (empty($def['identifier'])) {
            $def['identifier'] = Identifiers::getDatasetId($tableId);
        }
        foreach ($def['columns'] as $columnName => &$column) {
            if (!isset($column['type'])) {
                continue;
            }
            switch ($column['type']) {
                case 'CONNECTION_POINT':
                case 'ATTRIBUTE':
                    if (empty($column['identifier'])) {
                        $column['identifier'] = Identifiers::getAttributeId($tableId, $columnName);
                    }
                    if (empty($column['identifierLabel'])) {
                        $column['identifierLabel'] = Identifiers::getLabelId($tableId, $columnName);
                    }
                    break;
                case 'FACT':
                    if (empty($column['identifier'])) {
                        $column['identifier'] = Identifiers::getFactId($tableId, $columnName);
                    }
                    break;
                case 'LABEL':
                case 'HYPERLINK':
                    if (empty($column['identifier'])) {
                        $column['identifier'] = Identifiers::getRefLabelId($tableId, $column['reference'], $columnName);
                    }
                    break;
                case 'REFERENCE':
                    if (empty($column['schemaReferenceConnectionLabel'])) {
                        $column['schemaReferenceConnectionLabel'] = !empty($column['identifier'])
                            ? $column['identifier'] : sprintf(
                                'label.%s.%s',
                                Identifiers::getIdentifier($column['schemaReference']),
                                Identifiers::getIdentifier($column['reference'])
                            );
                    }
                    break;
                case 'DATE':
                    if (empty($column['identifier'])) {
                        $column['identifier'] = Identifiers::getIdentifier($column['dateDimension']);
                        if (!empty($column['template']) && strtolower($column['template']) !== 'gooddata') {
                            $column['identifier'] .= '.' . strtolower($column['template']);
                        }
                    }

                    if (!empty($column['includeTime']) && empty($column['identifierTimeFact'])) {
                        $column['identifierTimeFact'] = TimeDimension::getTimeFactIdentifier($tableId, $columnName);
                    }
                    break;
                case 'IGNORE':
                    continue 2;
            }
        }
        return $def;
    }

    public static function enhanceDefinition(string $tableId, array $def, array $projectDef): array
    {
        $result = $def;
        $result['columns'] = self::removeIgnoredColumns($def['columns']);
        foreach ($def['columns'] as $columnName => $column) {
            if ($column['type'] === 'DATE') {
                $result['columns'][$columnName]
                    = self::addDateDimensionDefinition($columnName, $column, $tableId, $projectDef);
            } elseif ($column['type'] === 'REFERENCE') {
                $result['columns'][$columnName]
                    = self::addReferenceDefinition($columnName, $column, $tableId, $projectDef);
            }
        }
        return self::addDefaultIdentifiers($tableId, $result);
    }


    private static function getGrain(string $id, array $def): array
    {
        $result = [];
        if (!empty($def['grain'])) {
            $factFound = false;
            foreach ($def['columns'] as $c) {
                if ($c['type'] === 'CONNECTION_POINT') {
                    throw new UserException('Grain cannot be created on data set with a connection point');
                }
                if ($c['type'] === 'FACT') {
                    $factFound = true;
                }
            }
            if (!$factFound) {
                throw new UserException('Grain cannot be created on data set without facts');
            }

            foreach ($def['grain'] as $g) {
                if (!isset($def['columns'][$g])) {
                    throw new UserException("Grain '{$g}' not found between dataset's columns");
                }
                $column = $def['columns'][$g];
                switch ($column['type']) {
                    case 'ATTRIBUTE':
                        $result[] = ['attribute' => !empty($column['identifier']) ? $column['identifier']
                            : Identifiers::getAttributeId($id, $g)];
                        break;
                    case 'REFERENCE':
                        $result[] = ['attribute' => $column['schemaReferenceConnection']];
                        break;
                    case 'DATE':
                        $result[] = ['dateDimension' => Identifiers::getDateDimensionGrainId(
                            $column['dateDimension'],
                            !empty($column['template']) ? $column['template'] : null
                        )];
                        break;
                    default:
                        throw new UserException("Grain '{$g}' is on unsupported column type");
                }
            }
        }
        return $result;
    }
}
