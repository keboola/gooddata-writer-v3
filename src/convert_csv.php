<?php

declare(strict_types=1);

/**
 * Gets csv and convert to GoodData load
 * Arguments:
 * -h names of columns to header separated by comma
 * -t orders of time columns separated by comma
 */

date_default_timezone_set('UTC');

$fh = fopen('php://stdin', 'r');
$stdErr = fopen('php://stderr', 'w');
$stdOut = fopen('php://stdout', 'w');
if (!$fh) {
    fwrite($stdErr, "Error in reading file");
    die(1);
}

$options = getopt('t::');

$timeColumns = [];
if (isset($options['t'])) {
    $timeColumns = explode(',', $options['t']);
}

$start = strtotime('1900-01-01 00:00:00');

$rowNumber = 2; // we have to add the header
while ($line = fgetcsv($fh, null, ',', '"', chr(0))) {
    $resultLine = '';

    foreach ($line as $i => $column) {
        if (in_array($i+1, $timeColumns)) {
            $resultLine .= '"' . $column . '",';

            if (is_numeric($column)) {
                $timestamp = $column;
            } else {
                $timestamp = empty($column) ? $start : strtotime($column);
                if ($timestamp === false) {
                    fwrite($stdErr, sprintf('Error in date column value: "%s" on row %d', $column, $rowNumber));
                    die(1);
                }
            }
            if ($start > $timestamp) {
                fwrite($stdErr, sprintf('Error in date column value: "%s" on row %d', $column, $rowNumber));
                die(1);
            }

            // Add time fact (number of seconds since midnight)
            $diff = $timestamp - $start;
            $daysDiff = floor($diff / (60 * 60 * 24));
            $timeFact = $diff - ($daysDiff * 60*60*24);
            $resultLine .= '"' . $timeFact . '",';
            $resultLine .= '"' . $timeFact . '",';
        } else {
            $resultLine .= '"' . str_replace('"', '""', $column) . '",';
        }
    }

    print rtrim($resultLine, ",") . "\n";
    $rowNumber++;
}
