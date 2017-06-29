<?php
/**
 * @package gooddata-writer
 * @copyright Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */
namespace Keboola\GoodDataWriter;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    protected function configure()
    {
        $this->setName('run');
        $this->setDescription('Runs the Writer');
        $this->addArgument('data directory', InputArgument::REQUIRED, 'Data directory');
    }

    protected function execute(InputInterface $input, OutputInterface $consoleOutput)
    {
        $dataDirectory = $input->getArgument('data directory');

        $configFile = "$dataDirectory/config.json";
        if (!file_exists($configFile)) {
            throw new \Exception("Config file not found at path $configFile");
        }
        $jsonDecode = new JsonDecode(true);
        $config = $jsonDecode->decode(file_get_contents($configFile), JsonEncoder::FORMAT);

        try {
            $inputPath = "$dataDirectory/in/tables";

            $validatedConfig = $this->validateInput($config);
            $validatedConfig['inputPath'] = $inputPath;
            $validatedConfig['output'] = $consoleOutput;

            $app = new App($validatedConfig);
            $app->run($validatedConfig['queries'], $validatedConfig['since'], $validatedConfig['until']);

            return 0;
        } catch (\Keboola\GoodData\Exception $e) {
            $consoleOutput->writeln($e->getMessage());
            return 1;
        } catch (Exception $e) {
            $consoleOutput->writeln($e->getMessage());
            return 1;
        } catch (\Exception $e) {
            if ($consoleOutput instanceof ConsoleOutput) {
                $consoleOutput->getErrorOutput()->writeln("{$e->getMessage()}\n{$e->getTraceAsString()}");
            } else {
                $consoleOutput->writeln("{$e->getMessage()}\n{$e->getTraceAsString()}");
            }
            return 2;
        }
    }

    public function validateInput($config)
    {
        $required = ['model'];
        foreach ($required as $r) {
            if (empty($config['parameters'][$r])) {
                throw new Exception("Missing parameter '$r'");
            }
        }
        return [
            'model' => $config['model'],
            'incrementalLoad' => date('Ymd', strtotime(isset($config['parameters']['since'])
                ? $config['parameters']['since'] : '-1 day')),
        ];
    }
}
