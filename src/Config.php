<?php

declare(strict_types=1);

namespace Octopus;

use Symfony\Component\Console\Exception\InvalidArgumentException;

/**
 * Configuration object.
 */
class Config
{
    /**
     * @var bool
     */
    public const FOLLOW_HTTP_REDIRECTS_DEFAULT = true;

    /**
     * @var string
     */
    public const OUTPUT_MODE_COUNT = 'count';

    /**
     * @var string
     */
    public const OUTPUT_MODE_SAVE = 'save';

    /**
     * @var string
     */
    public const REQUEST_HEADER_USER_AGENT = 'User-Agent';

    /**
     * @var string
     */
    public const REQUEST_HEADER_USER_AGENT_DEFAULT = 'Octopus/1.0';

    /**
     * @var string
     */
    public const REQUEST_TYPE_GET = 'GET';

    /**
     * @var string
     */
    public const REQUEST_TYPE_HEAD = 'HEAD';

    /**
     * @var string
     */
    public const REQUEST_TYPE_DEFAULT = self::REQUEST_TYPE_HEAD;

    /**
     * @var string
     */
    public const TARGET_TYPE_XML = 'xml';

    /**
     * @var string
     */
    public const TARGET_TYPE_TXT = 'txt';

    /**
     * @var string
     */
    public const TARGET_TYPE_DEFAULT = self::TARGET_TYPE_XML;

    /**
     * @var int
     */
    public const CONCURRENCY_DEFAULT = 5;

    /**
     * @var float
     */
    public const TIMEOUT_DEFAULT = 10.0;

    /**
     * @var float
     */
    public const TIMER_UI_DEFAULT = 0.25;

    /**
     * An array of some additional response headers to count.
     *
     * @var array
     */
    public $additionalResponseHeadersToCount;

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
    public $concurrency = self::CONCURRENCY_DEFAULT;

    /**
     * In case a requested URL returns a HTTP redirection status code, should it be followed?
     *
     * @var bool
     */
    public $followRedirects = self::FOLLOW_HTTP_REDIRECTS_DEFAULT;

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
     * Either 'save' or 'count'.
     *
     * @var string
     */
    public $outputMode = self::OUTPUT_MODE_COUNT;

    /**
     * The headers used in the request to fetch a URL.
     *
     * @var array
     */
    public $requestHeaders = [
        self::REQUEST_HEADER_USER_AGENT => self::REQUEST_HEADER_USER_AGENT_DEFAULT,
    ];

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
     * @var string
     */
    public $targetType = self::TARGET_TYPE_XML;

    /**
     * Use a local or remote target / sitemap file in either 'xml' or 'txt' format.
     *
     * @var string
     */
    public $targetFile;

    /**
     * Number of seconds for request timeout.
     *
     * @var float
     */
    public $timeout = self::TIMEOUT_DEFAULT;

    /**
     * How often to update current statistics.
     *
     * @var float
     */
    public $timerUI = self::TIMER_UI_DEFAULT;

    /**
     * How often spawn new request.
     *
     * @var float
     */
    public $timerQueue = 0.007;

    private static $allowedOutputModes = [
        self::OUTPUT_MODE_COUNT,
        self::OUTPUT_MODE_SAVE,
    ];

    private static $allowedRequestTypes = [
        self::REQUEST_TYPE_GET,
        self::REQUEST_TYPE_HEAD,
    ];

    private static $allowedTargetTypes = [
        self::TARGET_TYPE_XML,
        self::TARGET_TYPE_TXT,
    ];

    public function __construct()
    {
        $this->outputDestination .= DIRECTORY_SEPARATOR.\time();
    }

    /**
     * Validate passed parameters.
     *
     * @throws invalidArgumentException()
     */
    public function validate(): void
    {
        if (!\in_array($this->outputMode, self::$allowedOutputModes, true)) {
            throw new InvalidArgumentException('Invalid configuration value detected: use an allowed OutputMode: '.\print_r(self::$allowedOutputModes, true));
        }
        if (!\in_array($this->requestType, self::$allowedRequestTypes, true)) {
            throw new InvalidArgumentException('Invalid configuration value detected: use an allowed RequestType: '.\print_r(self::$allowedRequestTypes, true));
        }
        if (!\in_array($this->targetType, self::$allowedTargetTypes, true)) {
            throw new InvalidArgumentException('Invalid configuration value detected: use an allowed TargetType: '.\print_r(self::$allowedTargetTypes, true));
        }
        if ($this->spawnDelayMax < $this->spawnDelayMin) {
            throw new InvalidArgumentException('Invalid configuration value detected: check spawn delay numbers');
        }
        if ($this->bonusRespawn > 99) {
            throw new InvalidArgumentException('Invalid configuration value detected: bonus respawn should be up to 99');
        }
        if ($this->concurrency < 1) {
            throw new InvalidArgumentException('Invalid concurrency value');
        }
        if ($this->timeout < 0.5) {
            throw new InvalidArgumentException('Per request timeout is too low');
        }
    }
}
