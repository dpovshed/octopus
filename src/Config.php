<?php

declare(strict_types=1);

namespace Octopus;

use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration object
 *
 * @package Octopus
 *
 */
class Config
{
    public const OUTPUT_MODE_COUNT = 'count';
    public const OUTPUT_MODE_SAVE = 'save';
    public const REQUEST_TYPE_GET = 'GET';
    public const REQUEST_TYPE_HEAD = 'HEAD';
    public const TARGET_TYPE_XML = 'xml';
    public const TARGET_TYPE_TXT = 'txt';

    private static $allowedOutputModes = array(
        self::OUTPUT_MODE_COUNT,
        self::OUTPUT_MODE_SAVE,
    );

    private static $allowedRequestTypes = array(
        self::REQUEST_TYPE_GET,
        self::REQUEST_TYPE_HEAD,
    );

    private static $allowedTargetTypes = array(
        self::TARGET_TYPE_XML,
        self::TARGET_TYPE_TXT,
    );

    /**
     * Percentage of re-issuing the same request after successful completion, can be used for stress-testing.
     *
     * @var int
     */
    public $bonusRespawn = 0;

    /**
     * Number of concurrent / simultaneous requests.
     *
     * Be careful selecting very large value here. A value between 10-50 usually brings enough speed and fun.
     *
     * @var int
     */
    public $concurrency = 10;

    /**
     * IP address / host of the DNS Resolver.
     *
     * One might consider to use the Google DNS available at either:
     *  - 8.8.8.8
     *  - 8.8.4.4
     *
     * @var string
     */
    public $dnsResolver = '8.8.4.4';

    /**
     * If turned on: write list of failed URLs to a file.
     *
     * @var bool
     */
    public $outputBroken = true;

    /**
     * ~/save-to-some-dir if needed.
     *
     * @var string
     */
    public $outputDestination = '/tmp';

    /**
     * Either 'save' or 'count'
     *
     * @var string
     */
    public $outputMode = self::OUTPUT_MODE_SAVE;

    /**
     * Type of the request, 'GET'/'HEAD'.
     *
     * With HEAD saving data is not possible.
     *
     * @var string
     */
    public $requestType = self::REQUEST_TYPE_HEAD;

    /**
     * Maximum delay after spawning requests in microseconds.
     *
     * @var int
     */
    public $spawnDelayMax = 0;

    /**
     * Minimum delay after spawning requests in microseconds.
     *
     * @var int
     */
    public $spawnDelayMin = 0;

    /**
     * The format of the loaded sitemap.
     *
     * Either 'xml' or 'txt'
     *
     * @var @var string
     */
    public $targetType = self::TARGET_TYPE_XML;

    /**
     * Use a local or remote target / sitemap file in either 'xml' or 'txt' format.
     *
     * @var string
     */
    public $targetFile;

    /**
     * How often to update current statistics.
     *
     * @var float
     */
    public $timerUI = 0.25;

    /**
     * How often spawn new request.
     *
     * @var float
     */
    public $timerQueue = 0.007;

    public function __construct()
    {
        assert(in_array($this->outputMode, self::$allowedOutputModes, true), 'Invalid configuration value detected: use an allowed OutputMode: ' . print_r(self::$allowedOutputModes, true));
        assert(in_array($this->requestType, self::$allowedRequestTypes, true), 'Invalid configuration value detected: use an allowed RequestType: ' . print_r(self::$allowedRequestTypes, true));
        assert(in_array($this->targetType, self::$allowedTargetTypes, true), 'Invalid configuration value detected: use an allowed TargetType: ' . print_r(self::$allowedTargetTypes, true));
        assert($this->spawnDelayMax >= $this->spawnDelayMin, 'Invalid configuration value detected: check spawn delay numbers');
        assert($this->bonusRespawn <= 99, 'Invalid configuration value detected: bonus respawn should be up to 99');

        $this->outputDestination .= DIRECTORY_SEPARATOR . time();
    }

}