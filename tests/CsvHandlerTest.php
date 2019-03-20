<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodData\Identifiers;
use Keboola\GoodDataWriter\CsvHandler;
use PHPUnit\Framework\TestCase;

class CsvHandlerTest extends TestCase
{
    public function testCsvConvert(): void
    {
        $tableId = 'out.c-main.data';
        $definition = $this->getDefinition($tableId);
        $inFile = $this->createCsv();
        $outFile = sys_get_temp_dir() . '/' . uniqid() . '.csv';
        $csvHandler = new CsvHandler();
        $csvHandler->convert($inFile, $outFile, $definition['dataSets'][0]['columns']);
        $this->assertTrue(file_exists($outFile));
        $this->compareFiles($inFile, $outFile);
    }

    protected function createCsv(): string
    {
        $csvFile = sys_get_temp_dir() . '/' . uniqid() . '.csv';
        $fp = fopen($csvFile, 'w');
        for ($i = 0; $i < 25000; $i++) {
            fputcsv($fp, [$i, md5(uniqid()), rand(1, 255), date('Y-m-d H:i:s', rand(1483228800, 1487116800))]);
        }
        fclose($fp);
        return $csvFile;
    }

    protected function compareFiles(string $csvIn, string $csvOut): void
    {
        $start = strtotime('1900-01-01 00:00:00');
        $fileIn = new \SplFileObject($csvIn);
        $fileOut = new \SplFileObject($csvOut);
        for ($i = 0; $i < 100; $i++) {
            $row = rand(1, 25000);
            $fileIn->seek($row);
            $fileOut->seek($row);
            $in = $fileIn->fgetcsv();
            $out = $fileOut->fgetcsv();
            $diff = strtotime($in[3]) - $start;
            $daysDiff = floor($diff / (60 * 60 * 24));
            $timeFact = $diff - ($daysDiff * 60 * 60 * 24);
            $in[4] = $timeFact;
            $in[5] = $timeFact;
            $this->assertEmpty(array_diff($out, $in), "Row $i differs. Input: "
                . print_r($in, true) . "\nOutput: " . print_r($out, true)
                . "\nFile tail" . shell_exec("tail -n 10 $csvOut"));
        }
    }

    protected function getDefinition(string $tableId): array
    {
        return ['dataSets' => [
            [
                'tableId' => $tableId,
                'identifier' => 'dataset.outcmaindata',
                'title' => 'Data',
                'columns' => [
                    'id' => [
                        'identifier' => "fact.".Identifiers::getIdentifier($tableId).".id",
                        'identifierLabel' => "label.".Identifiers::getIdentifier($tableId).".id",
                        'title' => 'Id',
                        'type' => 'CONNECTION_POINT',
                    ],
                    'attr' => [
                        'identifier' => "attr.".Identifiers::getIdentifier($tableId).".attr",
                        'identifierLabel' => "label.".Identifiers::getIdentifier($tableId).".col2",
                        'title' => 'Attr',
                        'type' => 'ATTRIBUTE',
                    ],
                    'fact' => [
                        'identifier' => "fact.".Identifiers::getIdentifier($tableId).".fact",
                        'title' => 'Fact',
                        'type' => 'FACT',
                    ],
                    'date' => [
                        'identifier' => 'test',
                        'identifierTimeFact' => 'tm.dt.outcmaindata.date',
                        'dateDimension' => 'test',
                        'format' => 'yyyy-MM-dd HH:mm:ss',
                        'includeTime' => true,
                        'title' => 'date',
                        'type' => 'DATE',
                    ],
                ],
            ],
        ], 'dimensions' => []];
    }
}
