<?php

declare(strict_types=1);

namespace Octopus\Command;

use Octopus\Config as OctopusConfig;
use Octopus\Processor as OctopusProcessor;
use Octopus\TargetManager as OctopusTargetManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunOctopusCommand extends Command
{
    private const SITEMAP_FILE = 'sitemap';

    protected function configure(): void
    {
        $this
            ->setName('octopus:run')
            ->setDescription('Run the Octopus Sitemap Crawler.')
            ->addArgument(self::SITEMAP_FILE, InputArgument::REQUIRED, 'What is the location of the sitemap you want to crawl?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Starting Octopus Sitemap Crawler');
        $config = new OctopusConfig();
        $config->targetFile = $input->getArgument(self::SITEMAP_FILE);
        $targetManager = new OctopusTargetManager($config);
        $processor = new OctopusProcessor($config, $targetManager);

        try {
            $numberOfQueuedFiles = $targetManager->populate();
            $output->writeln($numberOfQueuedFiles . ' URLs queued for crawling');
            $processor->warmUp();
            $processor->spawnBundle();
        } catch (\Exception $e) {
            $output->writeln('Exception on initialization: ' . $e->getMessage());
            exit;
        }

        while ($targetManager->countQueue()) {
            $processor->run();
        }

        $output->writeln(str_repeat(PHP_EOL, 2));
        $this->renderResultsTable($output, $processor);

        if ($config->outputBroken && count($processor->brokenUrls) > 0) {
            $content = array();
            foreach ($processor->brokenUrls as $url => $httpStatusCode) {
                $label = sprintf('Failed %d: %s', $httpStatusCode, $url);
                $output->writeln($label);
                $content[] = $label;
            }
            file_put_contents($config->outputDestination . '/broken.txt',
                implode(PHP_EOL, $content)
            );
        }
    }

    private function renderResultsTable(OutputInterface $output, OctopusProcessor $processor): void
    {
        $table = new Table($output);
        $table->setHeaders(
            array(
                array(new TableCell('Crawling summary for: ' . $processor->config->targetFile, array('colspan' => count($processor->statCodes)))),
                array_keys($processor->statCodes),
            )
        );
        $table->addRow(array_values($processor->statCodes));
        $table->addRow(new TableSeparator());
        $table->addRows(
            array(
                array(new TableCell('Applied concurrency: ' . $processor->config->concurrency, array('colspan' => count($processor->statCodes)))),
                array(new TableCell('Total amount of processed data: ' . $processor->totalData, array('colspan' => count($processor->statCodes)))),
                array(new TableCell('Failed to load #URLs: ' . count($processor->brokenUrls), array('colspan' => count($processor->statCodes)))),
            )
        );
        $table->render();
    }
}
