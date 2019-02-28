<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use Exception;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use function React\Promise\Timer\timeout;
use React\Promise\Timer\TimeoutException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Processor core.
 */
class Processor
{
    /**
     * @var array
     */
    public $statCodes = [];

    /**
     * Total amount of processed data.
     *
     * @var int
     */
    public $totalData = 0;

    /**
     * URLs that could not be loaded.
     *
     * @var array
     */
    public $brokenUrls = [];

    /**
     * URLs that were redirected to another location.
     *
     * @var array
     */
    public $redirectedUrls = [];

    /**
     * @var Config
     */
    public $config;

    /**
     * @var ConsoleOutput
     */
    public $output;

    /**
     * @var array
     */
    private $httpRedirectionResponseCodes = [301, 302, 303, 307, 308];

    /**
     * @var bool
     */
    private $saveEnabled;

    /**
     * to use with configuration elements.
     *
     * @var string
     */
    private $savePath;

    /**
     * Timestamp to track execution time.
     *
     * @var float
     */
    private $started;

    /**
     * @var TargetManager
     */
    private $targetManager;

    /**
     * @var Browser
     */
    private $browser;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var Table[]
     */
    private $tables = [];

    public function __construct(Config $config, TargetManager $targets, ConsoleOutputInterface $output)
    {
        $this->targetManager = $targets;
        $this->config = $config;
        $this->output = $output;
        $this->saveEnabled = $config->outputMode === 'save';
        if ($this->saveEnabled || $config->outputBroken) {
            $this->savePath = $config->outputDestination.\DIRECTORY_SEPARATOR;
            if (!@\mkdir($this->savePath) && !\is_dir($this->savePath)) {
                throw new Exception('Cannot create output directory: '.$this->savePath);
            }
        }
    }

    public function timerStatistics(Timer $timer): void
    {
        $this->reportProgress();

        if ($this->targetManager->noMoreUrlsToProcess()) {
            $this->getLoop()->cancelTimer($timer);
        }
    }

    public function run(): void
    {
        $this->getLoop()->addPeriodicTimer($this->config->timerUI, [$this, 'timerStatistics']);
        $this->getLoop()->addPeriodicTimer($this->config->timerQueue, function (Timer $timer) {
            if ($this->targetManager->hasFreeSlots()) {
                $this->spawnBundle();
            } elseif ($this->targetManager->noMoreUrlsToProcess()) {
                $this->getLoop()->cancelTimer($timer);
            }
        });

        $this->started = \microtime(true);
        $this->getLoop()->run();
    }

    public function spawnBundle(): void
    {
        for ($i = $this->targetManager->getFreeSlots(); $i > 0; --$i) {
            //[$id, $url] = $this->targets->launchAny(); //TODO make configurable to either launch the next, or a random URL
            [$id, $url] = $this->targetManager->launchNext();
            $this->spawn($id, $url);
        }
    }

    private function getBrowser(): Browser
    {
        return $this->browser ?: $this->browser = (new Browser($this->getLoop()))->withOptions([
            'followRedirects' => false, // We are using own mechanism of following redirects to correctly count these.
        ]);
    }

    private function getLoop(): LoopInterface
    {
        return $this->loop ?: $this->loop = EventLoopFactory::create();
    }

    private function spawn(int $id, string $url): void
    {
        $this->spawnWithBrowser($id, $url);

        if ($this->config->spawnDelayMax) {
            \usleep(\random_int($this->config->spawnDelayMin, $this->config->spawnDelayMax));
        }
    }

