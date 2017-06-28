<?php
/**
 * @file: Define all aspects of managing list and states of target URLs.
 */

namespace Octopus;

class TargetManager
{
    // Number of simultaneously running tasks.
  protected $concurrency;
    protected $queuedUrls = [];
    protected $runningUrls = [];
    protected $finishedUrls = [];
    protected $config;

    public function __construct(Config $config, Result $result)
    {
        $this->config = $config;
        $this->concurrency = $config->concurrency;
    }

    public function populate()
    {
        echo "Loading destination URLs..." . PHP_EOL;
        if (!($data = @file_get_contents($this->config->targetFile))) {
            throw new \Exception(error_get_last()['message']);
        }
        switch ($this->config->targetType) {
      case 'xml':
        $mask = "/\<loc\>(.+)\<\/loc\>/miU";
        break;
      case 'txt':
        $mask = "/^\s*((?U).+)\s*$/mi";
        break;
      default:
        throw new \Exception("Unsupported file type: {$this->config->targetType}");
    }
        $matches = [];
        if (!preg_match_all($mask, $data, $matches)) {
            throw new \Exception("No URL entries found");
        }
        $this->queuedUrls = $matches[1];
        echo count($this->queuedUrls) . " target URLs set" . PHP_EOL;
    }

    public function add($url)
    {
        $this->queuedUrls[] = $url;
        return max(array_keys($this->queuedUrls));
    }

    public function done($id)
    {
        $this->finishedUrls[$id] = $this->runningUrls[$id];
        unset($this->runningUrls[$id]);
    }

    public function retry($id)
    {
        $this->queuedUrls[] = $this->finishedUrls[$id];
        return max(array_keys($this->queuedUrls));
    }

  /**
   * This one normally used for relaunching.
   * @param $id
   */
  public function launch($id)
  {
      $this->runningUrls[$id] = $this->queuedUrls[$id];
      unset($this->queuedUrls[$id]);
  }

    public function launchNext()
    {
        assert($this->queuedUrls, "Cannot launch, nothing in queue!");
        list($id, $url) = each($this->queuedUrls);
        $this->launch($id);
        return [$id, $url];
    }

    public function launchAny()
    {
        assert($this->queuedUrls, "Cannot launch, nothing in queue!");
        $id = array_rand($this->queuedUrls);
        $url = $this->queuedUrls[$id];
        $this->launch($id);
        return [$id, $url];
    }

    public function countQueue()
    {
        return count($this->queuedUrls);
    }

    public function countRunning()
    {
        return count($this->runningUrls);
    }

    public function countFinished()
    {
        return count($this->finishedUrls);
    }

    public function getFreeSlots()
    {
        return min($this->concurrency - $this->countRunning(), $this->countQueue());
    }
}
