<?php

declare(strict_types=1);

namespace Octopus;

interface Presenter
{
    public function __construct(Result $result);

    public function renderStatistics(int $totalNumberOfUrls): void;
}
