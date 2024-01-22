<?php

declare(strict_types=1);

namespace Octopus;

use Octopus\Presenter\EchoPresenter;
use Symfony\Component\Console\Exception\InvalidArgumentException;

/**
 * Configuration object.
 */
class Config
{
    final public const bool FOLLOW_HTTP_REDIRECTS_DEFAULT = true;
    final public const string OUTPUT_MODE_COUNT = 'count';
    final public const string OUTPUT_MODE_SAVE = 'save';
    final public const string PRESENTER_DEFAULT = EchoPresenter::class;
    final public const string REQUEST_HEADER_USER_AGENT = 'User-Agent';
    final public const string REQUEST_HEADER_USER_AGENT_DEFAULT = 'Octopus/1.0';
    final public const string REQUEST_TYPE_GET = 'GET';
    final public const string REQUEST_TYPE_HEAD = 'HEAD';
    final public const string REQUEST_TYPE_DEFAULT = self::REQUEST_TYPE_HEAD;
    final public const string TARGET_TYPE_XML = 'xml';
    final public const string TARGET_TYPE_TXT = 'txt';
    final public const string TARGET_TYPE_DEFAULT = self::TARGET_TYPE_XML;
    final public const int CONCURRENCY_DEFAULT = 5;
    final public const float TIMEOUT_DEFAULT = 10.0;
    final public const float TIMER_UI_DEFAULT = 0.25;

    /**
     * An array of some additional response headers to count.
     *
     * @var array<int, string>
     */
    public array $additionalResponseHeadersToCount;

    /**
     * Percentage of re-issuing the same request after successful completion, can be used for stress-testing.
     */
    public int $bonusRespawn = 0;

    /**
     * Number of concurrent / simultaneous requests.
     *
     * Be careful selecting very large value here. A value between 10-50 usually brings enough speed and fun.
     */
    public int $concurrency = self::CONCURRENCY_DEFAULT;

    /**
     * In case a requested URL returns a HTTP redirection status code, should it be followed?
     */
    public bool $followRedirects = self::FOLLOW_HTTP_REDIRECTS_DEFAULT;

    /**
     * If turned on: write list of failed URLs to a file.
     */
    public bool $outputBroken = true;

    /**
     * ~/save-to-some-dir if needed.
     */
    public string $outputDestination = '/tmp';

    /**
     * Either 'save' or 'count'.
     */
    public string $outputMode = self::OUTPUT_MODE_COUNT;

    /**
     * The class or Presenter instance used to present intermediate results.
     */
    public string|Presenter $presenter = self::PRESENTER_DEFAULT;

    /**
     * The headers used in the request to fetch a URL.
     *
     * @var array<string, string>
     */
    public array $requestHeaders = [
        self::REQUEST_HEADER_USER_AGENT => self::REQUEST_HEADER_USER_AGENT_DEFAULT,
    ];

    /**
     * Type of the request, 'GET'/'HEAD'.
     *
     * With HEAD saving data is not possible.
     */
    public string $requestType = self::REQUEST_TYPE_HEAD;

    /**
     * The format of the loaded sitemap.
     *
     * Either 'xml' or 'txt'
     */
    public string $targetType = self::TARGET_TYPE_XML;

    /**
     * Use a local or remote target / sitemap file in either 'xml' or 'txt' format.
     */
    public string $targetFile;

    /**
     * Number of seconds for request timeout.
     */
    public float $timeout = self::TIMEOUT_DEFAULT;

    /**
     * How often to update current statistics in the UserInterface.
     */
    public float $timerUI = self::TIMER_UI_DEFAULT;

    /**
     * @var array<int, string>
     */
    private static array $allowedOutputModes = [
        self::OUTPUT_MODE_COUNT,
        self::OUTPUT_MODE_SAVE,
    ];

    /**
     * @var array<int, string>
     */
    private static array $allowedRequestTypes = [
        self::REQUEST_TYPE_GET,
        self::REQUEST_TYPE_HEAD,
    ];

    /**
     * @var array<int, string>
     */
    private static array $allowedTargetTypes = [
        self::TARGET_TYPE_XML,
        self::TARGET_TYPE_TXT,
    ];

    public function __construct()
    {
        $this->outputDestination .= \DIRECTORY_SEPARATOR.time();
    }

    /**
     * Validate passed parameters.
     *
     * @throws invalidArgumentException()
     */
    public function validate(): void
    {
        if (!\in_array($this->outputMode, self::$allowedOutputModes, true)) {
            throw new InvalidArgumentException('Invalid configuration value detected: use an allowed OutputMode: '.print_r(self::$allowedOutputModes, true));
        }
        if (!\in_array($this->requestType, self::$allowedRequestTypes, true)) {
            throw new InvalidArgumentException('Invalid configuration value detected: use an allowed RequestType: '.print_r(self::$allowedRequestTypes, true));
        }
        if (!\in_array($this->targetType, self::$allowedTargetTypes, true)) {
            throw new InvalidArgumentException('Invalid configuration value detected: use an allowed TargetType: '.print_r(self::$allowedTargetTypes, true));
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
