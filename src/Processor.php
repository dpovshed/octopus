<?php

declare(strict_types=1);

namespace Octopus;

use Clue\React\Flux\Transformer;
use Exception;
use Octopus\Http\StatusCodes;
use Octopus\TargetManager\StreamTargetManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Filesystem\Filesystem;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;

/**
 * Processor core.
 */
class Processor
{
    /**
     * Used for indicating a general error when no more details can be provided.
     */
    private const string ERROR_TYPE_GENERAL = 'failure';
    private const string ERROR_TYPE_TIMEOUT = 'timeout';

    public Config $config;
    public Result $result;

    /**
     * @var array<int, int>
     */
    private array $httpRedirectionResponseCodes = [
        StatusCodes::MOVED_PERMANENTLY->value,
        StatusCodes::FOUND->value,
        StatusCodes::SEE_OTHER->value,
        StatusCodes::TEMPORARY_REDIRECT->value,
        StatusCodes::PERMANENT_REDIRECT->value,
    ];

    private Browser $browser;
    private readonly LoggerInterface $logger;
    private StreamTargetManager $targetManager;
    private Transformer $transformer;
    private bool $followRedirects = false;
    private Presenter $presenter;

    /**
     * Use a local instance of the Loop.
     *
     * Note that the singleton Loop::get() should not be used, since multiple Processors might be in use during batch
     * processing and would then use the same loop. After a Processor instance is done, the loop is stopped.
     */
    private LoopInterface $loop;

    public function __construct(Config $config, LoggerInterface $logger = null)
    {
        $config->validate();

        $this->config = $config;
        $this->result = new Result($config);
        $this->logger = $logger ?? new NullLogger();

        if ($this->isOutputDestinationRequired()) {
            $this->touchOutputDestination();
        }

        if (isset($config->additionalResponseHeadersToCount)) {
            $this->result->setAdditionalResponseHeadersToCount($config->additionalResponseHeadersToCount);
        }

        $this->getLoop()->addPeriodicTimer($this->config->timerUI, $this->getPeriodicTimerCallback());

        // Instantiate the TargetManager to load a collection of URLs and pass it through the Transformer which will actually handle the URLs
        $this->getTargetManager()->pipe($this->getTransformer());
    }

    public function getPeriodicTimerCallback(): callable
    {
        return function (): void {
            if ($this->getTargetManager()->isClosed()) {
                $this->logger->warning('TargetManager is closed: stop!');
                $this->getLoop()->stop();

                return;
            }
            if ($this->getTargetManager()->isInitialized()) {
                $this->logger->debug('TargetManager is initialized...');
                if ($this->isCompleted()) {
                    $this->logger->info('no more URLs to process: stop!');
                    $this->getLoop()->stop();

                    return;
                }
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
        if (!@mkdir($savePath) && !is_dir($savePath)) {
            throw new \Exception('Cannot create output directory: '.$savePath);
        }
    }

    private function getLoop(): LoopInterface
    {
        return $this->loop ??= LoopFactory::create();
    }

    private function getTargetManager(): StreamTargetManager
    {
        return $this->targetManager ??= new StreamTargetManager($this->getStream(), $this->logger);
    }

    private function getStream(): PromiseInterface
    {
        return filter_var($this->config->targetFile, \FILTER_VALIDATE_URL)
            ? $this->getStreamForUrl($this->config->targetFile)
            : $this->getStreamForLocalFile($this->config->targetFile);
    }

    private function getStreamForUrl(string $url): PromiseInterface
    {
        $this->logger->info('acquire stream for URL "{url}"', ['url' => $url]);
        $browser = new Browser($this->getLoop());

        return $browser->requestStreaming('GET', $url);
    }

    private function getStreamForLocalFile(string $filename): PromiseInterface
    {
        $this->logger->info('acquire stream for local file "{filename}"', ['filename' => $filename]);

        $filesystem = Filesystem::create($this->getLoop());
        $file = $filesystem->file($filename);

        return $file->getContents();
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

        \assert(class_exists($presenterClass), "Indicated PresenterClass '$presenterClass' does not exist.");

        $presenter = new $presenterClass($this->result);

        \assert($presenter instanceof Presenter);

        return $presenter;
    }

    private function getTransformer(): Transformer
    {
        return $this->transformer ??= $this->instantiateNewTransformer();
    }

    private function instantiateNewTransformer(): Transformer
    {
        $this->logger->debug('instantiate new Transformer to process received data');

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
        $requestType = mb_strtolower($this->config->requestType);

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
        $browser = $browser->withRejectErrorResponse(true);

        return $browser->withFollowRedirects(false); // We are using own mechanism of following redirects to correctly count these.
    }

    private function getOnFulfilledCallback(string $url): callable
    {
        return function (ResponseInterface $response) use ($url): void {
            $this->logger->debug('loading URL "{url}" resulted in headers: "{headers}"', ['url' => $url, 'headers' => var_export($response->getHeaders(), true)]);
            $this->result->countAdditionalHeaders($response->getHeaders());

            /*
            if ($this->saveEnabled) {
            $path = $this->savePath.$this->makeFilename($url);
            if (\file_put_contents($path, $response->getBody(), FILE_APPEND) === false) {
            throw new Exception("Cannot write file: $path");
            }
            }
             */

            $size = $response->getBody()->getSize();
            if (\is_int($size)) {
                $this->result->addProcessedData($size);
            }

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

    /**
     * @param string[][] $headers
     */
    private function getLocationFromHeaders(array $headers): string
    {
        return $headers['Location'][0];
    }

    /**
     * Promise v1 and v2 reject with an array of Exceptions here, Promise v3 will use an Exception object instead.
     */
    private function getOnRejectedCallback(string $url): callable
    {
        return function (array|\Exception $errorOrException) use ($url): void {
            $errorType = $this->getErrorType($errorOrException);
            $this->result->done($url);
            $this->result->addBrokenUrl($url, $errorType);

            $this->logger->error('loading URL "{url}" resulted in error "{errorType}: {errorMessage}"', [
                'url' => $url,
                'errorType' => $errorType,
                'errorMessage' => $this->getErrorMessage($errorOrException),
            ]);
        };
    }

    /**
     * @param array<int, string>|\Exception $errorOrException
     */
    private function getErrorType(array|\Exception $errorOrException): string
    {
        if ($errorOrException instanceof TimeoutException) {
            return self::ERROR_TYPE_TIMEOUT;
        }

        if ($errorOrException instanceof ResponseException && $errorOrException->getCode() >= 300) {
            return (string) $errorOrException->getCode(); // Regular HTTP error code.
        }

        // This could help to distinguish UnexpectedValueException, InvalidArgumentException, etc.
        if ($errorOrException instanceof \RuntimeException) {
            return $errorOrException::class;
        }

        return self::ERROR_TYPE_GENERAL;
    }

    /**
     * @param array<int, string>|\Exception $errorOrException
     */
    private function getErrorMessage(array|\Exception $errorOrException): string
    {
        return $errorOrException instanceof \Exception ? $errorOrException->getMessage() : print_r($errorOrException, true);
    }
}
