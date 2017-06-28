<?php
/**
 * @file: defining configuration storage.
 */

namespace Octopus;

class Config
{
    protected $values = array();

    public function __construct()
    {
        $this->loadDefaults();
    }

    public function loadDefaults()
    {
        if (function_exists('yaml_parse_file')) {
            $yaml = yaml_parse_file("./octopus.yml");
        } else {
            $yaml = \Travis\YAML::from_file("./octopus.yml")->to_array();
        }
        if (!$yaml) {
            throw new \Exception(error_get_last()['message']);
        }
        $this->values['targetFile'] = $yaml['target']['file'];
        $this->values['targetType'] = $yaml['target']['type'];

        $this->values['outputMode'] = $yaml['output']['mode'];
        $this->values['outputDestination'] = $yaml['output']['destination'] . '/' . time();
        $this->values['outputBroken'] = $yaml['output']['broken'];

        $this->values['spawnDelayMin'] = $yaml['delay']['min'];
        $this->values['spawnDelayMax'] = $yaml['delay']['max'];
        assert($this->values['spawnDelayMax'] >= $this->values['spawnDelayMin'],
      "Misconfigured: check spawn delay numbers");
        $this->values['dnsResolver'] = $yaml['dns_resolver'];
        $this->values['concurrency'] = $yaml['concurrency'];
        $this->values['requestType'] = $yaml['request_type'];
        $this->values['bonusRespawn'] = $yaml['bonus_respawn'];
        assert($this->values['bonusRespawn'] <= 99, "Misconfigured: bonus respawn should be up to 99");
        $this->values['timerUI'] = $yaml['timer_ui'];
        $this->values['timerQueue'] = $yaml['timer_queue'];
    }

    public function __get($key)
    {
        if (!isset($this->values[$key])) {
            throw new \Exception(__METHOD__ . ': undefined parameter ' . $key);
        }
        return $this->values[$key];
    }
}
