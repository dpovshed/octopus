<?php

declare(strict_types=1);

namespace Octopus\Command;

use Octopus\Config as OctopusConfig;
use Octopus\Handlers as OctopusHandlers;
use Octopus\Processor as OctopusProcessor;
use Octopus\Result as OctopusResult;
use Octopus\TargetManager as OctopusTargetManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunOctopusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('octopus:run')
            ->setDescription('Run the Octopus.');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Starting Octopus</info>');


        $config = new OctopusConfig();
        $config->targetFile = 'file: http://d7.local.127.0.0.1.xip.io/sitemap.xml';
        $result = new OctopusResult();
        $handlers = new OctopusHandlers($config, $result);
        $targets = new OctopusTargetManager($config, $result);
        $processor = new OctopusProcessor($config, $targets);

        try {
            $targets->populate();
            $processor->warmUp();
            $processor->spawnBundle(); // Fill up initial portion then go.
        } catch (\Exception $e) {
            $output->writeln('<info>Exception on initialization: ' . $e->getMessage() . '</info>');
            exit;
        }

        while ($targets->countQueue()) {
            $processor->run();
        }

        echo PHP_EOL . PHP_EOL . 'Results:' . PHP_EOL;

        ksort($processor->statCodes);
        foreach ($processor->statCodes as $code => $count) {
            echo $code . ': ' . $count . PHP_EOL;
        }

        echo 'Total data: ' . $processor->totalData . PHP_EOL;

        if ($config->outputBroken) {
            file_put_contents($config->outputDestination . '/broken.txt',
                implode(PHP_EOL, array_keys($processor->brokenUrls))
            );
        }
    }
}
