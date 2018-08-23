<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodDataWriter\Config;
use Keboola\GoodDataWriter\UserException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Keboola\GoodDataWriter\Config
 */
class ConfigTest extends TestCase
{
    protected $config = [
        'image_parameters' => [
            'provisioning_url' => 'url'
        ],
        'parameters' => [
            'user' => [
                'login' => 'login',
                '#password' => 'pass'
            ],
            'project' => [
                'pid' => 'pid'
            ],
            'tables' => [
                'table1' => []
            ]
        ],
        'storage' => [
            'input' => [
                'tables' => [
                    [
                        'source' => 'table1',
                        'destination' => 'destination'
                    ]
                ]
            ]
        ]
    ];

    public function testConfigCheckMissingUserPass()
    {
        $this->expectException(UserException::class);
        $config = $this->config;
        unset($config['parameters']['user']['#password']);
        $this->assertTrue(Config::check($config));
    }

    public function testConfigCheckMissingUserProject()
    {
        $this->expectException(UserException::class);
        $config = $this->config;
        unset($config['parameters']['project']['pid']);
        $this->assertTrue(Config::check($config));
    }

    public function testConfigCheckMissingTable()
    {
        $this->expectException(UserException::class);
        $config = $this->config;
        unset($config['parameters']['tables']['table1']);
        $this->assertTrue(Config::check($config));
    }

    public function testConfigCheckMissingStorage()
    {
        $this->expectException(UserException::class);
        $config = $this->config;
        $config['storage']['input']['tables'][0]['source'] = 'x';
        $this->assertTrue(Config::check($config));
    }

    public function testConfigCheckSuccess()
    {
        $this->assertTrue(Config::check($this->config));
    }
}
