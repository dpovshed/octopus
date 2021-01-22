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
    private float $started;

    private array $statusCodes = [];

    /**
     * Total amount of processed data.
     */
    private int $totalData = 0;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->started = \microtime(true);
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

    /**
     * @param int|string $statusCode
     */
    public function addBrokenUrl(string $url, $statusCode): void
    {
        \assert(\is_int($statusCode) || \is_string($statusCode));

        $this->brokenUrls[$url] = $statusCode;

        $this->addStatusCode($statusCode);
    }

    /**
     * @param int|string $statusCode
     */
    public function addStatusCode($statusCode): void
    {
        \assert(\is_int($statusCode) || \is_string($statusCode));

        $this->bumpStatusCode($statusCode);
    }

    public function getTotalData(): int
    {
        return $this->totalData;
    }

    public function getBrokenUrls(): array
    {
        return $this->brokenUrls;
    }

    public function countFinishedUrls(): int
    {
        return \count($this->finishedUrls);
    }

    public function countAdditionalHeaders(array $headers): void
    {
        if (\is_array($this->additionalResponseHeadersToCount) && \count($this->additionalResponseHeadersToCount) > 0) {
            foreach ($this->additionalResponseHeadersToCount as $additionalHeader) {
                if (isset($headers[$additionalHeader])) {
                    $headerLabel = \sprintf('%s (%s)', $additionalHeader, $headers[$additionalHeader][0]);
                    $this->bumpStatusCode($headerLabel);
                }
            }
        }
    }

    public function getRedirectedUrls(): array
    {
        return $this->redirectedUrls;
    }

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
        return \sprintf('%6.2f sec.', \microtime(true) - $this->started);
    }

    public function getStatusCodeInformation(): array
    {
        $codeInfo = [];
        foreach ($this->getStatusCodes() as $code => $count) {
            $codeInfo[] = \sprintf('%s: %d', $code, $count);
        }

        return $codeInfo;
    }

    public function getNumberOfRemainingUrlsToProcess(int $totalNumberOfUrls): int
    {
        return $totalNumberOfUrls - $this->countFinishedUrls();
    }

    public function getMemoryUsageLabel(): string
    {
        return \sprintf('%5.1f MB', \memory_get_usage(true) / 1048576);
    }

    /**
     * @param int|string $statusCode
     */
    private function bumpStatusCode($statusCode): void
    {
        $this->statusCodes[$statusCode] = $this->statusCodes[$statusCode] ?? 0;
        ++$this->statusCodes[$statusCode];
    }
}
