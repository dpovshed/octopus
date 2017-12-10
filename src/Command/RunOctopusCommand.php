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
    private const COMMAND_ARGUMENT_SITEMAP_FILE = 'sitemap';

    /**
     * @var string
     */
    private const COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT = 'additionalResponseHeadersToCount';

    /**
     * @var string
     */
    private const COMMAND_OPTION_CONCURRENCY = 'concurrency';

    /**
     * @var string
     */
    private const COMMAND_OPTION_TIMEOUT = 'timeout';

    /**
     * @var string
     */
    private const COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS = 'followRedirects';

    /**
     * @var string
     */
    private const COMMAND_OPTION_USER_AGENT = 'userAgent';

    /**
     * @var string
     */
    private const COMMAND_OPTION_REQUEST_TYPE = 'requestType';

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
            ->addArgument(self::COMMAND_ARGUMENT_SITEMAP_FILE, InputArgument::REQUIRED, 'What is the location of the sitemap you want to crawl?')
            ->addOption(self::COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT, null, InputOption::VALUE_OPTIONAL, 'A comma separated list of the additional response headers to keep track of / count during crawling')
            ->addOption(self::COMMAND_OPTION_CONCURRENCY, null, InputOption::VALUE_OPTIONAL, 'The amount of connections used concurrently. Defaults to ' . Config::CONCURRENCY_DEFAULT)
            ->addOption(self::COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS, null, InputOption::VALUE_OPTIONAL, 'Should the crawler follow HTTP redirects? Defaults to ' . (Config::FOLLOW_HTTP_REDIRECTS_DEFAULT ? 'true' : 'false'))
            ->addOption(self::COMMAND_OPTION_USER_AGENT, null, InputOption::VALUE_OPTIONAL, 'The UserAgent to use when issuing requests, defaults to ' . Config::REQUEST_HEADER_USER_AGENT_DEFAULT)
            ->addOption(self::COMMAND_OPTION_REQUEST_TYPE, null, InputOption::VALUE_OPTIONAL, 'The type of HTTP request, HEAD put lesser load to server while GET loads whole page. Defaults to ' . Config::REQUEST_TYPE_DEFAULT)
            ->addOption(self::COMMAND_OPTION_TIMEOUT, null, InputOption::VALUE_OPTIONAL, 'Timeout for a request, in seconds. Defaults to ' . Config::TIMEOUT_DEFAULT)
            ->setHelp(
                sprintf(
                    'Usage:
<info> - php application.php %1$s http://www.domain.ext/sitemap.xml</info>
using a specific concurrency:
<info> - php application.php %1$s http://www.domain.ext/sitemap.xml --%2$s 15</info>',
                    $this->getName(),
                    self::COMMAND_OPTION_CONCURRENCY
                )
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->crawlingStartedDateTime = new DateTime();
        $output->writeln('Starting Octopus Sitemap Crawler');

        $config = $this->determineConfiguration($input, $output);
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

    private function determineConfiguration(InputInterface $input, OutputInterface $output): Config
    {
        $config = new Config();
        $config->targetFile = $input->getArgument(self::COMMAND_ARGUMENT_SITEMAP_FILE);
        $output->writeln('Loading URLs from Sitemap: ' . $config->targetFile);

        if (is_string($input->getOption(self::COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT))) {
            $additionalResponseHeadersToCount = $input->getOption(self::COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT);
            $config->additionalResponseHeadersToCount = explode(',', $additionalResponseHeadersToCount);
            $output->writeln('Keep track of additional response headers: ' . $additionalResponseHeadersToCount);
        }
        if (is_numeric($input->getOption(self::COMMAND_OPTION_CONCURRENCY))) {
            $config->concurrency = (int)$input->getOption(self::COMMAND_OPTION_CONCURRENCY);
            $output->writeln('Using concurrency: ' . $config->concurrency);
        }
        if (is_string($input->getOption(self::COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS))) {
            $followRedirectsValue = $input->getOption(self::COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS);
            $config->followRedirects = $followRedirectsValue === 'true' ? true : false;
            $output->writeln('Follow HTTP redirects: ' . $followRedirectsValue);
        }
        if (is_string($input->getOption(self::COMMAND_OPTION_USER_AGENT))) {
            $userAgentValue = $input->getOption(self::COMMAND_OPTION_USER_AGENT);
            $config->requestHeaders[$config::REQUEST_HEADER_USER_AGENT] = $userAgentValue;
            $output->writeln('Use UserAgent for issued requests: ' . $userAgentValue);
        }
        if (is_string($input->getOption(self::COMMAND_OPTION_REQUEST_TYPE))) {
            $config->requestType = $input->getOption(self::COMMAND_OPTION_REQUEST_TYPE);
            $output->writeln('Using request type: ' . $config->requestType);
        }
        if (is_numeric($input->getOption(self::COMMAND_OPTION_TIMEOUT))) {
            $config->timeout = (float)$input->getOption(self::COMMAND_OPTION_TIMEOUT);
            $output->writeln('Using per-request timeout: ' . $config->timeout);
        }

        $config->validate();

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
        ksort($processor->statCodes);
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
                array(new TableCell('Redirected #URLs: ' . count($processor->redirectedUrls), $rowColumnSpan)),
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
        foreach ($processor->brokenUrls as $url => $reason) {
            $label = sprintf('Failed %s: %s', $reason, $url);
            $output->writeln($label);
            $content[] = $label;
        }

        file_put_contents(
            $outputDestination . '/broken.txt',
            implode(PHP_EOL, $content)
        );
    }
}
