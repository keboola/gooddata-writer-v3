<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\Component\UserException;
use Keboola\GoodDataWriter\Model;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Keboola\GoodDataWriter\Model
 */
class ModelTest extends TestCase
{
    /** @var array */
    protected $def;
    /** @var string */
    protected $id;
    /** @var array */
    protected $tableDef;
    /** @var array */
    protected $factColDef;
    /** @var array */
    protected $attrColDef;
    /** @var array */
    protected $labelColDef;
    /** @var array */
    protected $dateColDef;
    /** @var array */
    protected $refColDef;

    protected function setUp(): void
    {
        $this->id = 'id' . uniqid();
        $this->factColDef = [
            'identifier' => uniqid(),
            'title' => uniqid(),
            'dataType' => 'DECIMAL',
            'dataTypeSize' => '12,2',
            'type' => 'FACT',
        ];
        $this->attrColDef = [
            'identifier' => uniqid(),
            'identifierLabel' => uniqid(),
            'title' => uniqid(),
            'sortOrder' => 'DESC',
            'sortLabel' => 'label',
            'type' => 'ATTRIBUTE',
        ];
        $this->labelColDef = [
            'title' => "l" . uniqid(),
            'reference' => 'attr',
            'type' => 'HYPERLINK',
        ];
        $this->dateColDef = [
            'dateDimension' => 'Date 1',
            'type' => 'DATE',
        ];
        $this->refColDef = [
            'schemaReference' => 'refTable',
            'reference' => 'id',
            'type' => 'REFERENCE',
        ];
        $this->tableDef = [
            'identifier' => uniqid(),
            'title' => uniqid(),
            'columns' => [
                'attr' => $this->attrColDef,
                'fact' => $this->factColDef,
                'label' => $this->labelColDef,
                'date' => $this->dateColDef,
                'ref' => $this->refColDef,
                'ignore' => [
                    'type' => 'IGNORE',
                ],
            ],
        ];
        $this->def = [
            'dataSets' => [
                $this->id => $this->tableDef,
            ],
            'dimensions' => [
                'Date 1' => [
                    'template' => 'keboola',
                    'includeTime' => true,
                ],
            ],
        ];
    }

    public function testGetDatasetIdFromDefinition(): void
    {
        $this->assertEquals(
            $this->tableDef['identifier'],
            Model::getDatasetIdFromDefinition($this->id, $this->tableDef)
        );
        $this->assertEquals("dataset.{$this->id}", Model::getDatasetIdFromDefinition($this->id, []));
        $this->assertEquals("dataset.{$this->id}s", Model::getDatasetIdFromDefinition("$this->id.š", []));
    }

    public function testGetTitleFromDefinition(): void
    {
        $this->assertEquals($this->tableDef['title'], Model::getTitleFromDefinition($this->id, $this->tableDef));
        $this->assertEquals($this->id, Model::getTitleFromDefinition($this->id, []));
    }

    public function testGetDefaultLabelId(): void
    {
        $name = 't' . uniqid();
        $this->assertEquals(
            $this->attrColDef['identifierLabel'],
            Model::getDefaultLabelId($this->id, $name, $this->attrColDef)
        );
        $this->assertEquals("label.{$this->id}.{$name}", Model::getDefaultLabelId($this->id, $name, []));
    }

    public function testGetColumnDataType(): void
    {
        $this->assertEquals('DECIMAL(12,2)', Model::getColumnDataType($this->factColDef));
    }

    public function testGetDateDimensionLDM(): void
    {
        $name = uniqid();
        $this->assertEquals([
            'dateDimension' => [
                'name' => $this->tableDef['identifier'],
                'title' => $name,
            ],
        ], Model::getDateDimensionLDM($name, $this->tableDef));
    }

