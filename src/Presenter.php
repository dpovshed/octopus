<?php

declare(strict_types=1);

namespace Octopus;

interface Presenter
{
    public function renderStatistics(Result $result, int $totalNumberOfUrls): void;
}
