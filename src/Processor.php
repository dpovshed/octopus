<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use Exception;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LibEventLoop;
use React\EventLoop\Timer\Timer;
use React\HttpClient\Client as HttpClient;
use React\Promise\Timer\TimeoutException;
use function React\Promise\race;
use function React\Promise\Timer\reject;

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
     * @var array
     */
    private $requests = [];

    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var Browser
     */
    private $browser;

    /**
     * @var LibEventLoop
     */
    private $loop;

    public function __construct(Config $config, TargetManager $targets)
    {
        $this->targetManager = $targets;
        $this->config = $config;
        $this->saveEnabled = $config->outputMode === 'save';
        if ($this->saveEnabled || $config->outputBroken) {
            $this->savePath = $config->outputDestination.DIRECTORY_SEPARATOR;
            if (!@\mkdir($this->savePath) && !\is_dir($this->savePath)) {
                throw new Exception('Cannot create output directory: '.$this->savePath);
            }
        }
    }

    public function timerStatistics(Timer $timer): void
    {
        $countQueue = $this->targetManager->countQueue();
        $countRunning = $this->targetManager->countRunning();
        $countFinished = $this->targetManager->countFinished();

        $codeInfo = [];
        foreach ($this->statCodes as $code => $count) {
            $codeInfo[] = \sprintf('%s: %d', $code, $count);
        }

        echo \sprintf(
            " %5.1fMB %6.2f sec. Queued/running/done: %d/%d/%d. Statistics: %s           \r",
            \memory_get_usage(true) / 1048576,
            \microtime(true) - $this->started,
            $countQueue,
            $countRunning,
            $countFinished,
            \implode(' ', $codeInfo)
        );

        if (($countQueue + $countRunning) === 0) {
            $timer->cancel();
        }
    }

    public function warmUp(): void
    {
        $this->loop = EventLoopFactory::create();
        $this->client = new HttpClient($this->loop);
        $this->browser = new Browser($this->loop);
        $this->browser = $this->browser->withOptions([
            // We are using own mechanism of following redirects to correctly count these.
            'followRedirects' => false,
        ]);
    }

    public function run(): void
    {
        $this->loop->addPeriodicTimer($this->config->timerUI, [$this, 'timerStatistics']);
        $this->loop->addPeriodicTimer($this->config->timerQueue, function (Timer $timer) {
            if ($this->targetManager->hasFreeSlots()) {
                $this->spawnBundle();
            } elseif ($this->targetManager->noMoreUrlsToProcess()) {
                $timer->cancel();
            }
        });

        $this->started = \microtime(true);
        $this->loop->run();
    }

    public function spawnBundle(): void
    {
        for ($i = $this->targetManager->getFreeSlots(); $i > 0; --$i) {
            //list($id, $url) = $this->targets->launchAny(); //TODO make configurable to either launch the next, or a random URL
            list($id, $url) = $this->targetManager->launchNext();
            $this->spawn($id, $url);
        }
    }

    private function spawn(int $id, string $url): void
    {
        $this->spawnWithBrowser($id, $url);

        return;
    }

    private function spawnWithBrowser(int $id, string $url): void
    {
        $requestType = \mb_strtolower($this->config->requestType);

        race([
                reject($this->config->timeout, $this->loop),
                $this->browser->$requestType($url, $this->config->requestHeaders),
            ]
        )->then(
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
                $this->statCodes[$httpResponseCode] = isset($this->statCodes[$httpResponseCode]) ? $this->statCodes[$httpResponseCode] + 1 : 1;
                $this->targetManager->done($id);

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
            },
            function (Exception $exception) use ($id, $url) {
                $failType = 'failed'; // Generic, cannot qualify with more details.
                if (\is_a($exception, TimeoutException::class)) {
                    $failType = 'timeouted';
                } elseif (\is_a($exception, ResponseException::class) && $exception->getCode() >= 300) {
                    // Regular HTTP error code.
                    $failType = $exception->getCode();
                }

                if (!isset($this->statCodes[$failType])) {
                    // Init just to prevent warning.
                    $this->statCodes[$failType] = 0;
                }
                ++$this->statCodes[$failType];
                $this->targetManager->done($id);
                $this->brokenUrls[$url] = $failType;

                echo $url.' request error: '.$exception->getMessage().PHP_EOL;
            }
        );

        if ($this->config->spawnDelayMax) {
            \usleep(\random_int($this->config->spawnDelayMin, $this->config->spawnDelayMax));
        }
    }

    private function countAdditionalHeaders(array $headers): void
    {
        if (\is_array($this->config->additionalResponseHeadersToCount) && \count($this->config->additionalResponseHeadersToCount) > 0) {
            foreach ($this->config->additionalResponseHeadersToCount as $additionalHeader) {
                if (isset($headers[$additionalHeader])) {
                    $headerLabel = \sprintf('%s (%s)', $additionalHeader, $headers[$additionalHeader][0]);
                    $this->statCodes[$headerLabel] = isset($this->statCodes[$headerLabel]) ? $this->statCodes[$headerLabel] + 1 : 1;
                }
            }
        }
    }

    private function makeFilename(string $octopusUrl, int $octopusId): string
    {
        return \preg_replace('/[^a-zA-Z0-9]/', '_', $octopusUrl.'_____'.$octopusId);
    }
}