    public function testGetTimeDimensionLDM(): void
    {
        $name = 't' . uniqid();
        $model = Model::getTimeDimensionLDM($name, []);
        $this->assertArrayHasKey('dataset', $model);
        $this->assertArrayHasKey('identifier', $model['dataset']);
        $this->assertEquals("dataset.time.$name", $model['dataset']['identifier']);
        $this->assertArrayHasKey('title', $model['dataset']);
        $this->assertArrayHasKey('anchor', $model['dataset']);
        $this->assertArrayHasKey('attribute', $model['dataset']['anchor']);
        $this->assertArrayHasKey('identifier', $model['dataset']['anchor']['attribute']);
        $this->assertEquals("attr.time.second.of.day.$name", $model['dataset']['anchor']['attribute']['identifier']);
    }

    public function testGetFactLDM(): void
    {
        $this->assertEquals(['fact' => [
            'identifier' => $this->factColDef['identifier'],
            'title' => $this->factColDef['title'],
            'deprecated' => false,
            'dataType' => 'DECIMAL(12,2)',
        ]], Model::getFactLDM($this->id, uniqid(), $this->factColDef));
    }

    public function testGetAttributeLDM(): void
    {
        $model = Model::getAttributeLDM($this->id, $this->tableDef, 'attr', $this->attrColDef);
        $this->assertArrayHasKey('attribute', $model);
        $this->assertArrayHasKey('identifier', $model['attribute']);
        $this->assertArrayHasKey('title', $model['attribute']);
        $this->assertArrayHasKey('defaultLabel', $model['attribute']);
        $this->assertArrayHasKey('sortOrder', $model['attribute']);
        $this->assertArrayHasKey('attributeSortOrder', $model['attribute']['sortOrder']);
        $this->assertArrayHasKey('label', $model['attribute']['sortOrder']['attributeSortOrder']);
        $this->assertArrayHasKey('direction', $model['attribute']['sortOrder']['attributeSortOrder']);
        $this->assertEquals(
            "label.{$this->id}.attr.label",
            $model['attribute']['sortOrder']['attributeSortOrder']['label']
        );
        $this->assertEquals('DESC', $model['attribute']['sortOrder']['attributeSortOrder']['direction']);
    }

    public function testGetLabelLDM(): void
    {
        $model = Model::getLabelLDM($this->id, 'label', $this->labelColDef);
        $this->assertArrayHasKey('label', $model);
        $this->assertArrayHasKey('identifier', $model['label']);
        $this->assertArrayHasKey('title', $model['label']);
        $this->assertArrayHasKey('type', $model['label']);
        $this->assertArrayHasKey('dataType', $model['label']);
        $this->assertEquals('GDC.link', $model['label']['type']);
        $this->assertEquals('VARCHAR(255)', $model['label']['dataType']);
    }

    public function testAddDateDimensionDefinition(): void
    {
        $model = Model::addDateDimensionDefinition('date', $this->dateColDef, $this->id, $this->def);
        $this->assertArrayHasKey('dateDimension', $model);
        $this->assertArrayHasKey('type', $model);
        $this->assertArrayHasKey('includeTime', $model);
        $this->assertArrayHasKey('template', $model);
        $this->assertEquals('Date 1', $model['dateDimension']);
        $this->assertEquals('DATE', $model['type']);
        $this->assertEquals(1, $model['includeTime']);
        $this->assertEquals('keboola', $model['template']);
    }

    public function testAddReferenceDefinitionMissingTable(): void
    {
        $this->expectException(UserException::class);
        Model::addReferenceDefinition('ref', $this->refColDef, $this->id, $this->tableDef);
    }

    public function testAddReferenceDefinitionMissingConnection(): void
    {
        $this->expectException(UserException::class);
        $tableDef = $this->tableDef;
        $tableDef['dataSets']['refTable'] = ['columns' => []];
        Model::addReferenceDefinition('ref', $this->refColDef, $this->id, $tableDef);
    }

