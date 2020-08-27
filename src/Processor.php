<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use Clue\React\Flux\Transformer;
use Exception;
use Octopus\TargetManager\TargetManagerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use React\Stream\ReadableResourceStream;
use React\Stream\ReadableStreamInterface;
use RuntimeException;
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

    public Config$config;
    public Result $result;

    private array $httpRedirectionResponseCodes = [
        Http::MOVED_PERMANENTLY,
        Http::FOUND,
        Http::SEE_OTHER,
        Http::TEMPORARY_REDIRECT,
        Http::PERMANENT_REDIRECT,
    ];

    private Browser $browser;
    private LoggerInterface $logger;
    private LoopInterface $loop;
    private TargetManager$targetManager;
    private Transformer $transformer;
    private bool $followRedirects = false;
    private Presenter $presenter;

    public function __construct(Config $config, LoggerInterface $logger = null)
    {
        $config->validate();

        $this->config = $config;
        $this->result = new Result($config);
        $this->logger = $logger ?? new NullLogger();

        if ($this->isOutputDestinationRequired()) {
            $this->touchOutputDestination();
        }

        if (\is_array($config->additionalResponseHeadersToCount)) {
            $this->result->setAdditionalResponseHeadersToCount($config->additionalResponseHeadersToCount);
        }

        $this->getLoop()->addPeriodicTimer($this->config->timerUI, $this->getPeriodicTimerCallback());

        // Instantiate the TargetManager to load a collection of URLs and pass it through the Transformer which will actually handle the URLs
        $this->getTargetManager()->pipe($this->getTransformer());
    }

    public function getPeriodicTimerCallback(): callable
    {
        return function (): void {
            if ($this->getTargetManager()->isInitialized() && $this->isCompleted()) {
                $this->logger->info('no more URLs to process: stop!');
                $this->getLoop()->stop();

                return;
            }

            $this->getPresenter()->renderStatistics($this->result, $this->getTargetManager()->getNumberOfUrls());
        };
    }

    public function run(): void
    {
        $this->getLoop()->run();
    }

    private function isOutputDestinationRequired(): bool
    {
        return $this->isSaveEnabled() || $this->config->outputBroken;
    }

    private function isSaveEnabled(): bool
    {
        return $this->config->outputMode === Config::OUTPUT_MODE_SAVE;
    }

    private function touchOutputDestination(): void
    {
        $savePath = $this->config->outputDestination.\DIRECTORY_SEPARATOR;
        if (!@\mkdir($savePath) && !\is_dir($savePath)) {
            throw new Exception('Cannot create output directory: '.$savePath);
        }
    }

    private function getLoop(): LoopInterface
    {
        return $this->loop ??= EventLoopFactory::create();
    }

    private function getTargetManager(): TargetManager
    {
        return $this->targetManager ??= TargetManagerFactory::getInstance($this->getStream(), $this->logger);
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

    private function getNumberOfRemainingUrlsToProcess(): int
    {
        return $this->getTargetManager()->getNumberOfUrls() - $this->result->countFinishedUrls();
    }

    private function getPresenter(): Presenter
    {
        return $this->presenter ??= $this->determinePresenter();
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

    private function getTransformer(): Transformer
    {
        return $this->transformer ??= $this->instantiateNewTransformer();
    }

    private function instantiateNewTransformer(): Transformer
    {
        $transformer = new Transformer($this->config->concurrency, $this->getLoadUrlUsingBrowserCallback());
        $transformer->on('data', function ($data): void {
            $this->logger->debug('Transformer received data event');
        });
        $transformer->on('end', function (): void {
            $this->logger->debug('Transformer received end event');
        });
        $transformer->on('error', function (): void {
            $this->logger->debug('Transformer received error event');
        });

        return $transformer;
    }

    private function getLoadUrlUsingBrowserCallback(): callable
    {
        return function (string $url): PromiseInterface {
            $promise = $this->loadUrlWithBrowser($url);
            $promise = $promise->then($this->getOnFulfilledCallback($url));
            if ($promise instanceof ExtendedPromiseInterface) {
                $promise = $promise->otherwise($this->getOnRejectedCallback($url));
            }

            return $promise;
        };
    }

    private function loadUrlWithBrowser(string $url): PromiseInterface
    {
        $requestType = \mb_strtolower($this->config->requestType);

        return $this->getBrowser()->$requestType($url, $this->config->requestHeaders);
    }

    private function getBrowser(): Browser
    {
        return $this->browser ??= $this->assembleBrowser();
    }

    private function assembleBrowser(): Browser
    {
        $browser = new Browser($this->getLoop());
        $browser = $browser->withTimeout($this->config->timeout);
        $browser = $browser->withFollowRedirects(false); // We are using own mechanism of following redirects to correctly count these.

        return $browser;
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

            $this->logger->error('loading URL "{url}" resulted in  error "{errorType}: {errorMessage}"', [
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

        // This could help to distinguish UnexpectedValueException, InvalidArgumentException, etc.
        if ($errorOrException instanceof RuntimeException) {
            return \get_class($errorOrException);
        }

        return self::ERROR_TYPE_GENERAL;
    }

    private function getErrorMessage($errorOrException): string
    {
        return $errorOrException instanceof Exception ? $errorOrException->getMessage() : \print_r($errorOrException, true);
    }
}
