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
    public $outputMode = 'save';

    /**
     * Type of the request, 'GET'/'HEAD'.
     *
     * With HEAD saving data is not possible.
     *
     * @var string
     */
    public $requestType = 'HEAD';

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
    public $targetType = 'xml';

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
        assert($this->spawnDelayMax >= $this->spawnDelayMin, 'Misconfigured: check spawn delay numbers');
        assert($this->bonusRespawn <= 99, 'Misconfigured: bonus respawn should be up to 99');

        $this->outputDestination .= DIRECTORY_SEPARATOR . time();
    }

}
