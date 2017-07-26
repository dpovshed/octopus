<?php

declare(strict_types=1);

namespace Octopus;

use Exception;
use React\Dns\Resolver\Factory as DnsResolverFactory;
use React\Dns\Resolver\Resolver as DnsResolver;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LibEventLoop;
use React\EventLoop\Timer\Timer;
use React\HttpClient\Client as HttpClient;
use React\HttpClient\Factory as HttpClientFactory;
use React\HttpClient\Request;
use React\HttpClient\Response;

/**
 * Processor core.
 *
 * @package Octopus
 */
class Processor
{
    /**
     * @var array
     */
    public $statCodes = ['failed' => 0];

    /**
     * Total amount of processed data
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
     * to use with configuration elements
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
     * @var DnsResolver
     */
    private $dnsResolver;

    /**
     * @var HttpClient $client
     */
    private $client;

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
            $this->savePath = $config->outputDestination . DIRECTORY_SEPARATOR;
            if (!@mkdir($this->savePath) && !is_dir($this->savePath)) {
                throw new Exception('Cannot create output directory: ' . $this->savePath);
            }
        }
    }

    public function timerStatistics(Timer $timer): void
    {
        $countQueue = $this->targetManager->countQueue();
        $countRunning = $this->targetManager->countRunning();
        $countFinished = $this->targetManager->countFinished();

        $codeInfo = array();
        foreach ($this->statCodes as $code => $count) {
            $codeInfo[] = sprintf('%s: %d', $code, $count);
        }

        echo sprintf(
            " %5.1fMB %6.2f sec. Queued/running/done: %d/%d/%d. Statistics: %s           \r",
            memory_get_usage(true) / 1048576,
            microtime(true) - $this->started,
            $countQueue,
            $countRunning,
            $countFinished,
            implode(' ', $codeInfo)
        );

        if (0 === ($countQueue + $countRunning)) {
            $timer->cancel();
        }
    }

    public function onData($data, Response $response): void
    {
        $this->countAdditionalHeaders($response->getHeaders());

        if ($this->saveEnabled) {
            $path = $this->savePath . self::makeFilename($response->octopusUrl, $response->octopusId);
            if (file_put_contents($path, $data, FILE_APPEND) === false) {
                throw new Exception("Cannot write file: $path");
            }
        }
        $this->totalData += strlen($data);
    }

    private function countAdditionalHeaders(array $headers): void
    {
        if (is_array($this->config->additionalResponseHeadersToCount) && count($this->config->additionalResponseHeadersToCount) > 0) {
            foreach ($this->config->additionalResponseHeadersToCount as $additionalHeader) {
                if (isset($headers[$additionalHeader])) {
                    $headerLabel = sprintf('%s (%s)', $additionalHeader, $headers[$additionalHeader]);
                    $this->statCodes[$headerLabel] = isset($this->statCodes[$headerLabel]) ? $this->statCodes[$headerLabel] + 1 : 1;
                }
            }
        }
    }

    public static function makeFilename(string $octopusUrl, int $octopusId): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '_', $octopusUrl . '_____' . $octopusId);
    }

    public function onRequestError(Exception $e, Request $request): void
    {
        $this->statCodes['failed']++;
        $this->targetManager->done($request->octopusId);
        $this->brokenUrls[$request->octopusUrl] = 'fail';

        echo $request->octopusUrl . ' request error: ' . $e->getMessage() . PHP_EOL;
    }

    public function onResponseError(Exception $e, Response $response): void
    {
        $this->statCodes['failed']++;
        $this->targetManager->done($response->octopusId);
        $this->brokenUrls[$response->octopusUrl] = 'fail';

        echo $response->octopusUrl . ' response error: ' . $e->getMessage() . PHP_EOL;
    }

    public function onEnd($data, Response $response): void
    {
        $httpResponseCode = $response->getCode();
        $this->statCodes[$httpResponseCode] = isset($this->statCodes[$httpResponseCode]) ? $this->statCodes[$httpResponseCode] + 1 : 1;
        $this->targetManager->done($response->octopusId);
        if (in_array($httpResponseCode, $this->httpRedirectionResponseCodes, true)) {
            $headers = $response->getHeaders();
            $this->targetManager->add($headers['Location']);
            return;
        }

        // Any 2xx code is 'success' for us, if not => failure
        if ((int)($httpResponseCode / 100) !== 2) {
            $this->brokenUrls[$response->octopusUrl] = $httpResponseCode;
            return;
        }

        if (random_int(0, 100) < $this->config->bonusRespawn) {
            $this->targetManager->add($response->octopusUrl);
        }
    }

    public function warmUp(): void
    {
        $this->loop = EventLoopFactory::create();

        $dnsResolverFactory = new DnsResolverFactory();
        $this->dnsResolver = $dnsResolverFactory->createCached($this->config->dnsResolver, $this->loop);

        $factory = new HttpClientFactory();
        $this->client = $factory->create($this->loop, $this->dnsResolver);
    }

    public function run(): void
    {
        $this->loop->addPeriodicTimer($this->config->timerUI, array($this, 'timerStatistics'));
        $this->loop->addPeriodicTimer($this->config->timerQueue, function (Timer $timer) {
            if ($this->targetManager->hasFreeSlots()) {
                $this->spawnBundle();
            } elseif ($this->targetManager->noMoreUrlsToProcess()) {
                $timer->cancel();
            }
        });

        $this->started = microtime(true);
        $this->loop->run();
    }

    public function spawnBundle(): void
    {
        for ($i = $this->targetManager->getFreeSlots(); $i > 0; $i--) {
            //list($id, $url) = $this->targets->launchAny(); //TODO make configurable to either launch the next, or a random URL
            list($id, $url) = $this->targetManager->launchNext();
            $this->spawn($id, $url);
        }
    }

    public function spawn(int $id, string $url): void
    {
        $request = $this->client->request($this->config->requestType, $url, $this->config->requestHeaders);
        $request->octopusUrl = $url;
        $request->octopusId = $id;
        $request->on('response', function (Response $response, Request $request) {
            $response->octopusUrl = $request->octopusUrl;
            $response->octopusId = $request->octopusId;
            $response->on('data', array($this, 'onData'));
            $response->on('end', array($this, 'onEnd'));
            $response->on('error', array($this, 'onResponseError'));
        });
        $request->on('error', array($this, 'onRequestError'));

        try {
            $request->end();
        } catch (Exception $e) {
            echo 'Problem of sending request: ' . $e->getMessage() . PHP_EOL;
        }

        if ($this->config->spawnDelayMax) {
            usleep(random_int($this->config->spawnDelayMin, $this->config->spawnDelayMax));
        }

        $this->requests[$id] = $request;
    }
}
