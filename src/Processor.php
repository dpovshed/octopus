<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use Clue\React\Flux\Transformer;
use Exception;
use Octopus\Sitemap\Loader as SitemapLoader;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use React\Stream\ReadableStreamInterface;
use Teapot\StatusCode\Http;
use function React\Promise\Timer\timeout;

/**
 * Processor core.
 */
class Processor
{
    /**
     * Used for indicating a general error when no more details can be provided.
     *
     * @var string
     */
    private const ERROR_TYPE_GENERAL = 'failure';

    /**
     * @var string
     */
    private const ERROR_TYPE_TIMEOUT = 'timeout';

    /**
     * @var Config
     */
    public $config;

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
     * @var array
     */
    private $httpRedirectionResponseCodes = [
        Http::MOVED_PERMANENTLY,
        Http::FOUND,
        Http::SEE_OTHER,
        Http::TEMPORARY_REDIRECT,
        Http::PERMANENT_REDIRECT,
    ];

    /**
     * @var Browser
     */
    private $browser;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var SitemapLoader
     */
    private $sitemapLoader;

    /**
     * @var Transformer
     */
    private $transformer;

    /**
     * @var Result
     */
    private $result;

    /**
     * @var bool
     */
    private $followRedirects;

    public function __construct(Config $config, LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->saveEnabled = $config->outputMode === 'save';
        if ($this->saveEnabled || $config->outputBroken) {
            $this->savePath = $config->outputDestination.\DIRECTORY_SEPARATOR;
            if (!@\mkdir($this->savePath) && !\is_dir($this->savePath)) {
                throw new Exception('Cannot create output directory: '.$this->savePath);
            }
        }

        if (\is_array($config->additionalResponseHeadersToCount)) {
            $this->getResult()->setAdditionalResponseHeadersToCount($config->additionalResponseHeadersToCount);
        }

        $this->getLoop()->addPeriodicTimer($this->config->timerUI, $this->getTimerStatisticsCallback());

        // load a collection of URLs and pass it through the Transformer
        $this->getSitemapLoader()->pipe($this->getTransformer());
    }

    public function getResult(): Result
    {
        return $this->result ?: $this->result = new Result();
    }

    public function run(): void
    {
        $this->started = \microtime(true);

        $this->getLoop()->run();
    }

    public function getTimerStatisticsCallback(): callable
    {
        return function (Timer $timer) {
            $this->renderStatistics();
            if ($this->isCompleted()) {
                $this->logger->info('no more URLs to process: stop!');
                $timer->cancel();
            }
        };
    }

    private function getLoop(): LoopInterface
    {
        return $this->loop ?: $this->loop = EventLoopFactory::create();
    }

    private function renderStatistics(): void
    {
        $message = \sprintf(
            " %s %s Queued/running/done: %d/%s/%d. Statistics: %s \r",
            $this->getMemoryUsageLabel(),
            $this->getDurationLabel(),
            $this->getNumberOfRemainingUrlsToProcess(),
            $this->config->concurrency,
            $this->getResult()->countFinishedUrls(),
            \implode(' ', $this->getStatusCodeInformation())
        );

        $this->logger->debug($message);

        echo $message;
    }

    private function getMemoryUsageLabel(): string
    {
        return \sprintf('%5.1f MB', \memory_get_usage(true) / 1048576);
    }

    private function getDurationLabel(): string
    {
        return \sprintf('%6.2f sec.', \microtime(true) - $this->started);
    }

    private function getNumberOfRemainingUrlsToProcess(): int
    {
        return $this->getSitemapLoader()->getNumberOfUrls() - $this->getResult()->countFinishedUrls();
    }

    private function getSitemapLoader(): SitemapLoader
    {
        return $this->sitemapLoader ?: $this->sitemapLoader = new SitemapLoader($this->getStream(), $this->logger);
    }

    private function getStream(): ReadableStreamInterface
    {
        return new \React\Stream\ReadableResourceStream(
            \fopen($this->config->targetFile, 'r'),
            $this->getLoop()
        );
    }

    private function getStatusCodeInformation(): array
    {
        $codeInfo = [];
        foreach ($this->getResult()->getStatusCodes() as $code => $count) {
            $codeInfo[] = \sprintf('%s: %d', $code, $count);
        }

        return $codeInfo;
    }

    private function isCompleted(): bool
    {
        return $this->getResult()->countFinishedUrls() > 0 && $this->getNumberOfRemainingUrlsToProcess() === 0;
    }

