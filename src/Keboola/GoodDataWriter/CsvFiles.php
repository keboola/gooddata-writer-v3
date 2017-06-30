<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Keboola\Csv\CsvFile;

class CsvFiles
{
    private $inputPath;
    private $files = [];

    public function __construct(string $inputPath)
    {
        $this->inputPath = $inputPath;

        foreach (new \DirectoryIterator($inputPath) as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $this->files[$fileinfo->getFilename()] = new CsvFile($fileinfo->getPathname());
            }
        }

        if (!count($this->files)) {
            throw new UserException('There is no table to write');
        }
    }

    public function getFiles(): array
    {
        return $this->files;
    }
}
