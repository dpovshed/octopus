<?php

declare(strict_types=1);

namespace Octopus;

use Exception;

/**
 * Define all aspects of managing list and states of target URLs.
 *
 * @package Octopus
 */
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

    public function populate(): void
    {
        echo 'Loading destination URLs from ' . $this->config->targetFile . PHP_EOL;
        if (!($data = @file_get_contents($this->config->targetFile))) {
            throw new Exception(error_get_last()['message']);
        }
        switch ($this->config->targetType) {
            case 'xml':
                $mask = "/\<loc\>(.+)\<\/loc\>/miU";
                break;
            case 'txt':
                $mask = "/^\s*((?U).+)\s*$/mi";
                break;
            default:
                throw new Exception("Unsupported file type: {$this->config->targetType}");
        }
        $matches = [];
        if (!preg_match_all($mask, $data, $matches)) {
            throw new Exception('No URL entries found');
        }
        $this->queuedUrls = $matches[1];
        echo count($this->queuedUrls) . ' target URLs set' . PHP_EOL;
    }

    public function add($url): int
    {
        $this->queuedUrls[] = $url;

        return max(array_keys($this->queuedUrls));
    }

    public function done($id): void
    {
        $this->finishedUrls[$id] = $this->runningUrls[$id];
        unset($this->runningUrls[$id]);
    }

    public function retry($id): int
    {
        $this->queuedUrls[] = $this->finishedUrls[$id];
        return max(array_keys($this->queuedUrls));
    }

    public function launchNext(): array
    {
        assert($this->queuedUrls, "Cannot launch, nothing in queue!");
        list($id, $url) = each($this->queuedUrls);
        $this->launch($id);
        return [$id, $url];
    }

    /**
     * This one normally used for relaunching.
     *
     * @param $id
     *
     * @return void
     */
    public function launch($id): void
    {
        $this->runningUrls[$id] = $this->queuedUrls[$id];
        unset($this->queuedUrls[$id]);
    }

    public function launchAny(): array
    {
        assert($this->queuedUrls, "Cannot launch, nothing in queue!");
        $id = array_rand($this->queuedUrls);
        $url = $this->queuedUrls[$id];
        $this->launch($id);
        return [$id, $url];
    }

    public function countFinished(): int
    {
        return count($this->finishedUrls);
    }

    public function getFreeSlots(): int
    {
        return min($this->concurrency - $this->countRunning(), $this->countQueue());
    }

    public function countRunning(): int
    {
        return count($this->runningUrls);
    }

    public function countQueue(): int
    {
        return count($this->queuedUrls);
    }
}
