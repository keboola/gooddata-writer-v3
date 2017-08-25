<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Keboola\Csv\CsvFile;
use Keboola\GoodData\Client;
use Keboola\Temp\Temp;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class App
{
    /** @var  ConsoleOutput */
    private $consoleOutput;

    public function __construct($consoleOutput)
    {
        $this->consoleOutput = $consoleOutput;
    }

    public function run($config, $inputPath)
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
        $pid = $config['parameters']['project']['pid'];

        if (!isset($config['storage']['input']['tables']) || !count($config['storage']['input']['tables'])) {
            throw new UserException('There are no tables on input');
        }
        if (!isset($config['parameters']['tables']) || !count($config['parameters']['tables'])) {
            throw new UserException('There are no configured tables');
        }

        $gdClient = $this->initGoodDataClient($config);
        $temp = new Temp();


        // Date dimensions
        if (isset($config['parameters']['dimensions']) && count($config['parameters']['dimensions'])) {
            foreach ($config['parameters']['dimensions'] as $dimensionName => $dimension) {
                $identifier = !empty($dimension['identifier']) ? $dimension['identifier']
                    : $gdClient->getDateDimensions()->getDefaultIdentifier($dimensionName);
                $template = !empty($dimension['template']) ? $dimension['template'] : null;
                if (!$gdClient->getDateDimensions()->exists($pid, $dimensionName, $template)) {
                    $gdClient->getDateDimensions()->executeCreateMaql($pid, $dimensionName, $identifier, $template);
                }
                if (!empty($dimension['includeTime'])) {
                    $td = new \Keboola\GoodData\TimeDimension($gdClient);
                    if (!$td->exists($pid, $dimensionName, $identifier)) {
                        $td->executeCreateMaql($pid, $dimensionName, $identifier);
                        $td->loadData($pid, $dimensionName, $temp->getTmpFolder());
                    }
                }
            }
        }


        $tables = $config['parameters']['tables'];
        foreach ($config['storage']['input']['tables'] as $table) {
            if (!isset($table['source']) || !isset($table['destination'])) {
                throw new \Exception('Missing table source in storage: '
                    . (new JsonEncode())->encode($config['storage'], JsonEncoder::FORMAT));
            }
            if (!isset($tables[$table['source']])) {
                throw new UserException("Table {$table['source']} is not configured");
            }
            $tableDefinition = $tables[$table['source']];
            print_r($tableDefinition);
            $file = new CsvFile("$inputPath/{$table['destination']}");
            print_r($file);
        }
    }

    protected function initGoodDataClient($config)
    {
        $gdClient = new Client();
        $gdClient->setUserAgent('gooddata-writer-v3', getenv('KBC_RUNID'));
        if (isset($config['parameters']['project']['backendUrl'])) {
            $gdClient->setApiUrl($config['parameters']['project']['backendUrl']);
        }
        $gdClient->login($config['parameters']['user']['login'], $config['parameters']['user']['#password']);
        return $gdClient;
    }
}
