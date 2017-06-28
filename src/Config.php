<?php

declare(strict_types=1);

namespace Octopus;

use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * Define configuration storage.
 *
 * @package Octopus
 *
 * @property int $bonusRespawn Percentage of re-issuing the same request after successful completion, can be used for stress-testing.
 * @property int $concurrency
 * @property string $dnsResolver IP address / host of the DNS Resolver
 * @property string $outputDestination Either 'save' or 'count'
 * @property string $outputMode
 * @property bool $outputBroken
 * @property string $requestType Either 'GET' or 'HEAD'
 * @property int $spawnDelayMax
 * @property int $spawnDelayMin
 * @property string $targetType Either 'xml' or 'txt'
 */
class Config
{
    protected $values = array();

    public function __construct()
    {
        $this->loadDefaults('./octopus.yml');
    }

    private function loadDefaults(string $configurationFile): void
    {
        $yaml = $this->loadConfigurationFromYaml($configurationFile);

        $this->values['targetFile'] = $yaml['target']['file'];
        $this->values['targetType'] = $yaml['target']['type'];

        $this->values['outputMode'] = $yaml['output']['mode'];
        $this->values['outputDestination'] = $yaml['output']['destination'] . '/' . time();
        $this->values['outputBroken'] = $yaml['output']['broken'];

        $this->values['spawnDelayMin'] = $yaml['delay']['min'];
        $this->values['spawnDelayMax'] = $yaml['delay']['max'];
        assert($this->values['spawnDelayMax'] >= $this->values['spawnDelayMin'], 'Misconfigured: check spawn delay numbers');
        $this->values['dnsResolver'] = $yaml['dns_resolver'];
        $this->values['concurrency'] = $yaml['concurrency'];
        $this->values['requestType'] = $yaml['request_type'];
        $this->values['bonusRespawn'] = $yaml['bonus_respawn'];
        assert($this->values['bonusRespawn'] <= 99, 'Misconfigured: bonus respawn should be up to 99');
        $this->values['timerUI'] = $yaml['timer_ui'];
        $this->values['timerQueue'] = $yaml['timer_queue'];
    }

    private function loadConfigurationFromYaml(string $configurationFile): array
    {
        return Yaml::parse(file_get_contents($configurationFile));
    }

    public function __get($key)
    {
        if (!isset($this->values[$key])) {
            throw new Exception(__METHOD__ . ': undefined parameter ' . $key);
        }
        return $this->values[$key];
    }
}
