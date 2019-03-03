<?php

declare(strict_types=1);

namespace Octopus\Presenter;

use Octopus\Presenter;
use Octopus\Result;

class EchoPresenter implements Presenter
{
    /**
     * @var Result
     */
    private $result;

    public function __construct(Result $result)
    {
        $this->result = $result;
    }

    public function renderStatistics(int $totalNumberOfUrls): void
    {
        echo \sprintf(
            " %s %s Queued/running/done: %d/%s/%d. Statistics: %s \r",
            $this->result->getMemoryUsageLabel(),
            $this->result->getDurationLabel(),
            $this->result->getNumberOfRemainingUrlsToProcess($totalNumberOfUrls),
            $this->result->config->concurrency,
            $this->result->countFinishedUrls(),
            \implode(' ', $this->result->getStatusCodeInformation())
        );
    }
}
