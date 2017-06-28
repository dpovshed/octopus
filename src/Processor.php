<?php

declare(strict_types=1);

namespace Octopus;

use React\Dns\Resolver\Factory as DnsResolverFactory;
use React\Dns\Resolver\Resolver as DnsResolver;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LibEventLoop;
use React\HttpClient\Client as HttpClient;
use React\HttpClient\Factory as HttpClientFactory;
use React\HttpClient\Request;
use React\HttpClient\Response;

use Exception;

/**
 * Processor core.
 *
 * @package Octopus
 */
class Processor
{
    public static $statCodes = ['failed' => 0];

    /**
     * Redirects which we able too process.
     */
    public static $totalData = 0;

    /**
     * Currently running requests.
     *
     * @todo: probably move to TargetManager
     *
     * @var array
     */
    public static $brokenUrls = [];

    /**
     * @var TargetManager $targets
     */
    private static $targets;

    /**
     * @var array
     */
    private static $redirects = [301, 302, 303, 307, 308];

    /**
     * @var bool
     */
    private static $saveEnabled;

    /**
     * to use with configuration elements
     */
    private static $savePath;

    /**
     * Just to track execution time.
     *
     * @var Config
     */
    private static $config;

    /**
     * Timestamp when we started processing.
     *
     * @var float
     */
    private static $started;

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
        self::$targets = $targets;
        self::$config = $config;
        self::$saveEnabled = $config->outputMode === 'save';
        if (self::$saveEnabled || $config->outputBroken) {
            self::$savePath = $config->outputDestination . DIRECTORY_SEPARATOR;
            if (!@mkdir(self::$savePath) && !is_dir(self::$savePath)) {
                throw new Exception('Cannot create output directory: ' . self::$savePath);
            }
        }
    }

    public static function timerStat($timer): void
    {
        $countQueue = self::$targets->countQueue();
        $countRunning = self::$targets->countRunning();
        $countFinished = self::$targets->countFinished();

        $codeInfo = array();
        foreach (self::$statCodes as $code => $count) {
            $codeInfo[] = sprintf('%s: %d', $code, $count);
        }

        echo sprintf(" %5.1fMB %6.2f sec. Queued/running/done: %d/%d/%d. Stats: %s           \r",
            memory_get_usage(true) / 1048576,
            microtime(true) - self::$started,
            $countQueue,
            $countRunning,
            $countFinished,
            implode(' ', $codeInfo)
        );

        if (0 === ($countQueue + $countRunning)) {
            $timer->cancel();
        }
    }

    public static function onData($data, Response $response): void
    {
       self::countAdditionalHeaders($response->getHeaders());

        if (self::$saveEnabled) {
            $path = self::$savePath . self::makeFilename($response->octopusUrl, $response->octopusId);
            if (file_put_contents($path, $data, FILE_APPEND) === false) {
                throw new Exception("Cannot write file: $path");
            }
        }
        self::$totalData += strlen($data);
    }

    private static function countAdditionalHeaders(array $headers): void
    {
        $consideredHeaders = array(
            'CF-Cache-Status',
        );

        foreach($consideredHeaders as $consideredHeader){
            if (isset($headers[$consideredHeader])) {
                $headerLabel = sprintf('%s (%s)', $consideredHeader, $headers[$consideredHeader]);
                self::$statCodes[$headerLabel] = isset(self::$statCodes[$headerLabel]) ? self::$statCodes[$headerLabel] + 1 : 1;
            }
        }

    }

    public static function makeFilename(string $octopusUrl, int $octopusId): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '_', $octopusUrl . '_____' . $octopusId);
    }

    public static function onRequestError(Exception $e, Request $request): void
    {
        self::$statCodes['failed']++;
        self::$targets->done($request->octopusId);
        self::$brokenUrls[$request->octopusUrl] = 'fail';

        echo $request->octopusUrl . ' request error: ' . $e->getMessage() . PHP_EOL;
    }

    public static function onResponseError(Exception $e, Response $response): void
    {
        self::$statCodes['failed']++;
        self::$targets->done($response->octopusId);
        self::$brokenUrls[$response->octopusUrl] = 'fail';

        echo $response->octopusUrl . ' response error: ' . $e->getMessage() . PHP_EOL;
    }

    public static function onEnd($data, Response $response): void
    {
        $doBonus = random_int(0, 100) < self::$config->bonusRespawn;
        $code = $response->getCode();
        self::$statCodes[$code] = isset(self::$statCodes[$code]) ? self::$statCodes[$code] + 1 : 1;
        self::$targets->done($response->octopusId);
        if (in_array($code, self::$redirects, true)) {
            $headers = $response->getHeaders();
            self::$targets->add($headers['Location']);
        } // Any 2xx code is 'success' for us.
        elseif ((int)($code / 100) !== 2) {
            self::$brokenUrls[$response->octopusUrl] = $code;
        } elseif ($doBonus) {
            self::$targets->add($response->octopusUrl);
        }
    }

    public function warmUp(): void
    {
        $this->loop = EventLoopFactory::create();

        $dnsResolverFactory = new DnsResolverFactory();
        $this->dnsResolver = $dnsResolverFactory->createCached(self::$config->dnsResolver, $this->loop);
        $factory = new HttpClientFactory();
        $this->client = $factory->create($this->loop, $this->dnsResolver);
    }

    public function run(): void
    {
        $processor = $this;
        $this->loop->addPeriodicTimer(self::$config->timerUI, '\Octopus\Processor::timerStat');
        $this->loop->addPeriodicTimer(self::$config->timerQueue, function ($timer) use ($processor) {
            if (self::$targets->getFreeSlots()) {
                $processor->spawnBundle();
            } elseif (0 === (self::$targets->countQueue() + self::$targets->countRunning())) {
                $timer->cancel();
            }
        });
        self::$started = microtime(true);
        $this->loop->run();
    }

    public function spawnBundle(): void
    {
        for ($i = self::$targets->getFreeSlots(); $i > 0; $i--) {
            list($id, $url) = self::$targets->launchAny();
            $this->spawn($id, $url);
        }
    }

    public function spawn($id, $url): void
    {
        $requestType = self::$config->requestType;
        $headers = [
            //'User-Agent' => 'Octopus/1.0',
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ];
        $request = $this->client->request($requestType, $url, $headers);
        $request->octopusUrl = $url;
        $request->octopusId = $id;
        $request->on('response', function (Response $response, Request $req) {
            $response->octopusUrl = $req->octopusUrl;
            $response->octopusId = $req->octopusId;
            $response->on('data', 'Octopus\\Processor::onData');
            $response->on('end', 'Octopus\\Processor::onEnd');
            $response->on('error', 'Octopus\\Processor::onResponseError');
        });
        $request->on('error', 'Octopus\\Processor::onRequestError');
        try {
            $request->end();
        } catch (Exception $e) {
            echo 'Problem of sending request: ' . $e->getMessage() . PHP_EOL;
        }

        if (self::$config->spawnDelayMax) {
            usleep(random_int(self::$config->spawnDelayMin, self::$config->spawnDelayMax));
        }
        $this->requests[$id] = $request;
    }
}
