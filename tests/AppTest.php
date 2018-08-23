<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter\Test;

use Keboola\GoodData\Client;
use Keboola\GoodData\Exception;
use Keboola\GoodDataWriter\App;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * @covers \Keboola\GoodDataWriter\App
 * @covers \Keboola\GoodDataWriter\Upload
 */
class AppTest extends TestCase
{
    protected $gdClient;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->gdClient = new Client();
        $this->gdClient->setUserAgent('gooddata-writer-v3', 'test');
        $this->gdClient->login(getenv('GD_USERNAME'), getenv('GD_PASSWORD'));
        $this->cleanUpProject(getenv('GD_PID'));
    }

    public function testAppRun()
    {
        $app = new App(new ConsoleOutput());
        $params = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
        $params['parameters']['user']['login'] = getenv('GD_USERNAME');
        $params['parameters']['user']['#password'] = getenv('GD_PASSWORD');
        $params['parameters']['project']['pid'] = getenv('GD_PID');

        $this->assertCount(0, $this->getDataSets(getenv('GD_PID')));
        $app->run($params, __DIR__ . '/tables');
        $this->assertCount(5, $this->getDataSets(getenv('GD_PID')));
    }

    protected function cleanUpProject($pid)
    {
        do {
            $error = false;
            $datasets = $this->gdClient->get("/gdc/md/$pid/data/sets");
            foreach ($datasets['dataSetsInfo']['sets'] as $dataset) {
                try {
                    $this->gdClient->getDatasets()->executeMaql(
                        $pid,
                        'DROP ALL IN {' . $dataset['meta']['identifier'] . '} CASCADE'
                    );
                } catch (Exception $e) {
                    $error = true;
                }
            }
        } while ($error);

        $folders = $this->gdClient->get("/gdc/md/$pid/query/folders");
        foreach ($folders['query']['entries'] as $folder) {
            try {
                $this->gdClient->getDatasets()->executeMaql(
                    $pid,
                    'DROP {'.$folder['identifier'].'};'
                );
            } catch (Exception $e) {
            }
        }
        $dimensions = $this->gdClient->get("/gdc/md/$pid/query/dimensions");
        foreach ($dimensions['query']['entries'] as $folder) {
            try {
                $this->gdClient->getDatasets()->executeMaql($pid, 'DROP {'.$folder['identifier'].'};');
            } catch (Exception $e) {
            }
        }
    }

    protected function getDataSets($pid)
    {
        $call = $this->gdClient->get("/gdc/md/$pid/data/sets");
        $existingDataSets = [];
        foreach ($call['dataSetsInfo']['sets'] as $r) {
            $existingDataSets[] = $r['meta']['identifier'];
        }
        return $existingDataSets;
    }
}
