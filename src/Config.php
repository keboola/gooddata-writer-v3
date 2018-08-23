<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\Config\BaseConfig;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class Config extends BaseConfig
{
    public static function check($config)
    {
        if (!isset($config['parameters']['user']['login'])) {
            throw new UserException('User login is missing from configuration');
        }
        if (!isset($config['parameters']['user']['#password'])) {
            throw new UserException('User password is missing from configuration');
        }
        if (!isset($config['parameters']['project']['pid'])) {
            throw new UserException('Project pid is missing from configuration');
        }
        if (!isset($config['storage']['input']['tables']) || !count($config['storage']['input']['tables'])) {
            throw new UserException('There are no tables on input');
        }
        if (!isset($config['parameters']['tables']) || !count($config['parameters']['tables'])) {
            throw new UserException('There are no configured tables');
        }
        foreach ($config['storage']['input']['tables'] as $table) {
            if (!isset($table['source']) || !isset($table['destination'])) {
                throw new \Exception('Wrong storage configuration: '
                    . (new JsonEncode())->encode($config['storage'], JsonEncoder::FORMAT));
            }
            if (!isset($config['parameters']['tables'][$table['source']])) {
                throw new UserException("Table {$table['source']} is not configured");
            }
        }
        if (!isset($config['image_parameters']['provisioning_url'])) {
            throw new \Exception('Provisioning url is missing from image parameters');
        }
        return true;
    }
}