    private function spawnWithBrowser(int $id, string $url): void
    {
        $requestType = \mb_strtolower($this->config->requestType);

        $promise = $this->getBrowser()->$requestType($url, $this->config->requestHeaders);

        timeout($promise, $this->config->timeout, $this->getLoop())
            ->then(
                function (ResponseInterface $response) use ($id, $url) {
                    $this->countAdditionalHeaders($response->getHeaders());

                    if ($this->saveEnabled) {
                        $path = $this->savePath.$this->makeFilename($url, $id);
                        if (\file_put_contents($path, $response->getBody(), FILE_APPEND) === false) {
                            throw new Exception("Cannot write file: $path");
                        }
                    }

                    $this->totalData += $response->getBody()->getSize();

                    $httpResponseCode = $response->getStatusCode();
                    $this->bumpStatusCode($httpResponseCode);
                    $this->targetManager->done($id);

                    // $this->reportProgress();

                    if ($this->config->followRedirects && \in_array($httpResponseCode, $this->httpRedirectionResponseCodes, true)) {
                        $headers = $response->getHeaders();
                        $newLocation = $headers['Location'][0];
                        $this->redirectedUrls[$url] = $newLocation;
                        $this->targetManager->add($newLocation);

                        return;
                    }

                    // Any 2xx code is 'success' for us, if not => failure
                    if ((int) ($httpResponseCode / 100) !== 2) {
                        $this->brokenUrls[$url] = $httpResponseCode;

                        return;
                    }

                    if (\random_int(0, 100) < $this->config->bonusRespawn) {
                        $this->targetManager->add($url);
                    }
                }
            )
            ->otherwise(
                function (TimeoutException $exception) use ($id, $url) {
                    $failType = 'timeouted';
                    $this->bumpStatusCode($failType);
                    $this->targetManager->done($id);
                    $this->brokenUrls[$url] = $failType;

                    echo $url.' request error: '.$exception->getMessage().PHP_EOL;
                }
            )
            ->otherwise(
                function (ResponseException $exception) use ($id, $url) {
                    $failType = $exception->getCode();
                    $this->bumpStatusCode($failType);
                    $this->targetManager->done($id);
                    $this->brokenUrls[$url] = $failType;

                    echo $url.' request error: '.$exception->getMessage().PHP_EOL;
                }
            )
            ->otherwise(
                function ($error) use ($id, $url) {
                    $failType = 'failed'; // Generic error type, cannot qualify with more details.
                    $this->bumpStatusCode($failType);
                    $this->targetManager->done($id);
                    $this->brokenUrls[$url] = $failType;

                    echo $url.' request error: '.\print_r($error, true).PHP_EOL;
                }
            );
    }

    private function bumpStatusCode($statusCode): void
    {
        $this->statCodes[$statusCode] = $this->statCodes[$statusCode] ?? 0;

        ++$this->statCodes[$statusCode];
    }

    private function countAdditionalHeaders(array $headers): void
    {
        foreach ($this->config->additionalResponseHeadersToCount as $additionalHeader) {
            if (isset($headers[$additionalHeader])) {
                $headerLabel = \sprintf('%s (%s)', $additionalHeader, $headers[$additionalHeader][0]);
                $this->bumpStatusCode($headerLabel);
            }
        }
    }

    private function reportProgress(): void
    {
        $status = [
            'Memory' => \sprintf('%5.1fMB', \memory_get_usage(true) / 1048576),
            'Time' => \sprintf('%6.2f sec', \microtime(true) - $this->started),
            'Queued' => $this->targetManager->countQueue(),
            'Running' => $this->targetManager->countRunning(),
            'Done' => $this->targetManager->countFinished(),
        ];

        $rows = $status + $this->statCodes;
        $tableHeaders = \array_keys($rows);

        $key = \md5(\implode(', ', $tableHeaders));

        $table = $this->tables[$key] ?? $this->tables[$key] = $this->getTable($tableHeaders);
        $table->appendRow($rows);
    }

    private function getTable(array $tableHeaders): Table
    {
        $table = new Table($this->getOutput()->section());
        $table->setHeaders($tableHeaders);
        $table->render(); //Render once, then append rows to gradually populate the table

        return $table;
    }

    private function getOutput(): ConsoleOutput
    {
        return $this->output;
    }

    private function getStatusRow(): array
    {
        return [
            \count($this->packageOptions),
            \count($this->packageOptionsWithGrossPrice),
            //count($this->packageOptionsWithoutGrossPrice),
            \count($this->packageOptionsWithTaxTariff),
            //count($this->packageOptionsWithoutTaxTariff),
            \count($this->packageOptionsWithTaxationDate),
            //count($this->packageOptionsWithoutTaxationDate),
            \count($this->packageOptionsWithTaxRate),
            //count($this->packageOptionsWithoutTaxRate),
            (\memory_get_peak_usage(true) / 1024 / 1024).' MiB',
        ];
    }

    private function makeFilename(string $octopusUrl, int $octopusId): string
    {
        return \preg_replace('/[^a-zA-Z0-9]/', '_', $octopusUrl.'_____'.$octopusId);
    }
}
