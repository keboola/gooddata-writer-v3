<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Symfony\Component\Console\Output\ConsoleOutput;

class App
{
    /** @var  ConsoleOutput */
    private $consoleOutput;

    public function __construct($consoleOutput)
    {
        $this->consoleOutput = $consoleOutput;
    }

    public function run($options, $inputPath)
    {
        print_r($options);
        $files = new CsvFiles($inputPath);
        print_r($files->getFiles());
    }
}
