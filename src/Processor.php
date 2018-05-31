<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use Clue\React\Mq\Queue;
use Exception;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use function React\Promise\Timer\timeout;

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
     * @var Browser
     */
    private $browser;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var Queue
     */
    private $queue;

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

    public function run(): void
    {
        $this->started = \microtime(true);

        $this->getLoop()->addPeriodicTimer($this->config->timerUI, $this->getTimerStatisticsCallback());

        $queue = $this->getQueue();
        foreach ($this->targetManager->getQueuedUrls() as $id => $url) {
            $queue($id, $url)->then(
                $this->getOnFulfilledCallback($id, $url),
                $this->getOnRejectedCallback($id, $url),
                $this->getOnProgressCallback($id, $url)
            );
        }

        $this->getLoop()->run();
    }

    public function getTimerStatisticsCallback(): callable
    {
        return function (Timer $timer) {
            $this->renderStatistics();
            if ($this->noMoreItemsToProcess()) {
                $timer->cancel();
            }
        };
    }

    /**
     * TODO: we should probably use the Queue to check whether items are still being processed. However the 'pending' items can not be accessed.
     *
     * @see https://github.com/clue/reactphp-mq/issues/12
     *
     * @return bool
     */
    private function noMoreItemsToProcess(): bool
    {
        return $this->targetManager->countFinished() === $this->targetManager->countQueuedUrls();

        //return $this->getQueue()->count() + $this->getQueue()->getPending() === 0;
    }

    private function renderStatistics(): void
    {
        $codeInfo = [];
        foreach ($this->statCodes as $code => $count) {
            $codeInfo[] = \sprintf('%s: %d', $code, $count);
        }

        echo \sprintf(
            " %5.1fMB %6.2f sec. Queued/running/done: %d/%s/%d. Statistics: %s \r",
            \memory_get_usage(true) / 1048576,
            \microtime(true) - $this->started,
            $this->getQueue()->count(),
            \method_exists($this->getQueue(), 'getPending') ? $this->getQueue()->getPending() : 'n/a',
            $this->targetManager->countFinished(),
            \implode(' ', $codeInfo)
        );
    }

    private function getQueue(): Queue
    {
        return $this->queue ?: $this->queue = new Queue($this->config->concurrency, null, $this->getLoadUrlUsingBrowserCallback());
    }

    private function getLoadUrlUsingBrowserCallback(): callable
    {
        return function (int $id, string $url) {
            return timeout($this->loadUrlWithBrowser($id, $url), $this->config->timeout, $this->getLoop());
        };
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

    private function loadUrlWithBrowser(int $id, string $url): PromiseInterface
    {
        $requestType = \mb_strtolower($this->config->requestType);

        return $this->getBrowser()->$requestType($url, $this->config->requestHeaders);
    }

    private function getOnFulfilledCallback(int $id, string $url): callable
    {
        return function (ResponseInterface $response) use ($id, $url) {
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
            $this->targetManager->done($id, $url);

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

            //In case a URL should be loaded again once in a while, add it to the queue again
            if (\random_int(0, 100) < $this->config->bonusRespawn) {
                $this->targetManager->add($url);
            }
        };
    }

    private function getOnRejectedCallback(int $id, string $url): callable
    {
        return function (Exception $exception) use ($id, $url) {
            $failType = 'failed'; // Generic, cannot qualify with more details.
            if (\is_a($exception, TimeoutException::class)) {
                $failType = 'timeouted';
            } elseif (\is_a($exception, ResponseException::class) && $exception->getCode() >= 300) {
                $failType = $exception->getCode(); // Regular HTTP error code.
            }

            if (!isset($this->statCodes[$failType])) {
                $this->statCodes[$failType] = 0; // Init just to prevent warning.
            }
            ++$this->statCodes[$failType];
            $this->targetManager->done($id, $url);
            $this->brokenUrls[$url] = $failType;

            echo $url.' request error: '.$exception->getMessage().PHP_EOL;
        };
    }

    private function getOnProgressCallback(int $id, string $url): callable
    {
        return function () use ($id, $url) {
            echo "Progress on $id : $url";
        };
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
