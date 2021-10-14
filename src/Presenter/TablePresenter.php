<?php

declare(strict_types=1);

namespace Octopus\Presenter;

use Octopus\Presenter;
use Octopus\Result;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

class TablePresenter implements Presenter
{
    private ConsoleOutputInterface $output;

    /**
     * @var Table[]
     */
    private array $tables = [];

    public function __construct(ConsoleOutputInterface $output)
    {
        $this->output = $output;
    }

    public function renderStatistics(Result $result, int $totalNumberOfUrls): void
    {
        $status = [
            'Memory' => sprintf('%5.1fMB', memory_get_usage(true) / 1048576),
            'Time' => $result->getDurationLabel(),
            'Queued' => $result->getNumberOfRemainingUrlsToProcess($totalNumberOfUrls),
            'Running' => $result->config->concurrency,
            'Done' => $result->countFinishedUrls(),
        ];

        $rows = $status + $result->getStatusCodes();
        $tableHeaders = array_keys($rows);

        $tableKeyForHeaders = md5(implode(', ', $tableHeaders));

        $table = $this->tables[$tableKeyForHeaders] ?? $this->tables[$tableKeyForHeaders] = $this->getTable($tableHeaders);

        $table->appendRow($rows);
    }

    /**
     * @param array<int, int|string> $tableHeaders
     */
    private function getTable(array $tableHeaders): Table
    {
        $table = new Table($this->output->section());
        $table->setHeaders($tableHeaders);
        $table->render(); //Render once, then append rows to gradually populate the table

        return $table;
    }
}
