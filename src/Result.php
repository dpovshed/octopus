<?php

declare(strict_types=1);

namespace Octopus;

class Result
{
    /**
     * @var array
     */
    private $additionalResponseHeadersToCount = [];

    /**
     * URLs that could not be loaded.
     *
     * @var array
     */
    private $brokenUrls = [];

    /**
     * @var array
     */
    private $finishedUrls = [];

    /**
     * URLs that were redirected to another location.
     *
     * @var array
     */
    private $redirectedUrls = [];

    /**
     * @var array
     */
    private $statCodes = [];

    /**
     * Total amount of processed data.
     *
     * @var int
     */
    private $totalData = 0;

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

    public function addBrokenUrl(string $url, $statusCode): void
    {
        $this->brokenUrls[$url] = $statusCode;

        $this->addStatusCode($statusCode);
    }

    public function addStatusCode($statusCode): void
    {
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
        foreach ($this->additionalResponseHeadersToCount as $additionalHeader) {
            if (isset($headers[$additionalHeader])) {
                $headerLabel = \sprintf('%s (%s)', $additionalHeader, $headers[$additionalHeader][0]);
                $this->bumpStatusCode($headerLabel);
            }
        }
    }

    public function getRedirectedUrls(): array
    {
        return $this->redirectedUrls;
    }

    public function getStatusCodes(): array
    {
        return $this->statCodes;
    }

    public function done(string $url): void
    {
        $this->finishedUrls[] = $url;
    }

    private function bumpStatusCode($statusCode): void
    {
        $this->statCodes[$statusCode] = $this->statCodes[$statusCode] ?? 0;
        ++$this->statCodes[$statusCode];
    }
}
