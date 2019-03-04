<?php

declare(strict_types=1);

namespace Octopus\Presenter;

use Octopus\Presenter;
use Octopus\Result;

class EchoPresenter implements Presenter
{
    public function renderStatistics(Result $result, int $totalNumberOfUrls): void
    {
        echo \sprintf(
            " %s %s Queued/running/done: %d/%s/%d. Statistics: %s \r",
            $result->getMemoryUsageLabel(),
            $result->getDurationLabel(),
            $result->getNumberOfRemainingUrlsToProcess($totalNumberOfUrls),
            $result->config->concurrency,
            $result->countFinishedUrls(),
            \implode(' ', $result->getStatusCodeInformation())
        );
    }
}
