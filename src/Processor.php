<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use Clue\React\Flux\Transformer;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use React\Stream\ReadableResourceStream;
use React\Stream\ReadableStreamInterface;
use Teapot\StatusCode\Http;

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
     * @var Result
     */
    public $result;

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
     * @var TargetManager
     */
    private $targetManager;

    /**
     * @var Transformer
     */
    private $transformer;

    /**
     * @var bool
     */
    private $followRedirects;

    /**
     * @var Presenter
     */
    private $presenter;

    public function __construct(Config $config, LoggerInterface $logger = null)
    {
        $config->validate();

        $this->config = $config;
        $this->result = new Result($config);
        $this->logger = $logger ?? new NullLogger();

        $this->saveEnabled = $config->outputMode === Config::OUTPUT_MODE_SAVE;
        if ($this->saveEnabled || $config->outputBroken) {
            $this->savePath = $config->outputDestination.\DIRECTORY_SEPARATOR;
            if (!@\mkdir($this->savePath) && !\is_dir($this->savePath)) {
                throw new Exception('Cannot create output directory: '.$this->savePath);
            }
        }

        if (\is_array($config->additionalResponseHeadersToCount)) {
            $this->result->setAdditionalResponseHeadersToCount($config->additionalResponseHeadersToCount);
        }

        $this->getLoop()->addPeriodicTimer($this->config->timerUI, $this->getPeriodicTimerCallback());

        // load a collection of URLs and pass it through the Transformer

        $this->getTargetManager()->pipe($this->getTransformer());
    }

    public function getPeriodicTimerCallback(): callable
    {
        return function () {
            $this->getPresenter()->renderStatistics($this->result, $this->getTargetManager()->getNumberOfUrls());

            if ($this->isCompleted()) {
                $this->logger->info('no more URLs to process: stop!');
                $this->getLoop()->stop();
            }
        };
    }

    public function run(): void
    {
        $this->getLoop()->run();
    }

    private function getPresenter(): Presenter
    {
        return $this->presenter ?: $this->presenter = $this->determinePresenter();
    }

    private function determinePresenter(): Presenter
    {
        if ($this->config->presenter instanceof Presenter) {
            return $this->config->presenter;
        }

        $presenterClass = $this->config->presenter;

        \assert(\class_exists($presenterClass), "Indicated PresenterClass '$presenterClass' does not exist.");

        return new $presenterClass($this->result);
    }

    private function getLoop(): LoopInterface
    {
        return $this->loop ?: $this->loop = EventLoopFactory::create();
    }

    private function getNumberOfRemainingUrlsToProcess(): int
    {
        return $this->getTargetManager()->getNumberOfUrls() - $this->result->countFinishedUrls();
    }

    private function getTargetManager(): TargetManager
    {
        return $this->targetManager ?: $this->targetManager = new TargetManager($this->getStream(), $this->logger);
    }

    private function getStream(): ?ReadableStreamInterface
    {
        $handle = @\fopen($this->config->targetFile, 'r');
        if (!$handle) {
            $this->logger->error(\sprintf('Could not open target file "%s"', $this->config->targetFile));

            return null;
        }

        return new ReadableResourceStream($handle, $this->getLoop());
    }

    private function isCompleted(): bool
    {
        return $this->getNumberOfRemainingUrlsToProcess() === 0;
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
        return function (string $url): PromiseInterface {
            return $this->loadUrlWithBrowser($url)
                ->then($this->getOnFulfilledCallback($url))
                ->otherwise($this->getOnRejectedCallback($url));
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
            'timeout' => $this->config->timeout,
            'followRedirects' => false, // We are using own mechanism of following redirects to correctly count these.
        ]);
    }

    private function getOnFulfilledCallback(string $url): callable
    {
        return function (ResponseInterface $response) use ($url): void {
            $this->result->countAdditionalHeaders($response->getHeaders());

            /*
            if ($this->saveEnabled) {
                $path = $this->savePath.$this->makeFilename($url);
                if (\file_put_contents($path, $response->getBody(), FILE_APPEND) === false) {
                    throw new Exception("Cannot write file: $path");
                }
            }
             */

            $this->result->addProcessedData($response->getBody()->getSize());

            $httpResponseCode = $response->getStatusCode();
            $this->result->addStatusCode($httpResponseCode);
            $this->result->done($url);

            if ($this->followRedirects && $this->isRedirectCode($httpResponseCode)) {
                $newLocation = $this->getLocationFromHeaders($response->getHeaders());
                $this->result->addRedirectedUrl($url, $newLocation);
                $this->getTargetManager()->addUrl($newLocation);

                return;
            }

            // Any 2xx code is 'success' for us, if not => failure
            if ((int) ($httpResponseCode / 100) !== 2) {
                $this->result->addBrokenUrl($url, $httpResponseCode);

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
            $this->result->done($url);
            $this->result->addBrokenUrl($url, $errorType);

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
