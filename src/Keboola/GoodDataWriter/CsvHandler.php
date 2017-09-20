<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Symfony\Component\Process\Process;

class CsvHandler
{
    const CONVERT_SCRIPT_PATH = __DIR__ . '/convert_csv.php';
    /** @var \Monolog\Logger  */
    private $logger;

    public function __construct($logger = null)
    {
        if ($logger) {
            $this->logger = $logger;
        }
        if (!file_exists(self::CONVERT_SCRIPT_PATH)) {
            throw new \Exception('Script for csv handling does not exist: ' . self::CONVERT_SCRIPT_PATH);
        }
    }

    protected static function getColumnNames($columns)
    {
        $csvHeaders = [];
        foreach ($columns as $columnName => $column) {
            $csvHeaders[] = $columnName;
            if (isset($column['type']) && $column['type'] == 'DATE' && !empty($column['includeTime'])) {
                $csvHeaders[] = $columnName . '_tm';
                $csvHeaders[] = $columnName . '_id';
            }
        }

        return $csvHeaders;
    }


    /**
     * Parse csv and prepare for data load
     */
    protected function getConvertCsvCommand($columns)
    {
        $timeColumns = [];
        $i = 1;
        foreach ($columns as $column) {
            if (isset($column['type']) && $column['type'] == 'DATE' && !empty($column['includeTime'])) {
                $timeColumns[] = $i;
            }
            $i++;
        }

        if (!count($timeColumns)) {
            return false;
        }

        return 'php ' . escapeshellarg(self::CONVERT_SCRIPT_PATH) . ' -t' . implode(',', $timeColumns);
    }

    protected function getReadFileCommand($columns, $csvFile)
    {
        $command =
            'echo ' . escapeshellarg('"' . implode('","', self::getColumnNames($columns)) . '"') . '; '
            . 'cat ' . escapeshellarg($csvFile) . ' | tail -n +2 ';

        $convertCsvCommand = $this->getConvertCsvCommand($columns);
        if ($convertCsvCommand) {
            $command .= '| ' . $convertCsvCommand;
        }
        return $command;
    }

    public function convert($gzipFile, $outFile, $columns)
    {
        $command = "({$this->getReadFileCommand($columns, $gzipFile)}) > $outFile";
        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();
        $error = $process->getErrorOutput();
        if ($error) {
            $error = str_replace([
                "\ngzip: stdin: unexpected end of file\n",
                "gzip: (stdin): unexpected end of file",
                "tail: write error: Broken pipe",
                "tail: write error",
                "cat: stdout: Broken pipe"
            ], "", $error);
            throw new UserException("CSV handling failed. $error");
        }
        return ['file' => $outFile, 'command' => ['c' => $command, 'outputSize' => filesize($outFile)]];
    }
}
