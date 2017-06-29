<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (0 === error_reporting()) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
