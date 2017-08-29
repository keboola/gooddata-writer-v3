<?php
declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodDataWriter\Model;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Keboola\GoodDataWriter\Model
 */
class EmailTest extends TestCase
{
    protected $id;
    protected $def;
    protected $factColDef;
    protected $attrColDef;
    protected $labelColDef;

    protected function setUp()
    {
        $this->id = 'id' . uniqid();
        $this->factColDef = [
            'identifier' => uniqid(),
            'title' => uniqid(),
            'dataType' => 'DECIMAL',
            'dataTypeSize' => '12,2',
            'type' => 'FACT'
        ];
        $this->attrColDef = [
            'identifier' => uniqid(),
            'identifierLabel' => uniqid(),
            'title' => uniqid(),
            'sortOrder' => 'DESC',
            'sortLabel' => 'label',
            'type' => 'ATTRIBUTE'
        ];
        $this->labelColDef = [
            'title' => "l" . uniqid(),
            'reference' => 'attr',
            'type' => 'HYPERLINK'
        ];
        $this->def = [
            'identifier' => uniqid(),
            'title' => uniqid(),
            'columns' => [
                'attr' => $this->attrColDef,
                'fact' => $this->factColDef,
                'label' => $this->labelColDef
            ]
        ];
    }

    public function testGetDatasetIdFromDefinition()
    {
        $this->assertEquals($this->def['identifier'], Model::getDatasetIdFromDefinition($this->id, $this->def));
        $this->assertEquals("dataset.{$this->id}", Model::getDatasetIdFromDefinition($this->id, []));
        $this->assertEquals("dataset.{$this->id}s", Model::getDatasetIdFromDefinition("$this->id.š", []));
    }

    public function testGetTitleFromDefinition()
    {
        $this->assertEquals($this->def['title'], Model::getTitleFromDefinition($this->id, $this->def));
        $this->assertEquals($this->id, Model::getTitleFromDefinition($this->id, []));
    }

    public function testGetDefaultLabelId()
    {
        $name = 't' . uniqid();
        $this->assertEquals($this->attrColDef['identifierLabel'], Model::getDefaultLabelId($this->id, $name, $this->attrColDef));
        $this->assertEquals("label.{$this->id}.{$name}", Model::getDefaultLabelId($this->id, $name, []));
    }

    public function testGetColumnDataType()
    {
        $this->assertEquals('DECIMAL(12,2)', Model::getColumnDataType($this->factColDef));
    }

    public function testGetDateDimensionLDM()
    {
        $name = uniqid();
        $this->assertEquals([
            'dateDimension' => [
                'name' => $this->def['identifier'],
                'title' => $name
            ]
        ], Model::getDateDimensionLDM($name, $this->def));
    }

    public function testGetTimeDimensionLDM()
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

    public function testGetFactLDM()
    {
        $this->assertEquals(['fact' => [
            'identifier' => $this->factColDef['identifier'],
            'title' => $this->factColDef['title'],
            'deprecated' => false,
            'dataType' => 'DECIMAL(12,2)'
        ]], Model::getFactLDM($this->id, uniqid(), $this->factColDef));
    }

    public function testGetAttributeLDM()
    {
        $model = Model::getAttributeLDM($this->id, $this->def, 'attr', $this->attrColDef);
        $this->assertArrayHasKey('attribute', $model);
        $this->assertArrayHasKey('identifier', $model['attribute']);
        $this->assertArrayHasKey('title', $model['attribute']);
        $this->assertArrayHasKey('defaultLabel', $model['attribute']);
        $this->assertArrayHasKey('sortOrder', $model['attribute']);
        $this->assertArrayHasKey('attributeSortOrder', $model['attribute']['sortOrder']);
        $this->assertArrayHasKey('label', $model['attribute']['sortOrder']['attributeSortOrder']);
        $this->assertArrayHasKey('direction', $model['attribute']['sortOrder']['attributeSortOrder']);
        $this->assertEquals("label.{$this->id}.attr.label", $model['attribute']['sortOrder']['attributeSortOrder']['label']);
        $this->assertEquals('DESC', $model['attribute']['sortOrder']['attributeSortOrder']['direction']);
    }

    public function testGetLabelLDM()
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
}