    public function testAddReferenceDefinition(): void
    {
        $tableDef = $this->tableDef;
        $tableDef['dataSets']['refTable'] = ['columns' => ['id' => ['type' => 'CONNECTION_POINT']]];
        $model = Model::addReferenceDefinition('ref', $this->refColDef, $this->id, $tableDef);
        $this->assertArrayHasKey('schemaReference', $model);
        $this->assertArrayHasKey('type', $model);
        $this->assertArrayHasKey('reference', $model);
        $this->assertArrayHasKey('schemaReferenceIdentifier', $model);
        $this->assertArrayHasKey('schemaReferenceConnection', $model);
        $this->assertArrayHasKey('schemaReferenceConnectionLabel', $model);
        $this->assertEquals('refTable', $model['schemaReference']);
        $this->assertEquals('REFERENCE', $model['type']);
        $this->assertEquals('id', $model['reference']);
        $this->assertEquals('dataset.reftable', $model['schemaReferenceIdentifier']);
        $this->assertEquals('attr.reftable.id', $model['schemaReferenceConnection']);
        $this->assertEquals('label.reftable.id', $model['schemaReferenceConnectionLabel']);
    }

    public function testGetDatasetLDM(): void
    {
        $model = Model::getDataSetLDM($this->id, $this->tableDef);
        $this->assertArrayHasKey('dataset', $model);
        $this->assertArrayHasKey('identifier', $model['dataset']);
        $this->assertArrayHasKey('title', $model['dataset']);
        $this->assertArrayHasKey('anchor', $model['dataset']);
        $this->assertArrayHasKey('attribute', $model['dataset']['anchor']);
        $this->assertArrayHasKey('identifier', $model['dataset']['anchor']['attribute']);
        $this->assertArrayHasKey('facts', $model['dataset']);
        $this->assertCount(1, $model['dataset']['facts']);
        $this->assertArrayHasKey('fact', $model['dataset']['facts'][0]);
        $this->assertArrayHasKey('identifier', $model['dataset']['facts'][0]['fact']);
        $this->assertArrayHasKey('dataType', $model['dataset']['facts'][0]['fact']);
        $this->assertArrayHasKey('attributes', $model['dataset']);
        $this->assertCount(1, $model['dataset']['attributes']);
        $this->assertArrayHasKey('attribute', $model['dataset']['attributes'][0]);
        $this->assertArrayHasKey('identifier', $model['dataset']['attributes'][0]['attribute']);
        $this->assertArrayHasKey('sortOrder', $model['dataset']['attributes'][0]['attribute']);
        $this->assertArrayHasKey('labels', $model['dataset']['attributes'][0]['attribute']);
        $this->assertCount(2, $model['dataset']['attributes'][0]['attribute']['labels']);
        $this->assertArrayHasKey('references', $model['dataset']);
        $this->assertEquals(['date1', 'dataset.reftable'], $model['dataset']['references']);
    }

    public function testGetProjectLDM(): void
    {
        $def = $this->def;
        $def['dataSets']['refTable'] = ['columns' => ['id' => ['type' => 'CONNECTION_POINT']]];
        $model = Model::getProjectLDM($def);
        $this->assertArrayHasKey('projectModel', $model);
        $this->assertArrayHasKey('datasets', $model['projectModel']);
        $this->assertCount(3, $model['projectModel']['datasets']);
        $this->assertArrayHasKey('dataset', $model['projectModel']['datasets'][2]);
        $this->assertArrayHasKey('dateDimensions', $model['projectModel']);
        $this->assertCount(1, $model['projectModel']['dateDimensions']);
    }

    public function testRemoveIgnoredColumns(): void
    {
        $this->assertArrayHasKey('ignore', $this->tableDef['columns']);
        $model = Model::removeIgnoredColumns($this->tableDef['columns']);
        $this->assertArrayNotHasKey('ignore', $model);
    }

    public function testAddDefaultIdentifiers(): void
    {
        $model = Model::addDefaultIdentifiers($this->id, $this->tableDef);
        $this->assertArrayHasKey('columns', $model);
        $this->assertArrayHasKey('label', $model['columns']);
        $this->assertArrayHasKey('identifier', $model['columns']['label']);
        $this->assertArrayHasKey('date', $model['columns']);
        $this->assertArrayHasKey('identifier', $model['columns']['date']);
        $this->assertArrayHasKey('ref', $model['columns']);
        $this->assertArrayHasKey('schemaReferenceConnectionLabel', $model['columns']['ref']);
    }
}
