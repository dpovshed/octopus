<?php

declare(strict_types=1);

namespace Octopus;

class Result
{
    public Config $config;

    /**
     * @var array<int, string>
     */
    private array $additionalResponseHeadersToCount = [];

    /**
     * URLs that could not be loaded.
     *
     * @var array<string, int|string>
     */
    private array $brokenUrls = [];

    /**
     * @var array<int, string>
     */
    private array $finishedUrls = [];

    /**
     * URLs that were redirected to another location.
     *
     * @var array<string, string>
     */
    private array $redirectedUrls = [];

    /**
     * Timestamp to track execution time.
     */
    private readonly float $started;

    /**
     * @var array<int|string, int>
     */
    private array $statusCodes = [];

    /**
     * Total amount of processed data.
     */
    private int $totalData = 0;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->started = microtime(true);
    }

    /**
     * @param array<int, string> $additionalResponseHeadersToCount
     */
    public function setAdditionalResponseHeadersToCount(array $additionalResponseHeadersToCount): void
    {
        $this->additionalResponseHeadersToCount = $additionalResponseHeadersToCount;
    }

    public function addProcessedData(int $data): void
    {
        $this->totalData += $data;
    }

    public function addRedirectedUrl(string $url, string $newLocation): void
    {
        $this->redirectedUrls[$url] = $newLocation;
    }

    public function addBrokenUrl(string $url, int|string $statusCode): void
    {
        $this->brokenUrls[$url] = $statusCode;

        $this->addStatusCode($statusCode);
    }

    public function addStatusCode(int|string $statusCode): void
    {
        $this->bumpStatusCode($statusCode);
    }

    public function getTotalData(): int
    {
        return $this->totalData;
    }

    /**
     * @return array<string, int|string>
     */
    public function getBrokenUrls(): array
    {
        return $this->brokenUrls;
    }

    public function countFinishedUrls(): int
    {
        return \count($this->finishedUrls);
    }

    /**
     * @param array<int, array<int, string>> $headers
     */
    public function countAdditionalHeaders(array $headers): void
    {
        if (isset($this->additionalResponseHeadersToCount)) {
            foreach ($this->additionalResponseHeadersToCount as $additionalHeader) {
                if (isset($headers[$additionalHeader])) {
                    $headerLabel = sprintf('%s (%s)', $additionalHeader, $headers[$additionalHeader][0]);
                    $this->bumpStatusCode($headerLabel);
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function getRedirectedUrls(): array
    {
        return $this->redirectedUrls;
    }

    /**
     * @return array<int|string, int>
     */
    public function getStatusCodes(): array
    {
        return $this->statusCodes;
    }

    public function done(string $url): void
    {
        $this->finishedUrls[] = $url;
    }

    public function getDurationLabel(): string
    {
        return sprintf('%6.2f sec.', microtime(true) - $this->started);
    }

    /**
     * @return array<int, string>
     */
    public function getStatusCodeInformation(): array
    {
        $codeInfo = [];
        foreach ($this->getStatusCodes() as $code => $count) {
            $codeInfo[] = sprintf('%s: %d', $code, $count);
        }

        return $codeInfo;
    }

    public function getNumberOfRemainingUrlsToProcess(int $totalNumberOfUrls): int
    {
        return $totalNumberOfUrls - $this->countFinishedUrls();
    }

    public function getMemoryUsageLabel(): string
    {
        return sprintf('%5.1f MB', memory_get_usage(true) / 1048576);
    }

    private function bumpStatusCode(int|string $statusCode): void
    {
        $this->statusCodes[$statusCode] ??= 0;
        ++$this->statusCodes[$statusCode];
    }
}
