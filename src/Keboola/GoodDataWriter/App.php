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
    private $output;

    public function __construct($options)
    {
        $required = ['output', 'inputPath', 'model'];
        foreach ($required as $item) {
            if (!isset($options[$item])) {
                throw new \Exception("Option $item is not set");
            }
        }

        $this->api = new Api(
            $options['oauthKey'],
            $options['oauthSecret'],
            $options['developerToken'],
            $options['refreshToken'],
            new Logger('adwords-api', [new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::WARNING)])
        );

        $this->output = $options['output'];
    }

    public function run()
    {

    }
}
