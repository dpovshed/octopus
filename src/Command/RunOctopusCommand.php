<?php

declare(strict_types=1);

namespace Octopus\Command;

use DateTime;
use Octopus\Config;
use Octopus\Presenter\TablePresenter;
use Octopus\Processor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
    private const COMMAND_OPTION_PRESENTER = 'presenter';

    /**
     * @var string
     */
    private const COMMAND_OPTION_TIMEOUT = 'timeout';

    /**
     * @var string
     */
    private const COMMAND_OPTION_TIMER_UI = 'timerUI';

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
    private const COMMAND_OPTION_TARGET_TYPE = 'targetFormat';

    /**
     * @var string
     */
    private const DATE_FORMAT = DateTime::W3C;

    private DateTime $crawlingStartedDateTime;
    private DateTime $crawlingEndedDateTime;
    private InputInterface $input;
    private LoggerInterface $logger;
    private ConsoleOutputInterface $output;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->setName('octopus:run');
        $this->setDescription('Run the Octopus Sitemap Crawler.');
        $this->addArgument(self::COMMAND_ARGUMENT_SITEMAP_FILE, InputArgument::REQUIRED, 'What is the location of the sitemap you want to crawl?');
        $this->addOption(self::COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT, null, InputOption::VALUE_OPTIONAL, 'A comma separated list of the additional response headers to keep track of / count during crawling');
        $this->addOption(self::COMMAND_OPTION_CONCURRENCY, null, InputOption::VALUE_OPTIONAL, 'The amount of connections used concurrently. Defaults to '.Config::CONCURRENCY_DEFAULT);
        $this->addOption(self::COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS, null, InputOption::VALUE_OPTIONAL, 'Should the crawler follow HTTP redirects? Defaults to '.(Config::FOLLOW_HTTP_REDIRECTS_DEFAULT ? 'true' : 'false')); // @phpstan-ignore-line
        $this->addOption(self::COMMAND_OPTION_USER_AGENT, null, InputOption::VALUE_OPTIONAL, 'The UserAgent to use when issuing requests, defaults to '.Config::REQUEST_HEADER_USER_AGENT_DEFAULT);
        $this->addOption(self::COMMAND_OPTION_PRESENTER, null, InputOption::VALUE_OPTIONAL, 'The type of Presenter to display the Results, defaults to '.Config::PRESENTER_DEFAULT);
        $this->addOption(self::COMMAND_OPTION_REQUEST_TYPE, null, InputOption::VALUE_OPTIONAL, 'The type of HTTP request, HEAD put lesser load to server while GET loads whole page. Defaults to '.Config::REQUEST_TYPE_DEFAULT);
        $this->addOption(self::COMMAND_OPTION_TARGET_TYPE, null, InputOption::VALUE_OPTIONAL, 'List of URLs to parse could be provided as sitemap or plain text. Defaults to '.Config::TARGET_TYPE_DEFAULT);
        $this->addOption(self::COMMAND_OPTION_TIMEOUT, null, InputOption::VALUE_OPTIONAL, 'Timeout for a request, in seconds. Defaults to '.Config::TIMEOUT_DEFAULT);
        $this->addOption(self::COMMAND_OPTION_TIMER_UI, null, InputOption::VALUE_OPTIONAL, 'How often to update current statistics in the UserInterface. Defaults to '.Config::TIMER_UI_DEFAULT);
        $this->setHelp(
            \sprintf(
                'Usage:
<info> - php application.php %1$s http://www.domain.ext/sitemap.xml</info>
using a specific concurrency:
<info> - php application.php %1$s http://www.domain.ext/sitemap.xml --%2$s 15</info>',
                $this->getName(),
                self::COMMAND_OPTION_CONCURRENCY
            )
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        \assert($output instanceof ConsoleOutputInterface);
        $this->input = $input;
        $this->output = $output;
        $this->crawlingStartedDateTime = new DateTime();
        $this->getLogger()->debug('Starting Octopus Sitemap Crawler');

        $config = $this->determineConfiguration();
        $processor = new Processor($config, $this->getLogger());
        $processor->run();

        $this->crawlingEndedDateTime = new DateTime();

        $this->renderResultsTable($processor);

        if ($config->outputBroken && \count($processor->result->getBrokenUrls()) > 0) {
            $this->outputBrokenUrls($processor, $config->outputDestination);
        }

        return 0;
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ??= new ConsoleLogger($this->output);
    }

    private function determineConfiguration(): Config
    {
        $config = new Config();
        $config->targetFile = $this->input->getArgument(self::COMMAND_ARGUMENT_SITEMAP_FILE);
        $this->getLogger()->notice('Loading URLs from Sitemap: '.$config->targetFile);

        if (\is_string($this->input->getOption(self::COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT))) {
            $additionalResponseHeadersToCount = $this->input->getOption(self::COMMAND_OPTION_ADDITIONAL_RESPONSE_HEADERS_TO_COUNT);
            $config->additionalResponseHeadersToCount = \explode(',', $additionalResponseHeadersToCount);
            $this->getLogger()->notice('Keep track of additional response headers: '.$additionalResponseHeadersToCount);
        }
        if (\is_numeric($this->input->getOption(self::COMMAND_OPTION_CONCURRENCY))) {
            $config->concurrency = (int) $this->input->getOption(self::COMMAND_OPTION_CONCURRENCY);
            $this->getLogger()->notice('Using concurrency: '.$config->concurrency);
        }
        if (\is_string($this->input->getOption(self::COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS))) {
            $followRedirectsValue = $this->input->getOption(self::COMMAND_OPTION_FOLLOW_HTTP_REDIRECTS);
            $config->followRedirects = $followRedirectsValue === 'true';
            $this->getLogger()->notice('Follow HTTP redirects: '.$followRedirectsValue);
        }
        if (\is_string($this->input->getOption(self::COMMAND_OPTION_USER_AGENT))) {
            $userAgentValue = $this->input->getOption(self::COMMAND_OPTION_USER_AGENT);
            $config->requestHeaders[$config::REQUEST_HEADER_USER_AGENT] = $userAgentValue;
            $this->getLogger()->notice('Use UserAgent for issued requests: '.$userAgentValue);
        }
        if (\is_string($this->input->getOption(self::COMMAND_OPTION_PRESENTER))) {
            $config->presenter = $this->input->getOption(self::COMMAND_OPTION_PRESENTER);
            $this->getLogger()->notice('Using Presenter: '.$config->presenter);

            if ($config->presenter === TablePresenter::class) {
                $this->getLogger()->notice('Instantiate Presenter to be able to inject output interface');
                $config->presenter = new TablePresenter($this->output);
            }
        }
        if (\is_string($this->input->getOption(self::COMMAND_OPTION_REQUEST_TYPE))) {
            $config->requestType = $this->input->getOption(self::COMMAND_OPTION_REQUEST_TYPE);
            $this->getLogger()->notice('Using request type: '.$config->requestType);
        }
        if (\is_string($this->input->getOption(self::COMMAND_OPTION_TARGET_TYPE))) {
            $config->targetType = $this->input->getOption(self::COMMAND_OPTION_TARGET_TYPE);
            $this->getLogger()->notice('Source file format: '.$config->targetType);
        }
        if (\is_numeric($this->input->getOption(self::COMMAND_OPTION_TIMEOUT))) {
            $config->timeout = (float) $this->input->getOption(self::COMMAND_OPTION_TIMEOUT);
            $this->getLogger()->notice('Using per-request timeout: '.$config->timeout);
        }
        if (\is_numeric($this->input->getOption(self::COMMAND_OPTION_TIMER_UI))) {
            $config->timerUI = (float) $this->input->getOption(self::COMMAND_OPTION_TIMER_UI);
            $this->getLogger()->notice('Using timerUI refresh rate: '.$config->timerUI);
        }

        return $config;
    }

    private function renderResultsTable(Processor $processor): void
    {
        $statusCodes = $processor->result->getStatusCodes();

        if (\count($statusCodes) === 0) {
            return;
        }

        \ksort($statusCodes);
        $rowColumnSpan = ['colspan' => \count($statusCodes)];

        $table = new Table($this->output);
        $table->setHeaders(
            [
                [new TableCell('Crawling summary for: '.$processor->config->targetFile, $rowColumnSpan)],
                \array_keys($statusCodes),
            ]
        );
        $table->addRow(\array_values($statusCodes));
        $table->addRow(new TableSeparator());

        $table->addRows(
            [
                [new TableCell('Crawling started: '.$this->getCrawlingStartedLabel(), $rowColumnSpan)],
                [new TableCell('Crawling ended: '.$this->getCrawlingEndedLabel(), $rowColumnSpan)],
                [new TableCell('Crawling duration: '.$this->getCrawlingDurationLabel(), $rowColumnSpan)],
                [new TableCell('Applied concurrency: '.$processor->config->concurrency, $rowColumnSpan)],
                [new TableCell('Total amount of processed data: '.$processor->result->getTotalData(), $rowColumnSpan)],
                [new TableCell('Failed to load #URLs: '.\count($processor->result->getBrokenUrls()), $rowColumnSpan)],
                [new TableCell('Redirected #URLs: '.\count($processor->result->getRedirectedUrls()), $rowColumnSpan)],
            ]
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

        return \sprintf('%d seconds', $numberOfSeconds);
    }

    private function outputBrokenUrls(Processor $processor, string $outputDestination): void
    {
        $content = [];
        foreach ($processor->result->getBrokenUrls() as $url => $reason) {
            $label = \sprintf('%s: %s', $reason, $url);
            $this->getLogger()->debug(\sprintf('broken URL: %s', $label));
            $content[] = $label;
        }

        \file_put_contents(
            $outputDestination.\DIRECTORY_SEPARATOR.'broken.txt',
            \implode(PHP_EOL, $content)
        );
    }
}
