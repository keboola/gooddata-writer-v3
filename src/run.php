<?php

declare(strict_types=1);

use Keboola\Component\UserException;
use Keboola\Component\Logger;
use Keboola\GoodDataWriter\Component;

require __DIR__ . '/../vendor/autoload.php';

$logger = new Logger();
try {
    $app = new Component($logger);
    $app->execute();
    exit(0);
} catch (UserException $e) {
    $logger->error($e->getMessage());
    exit(1);
} catch (\Keboola\GoodData\Exception $e) {
    $logger->error($e->getMessage());
    exit(1);
} catch (\Throwable $e) {
    $message = $e->getMessage();
    if ($e instanceof \GuzzleHttp\Exception\ClientException) {
        $message = $e->getResponse()->getBody()->getContents();
    }
    $logger->critical(
        get_class($e) . ':' . $message,
        [
            'errMessage' => $message,
            'errFile' => $e->getFile(),
            'errLine' => $e->getLine(),
            'errCode' => $e->getCode(),
            'errTrace' => $e->getTraceAsString(),
            'errPrevious' => $e->getPrevious() ? get_class($e->getPrevious()) : '',
        ]
    );
    exit(2);
}
