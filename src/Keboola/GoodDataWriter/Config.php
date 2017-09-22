<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class Config
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
        return true;
    }
}
