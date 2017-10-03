<?php

declare(strict_types=1);

namespace Octopus\Command;

use DateTime;
use Octopus\Config;
use Octopus\Processor as OctopusProcessor;
use Octopus\TargetManager as OctopusTargetManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunOctopusCommand extends Command
{
    /**
     * @var string
     */
    private const SITEMAP_FILE = 'sitemap';

    /**
     * @var string
     */
    private const CONCURRENCY = 'concurrency';

    /**
     * @var string
     */
    private const DATE_FORMAT = DateTime::W3C;

    /**
     * @var DateTime
     */
    private $crawlingStartedDateTime;

    /**
     * @var DateTime
     */
    private $crawlingEndedDateTime;

    protected function configure(): void
    {
        $this
            ->setName('octopus:run')
            ->setDescription('Run the Octopus Sitemap Crawler.')
            ->addArgument(self::SITEMAP_FILE, InputArgument::REQUIRED, 'What is the location of the sitemap you want to crawl?')
            ->addOption(self::CONCURRENCY, null, InputOption::VALUE_OPTIONAL, 'The amount of connections used concurrently')
            ->setHelp(
                sprintf(
                    'Usage:
<info> - php application.php %1$s http://www.domain.ext/sitemap.xml</info>
using a specific concurrency:
<info> - php application.php %1$s http://www.domain.ext/sitemap.xml --%2$s 15</info>',
                    $this->getName(),
                    self::CONCURRENCY
                )
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->crawlingStartedDateTime = new DateTime();
        $output->writeln('Starting Octopus Sitemap Crawler');

        $config = $this->determineConfiguration($input);
        $targetManager = new OctopusTargetManager($config);
        $processor = new OctopusProcessor($config, $targetManager);

        $this->runProcessor($processor, $targetManager, $output);

        $this->crawlingEndedDateTime = new DateTime();

        $output->writeln(str_repeat(PHP_EOL, 2));
        $this->renderResultsTable($output, $processor);


        if ($config->outputBroken && count($processor->brokenUrls) > 0) {
            $this->outputBrokenUrls($processor, $output, $config->outputDestination);
        }
    }

    private function determineConfiguration(InputInterface $input): Config
    {
        $config = new Config();
        $config->targetFile = $input->getArgument(self::SITEMAP_FILE);
        if (is_numeric($input->getOption(self::CONCURRENCY))) {
            $config->concurrency = (int)$input->getOption(self::CONCURRENCY);
        }

        return $config;
    }

    private function runProcessor(OctopusProcessor $processor, OctopusTargetManager $targetManager, OutputInterface $output): void
    {
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
    }

    private function renderResultsTable(OutputInterface $output, OctopusProcessor $processor): void
    {
        $rowColumnSpan = array('colspan' => count($processor->statCodes));

        $table = new Table($output);
        $table->setHeaders(
            array(
                array(new TableCell('Crawling summary for: ' . $processor->config->targetFile, $rowColumnSpan)),
                array_keys($processor->statCodes),
            )
        );
        $table->addRow(array_values($processor->statCodes));
        $table->addRow(new TableSeparator());

        $table->addRows(
            array(
                array(new TableCell('Crawling started: ' . $this->getCrawlingStartedLabel(), $rowColumnSpan)),
                array(new TableCell('Crawling ended: ' . $this->getCrawlingEndedLabel(), $rowColumnSpan)),
                array(new TableCell('Crawling duration: ' . $this->getCrawlingDurationLabel(), $rowColumnSpan)),
                array(new TableCell('Applied concurrency: ' . $processor->config->concurrency, $rowColumnSpan)),
                array(new TableCell('Total amount of processed data: ' . $processor->totalData, $rowColumnSpan)),
                array(new TableCell('Failed to load #URLs: ' . count($processor->brokenUrls), $rowColumnSpan)),
            )
        );
        $table->render();
    }

    private function getCrawlingStartedLabel(): string
    {
        return $this->crawlingStartedDateTime->format(self::DATE_FORMAT);
    }

    private function getCrawlingEndedLabel(): string
    {
        return $this->crawlingEndedDateTime->format(self::DATE_FORMAT);
    }

    private function getCrawlingDurationLabel(): string
    {
        $numberOfSeconds = $this->crawlingEndedDateTime->getTimestamp() - $this->crawlingStartedDateTime->getTimestamp();

        return sprintf('%d seconds', $numberOfSeconds);
    }

    private function outputBrokenUrls(OctopusProcessor $processor, OutputInterface $output, string $outputDestination): void
    {
        $content = array();
        foreach ($processor->brokenUrls as $url => $httpStatusCode) {
            $label = sprintf('Failed %d: %s', $httpStatusCode, $url);
            $output->writeln($label);
            $content[] = $label;
        }

        file_put_contents(
            $outputDestination . '/broken.txt',
            implode(PHP_EOL, $content)
        );
    }
}
