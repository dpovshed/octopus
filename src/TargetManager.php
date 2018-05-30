<?php

declare(strict_types=1);

namespace Octopus;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleXMLElement;

/**
 * Define all aspects of managing list and states of target URLs.
 */
class TargetManager
{
    /**
     * @see https://www.sitemaps.org/protocol.html
     *
     * @var string
     */
    private const XML_SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * @see https://www.sitemaps.org/protocol.html#index
     *
     * @var string
     */
    private const XML_SITEMAP_INDEX_ROOT_ELEMENT = 'sitemapindex';

    /**
     * @see https://www.sitemaps.org/protocol.html#index
     *
     * @var string
     */
    private const XML_SITEMAP_ROOT_ELEMENT = 'sitemap';

    /**
     * @var array
     */
    private $finishedUrls = [];

    /**
     * @var array
     */
    private $queuedUrls = [];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Config $config, LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getQueuedUrls(): array
    {
        return $this->queuedUrls;
    }

    public function populate(): int
    {
        if (!($data = @\file_get_contents($this->config->targetFile))) {
            $lastErrorMessage = \error_get_last()['message'];
            $this->logger->critical('Failed loading {targetFile}, last error message: {lastErrorMessage}', ['targetFile' => $this->config->targetFile, 'lastErrorMessage' => $lastErrorMessage ?? 'n/a']);

            throw new Exception($lastErrorMessage ?? 'Failed loading '.$this->config->targetFile);
        }

        switch ($this->config->targetType) {
            case 'xml':
                $xmlElement = new SimpleXMLElement($data);
                if ($this->isXmlSitemapIndex($xmlElement)) {
                    $this->processSitemapIndex($xmlElement);

                    return \count($this->queuedUrls);
                }
                if ($this->isXmlSitemap($xmlElement)) {
                    $this->processSitemapElement($xmlElement);

                    return \count($this->queuedUrls);
                }

                $mask = "/\<loc\>(.+)\<\/loc\>/miU";
                break;
            case 'txt':
                $mask = "/^\s*((?U).+)\s*$/mi";
                break;
            default:
                throw new Exception('Unsupported file type: '.$this->config->targetType);
        }
        $matches = [];
        if (!\preg_match_all($mask, $data, $matches)) {
            throw new Exception('No URL entries found');
        }
        $this->queuedUrls = $matches[1];

        return \count($this->queuedUrls);
    }

    public function add(string $url): void
    {
        $this->queuedUrls[] = $url;
    }

    public function done(int $id, string $url): void
    {
        $this->finishedUrls[$id] = $url;
    }

    public function countFinished(): int
    {
        return \count($this->finishedUrls);
    }

    private function isXmlSitemap(SimpleXMLElement $xmlElement): bool
    {
        $xmlRootElement = $xmlElement->getName();

        return $xmlRootElement === self::XML_SITEMAP_ROOT_ELEMENT;
    }

    private function isXmlSitemapIndex(SimpleXMLElement $xmlElement): bool
    {
        $xmlRootElement = $xmlElement->getName();

        return $xmlRootElement === self::XML_SITEMAP_INDEX_ROOT_ELEMENT;
    }

    private function processSitemapIndex(SimpleXMLElement $sitemapIndexElement): void
    {
        if ($sitemapLocationElements = $this->getSitemapLocationElements($sitemapIndexElement)) {
            foreach ($sitemapLocationElements as $sitemapLocationElement) {
                $sitemapUrl = (string) $sitemapLocationElement;
                $this->processSitemapUrl($sitemapUrl);
            }
        }
    }

    private function processSitemapUrl(string $sitemapUrl): void
    {
        if ($data = $this->loadData($sitemapUrl)) {
            try {
                $sitemapElement = @new SimpleXMLElement($data);
                $this->processSitemapElement($sitemapElement);
            } catch (Exception $exception) {
                $this->logger->critical('Caught exception while processing XML Sitemap {sitemapUrl}: {exceptionMessage}', ['sitemapUrl' => $sitemapUrl, 'exceptionMessage' => $exception->getMessage()]);
            }
        }
    }

    private function loadData(string $file): ?string
    {
        if ($data = @\file_get_contents($file)) {
            $this->logger->debug('Loaded data from {file}, data length {dataLength}', ['file' => $file, 'dataLength' => \mb_strlen($data)]);

            return $data;
        }

        $this->logger->critical('Failed loading data from {file}, last error messages: {lastErrorMessages}', ['file' => $file, 'lastErrorMessages' => \print_r(\error_get_last(), true)]);

        return null;
    }

    private function processSitemapElement(SimpleXMLElement $sitemapElement): void
    {
        if ($sitemapLocationElements = $this->getSitemapLocationElements($sitemapElement)) {
            foreach ($sitemapLocationElements as $sitemapLocationElement) {
                $sitemapUrl = (string) $sitemapLocationElement;
                $this->queuedUrls[] = $sitemapUrl;

                $this->logger->debug('Queued URL {queueLength}: {url}', ['queueLength' => \count($this->queuedUrls), 'url' => $sitemapUrl]);
            }
        }
    }

    private function getSitemapLocationElements(SimpleXMLElement $xmlElement): ?array
    {
        $xmlElement->registerXPathNamespace('sitemap', self::XML_SITEMAP_NAMESPACE);
        if ($sitemapLocationElements = $xmlElement->xpath('//sitemap:loc')) {
            return $sitemapLocationElements;
        }

        return null;
    }
}