    private function getTransformer(): Transformer
    {
        return $this->transformer ?: $this->transformer = (new Transformer($this->config->concurrency, $this->getLoadUrlUsingBrowserCallback()))
            ->on('data', function ($data) {
                $this->logger->debug('Transformer received data event');
            })
            ->on('end', function () {
                $this->logger->debug('Transformer received end event');
            })
            ->on('error', function () {
                $this->logger->debug('Transformer received error event');
            });
    }

    private function getLoadUrlUsingBrowserCallback(): callable
    {
        return function (string $url) {
            $promise = $this->loadUrlWithBrowser($url)->then(
                $this->getOnFulfilledCallback($url),
                $this->getOnRejectedCallback($url)
            );

            // return $promise;

            //The timeout seems to start counting directly / in the same loop? Causing the first item to early.
            return timeout($promise, $this->config->timeout, $this->getLoop())->otherwise(
                function (TimeoutException $timeoutException) use ($url) {
                    $this->logger->error(\sprintf('failed loading %s: %s', $url, $timeoutException->getMessage()));
                }
            );
        };
    }

    private function loadUrlWithBrowser(string $url): PromiseInterface
    {
        $requestType = \mb_strtolower($this->config->requestType);

        return $this->getBrowser()->$requestType($url, $this->config->requestHeaders);
    }

    private function getBrowser(): Browser
    {
        return $this->browser ?: $this->browser = (new Browser($this->getLoop()))->withOptions([
            'followRedirects' => false, // We are using own mechanism of following redirects to correctly count these.
        ]);
    }

    private function getOnFulfilledCallback(string $url): callable
    {
        return function (ResponseInterface $response) use ($url): void {
            $this->getResult()->countAdditionalHeaders($response->getHeaders());

            /*
            if ($this->saveEnabled) {
                $path = $this->savePath.$this->makeFilename($url);
                if (\file_put_contents($path, $response->getBody(), FILE_APPEND) === false) {
                    throw new Exception("Cannot write file: $path");
                }
            }
             */

            $this->getResult()->addProcessedData($response->getBody()->getSize());

            $httpResponseCode = $response->getStatusCode();
            $this->getResult()->addStatusCode($httpResponseCode);
            $this->getResult()->done($url);

            if ($this->followRedirects && $this->isRedirectCode($httpResponseCode)) {
                $newLocation = $this->getLocationFromHeaders($response->getHeaders());
                $this->getResult()->addRedirectedUrl($url, $newLocation);
                $this->getSitemapLoader()->addUrl($newLocation);

                return;
            }

            // Any 2xx code is 'success' for us, if not => failure
            if ((int) ($httpResponseCode / 100) !== 2) {
                $this->getResult()->addBrokenUrl($url, $httpResponseCode);

                return;
            }

            /*
            //In case a URL should be loaded again once in a while, add it to the queue again
            if (\random_int(0, 100) < $this->config->bonusRespawn) {
                $this->add($url);
            }
             */
        };
    }

    private function isRedirectCode(int $httpResponseCode): bool
    {
        return \in_array($httpResponseCode, $this->httpRedirectionResponseCodes, true);
    }

    private function getLocationFromHeaders(array $headers): string
    {
        return $headers['Location'][0];
    }

    private function getOnRejectedCallback(string $url): callable
    {
        return function ($errorOrException) use ($url): void {
            $errorType = $this->getErrorType($errorOrException);
            $this->getResult()->done($url);
            $this->getResult()->addBrokenUrl($url, $errorType);

            $this->logger->error('loading {url} resulted in an error: {errorType}, {errorMessage}', [
                'url' => $url,
                'errorType' => $errorType,
                'errorMessage' => $this->getErrorMessage($errorOrException),
            ]);
        };
    }

    private function getErrorType($errorOrException): string
    {
        if ($errorOrException instanceof TimeoutException) {
            return self::ERROR_TYPE_TIMEOUT;
        }

        if ($errorOrException instanceof ResponseException && $errorOrException->getCode() >= 300) {
            return (string) $errorOrException->getCode(); // Regular HTTP error code.
        }

        return self::ERROR_TYPE_GENERAL;
    }

    private function getErrorMessage($errorOrException): string
    {
        return $errorOrException instanceof Exception ? $errorOrException->getMessage() : \print_r($errorOrException, true);
    }
}
