<?php

namespace Octopus\Sitemap;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use SimpleXMLElement;

/**
 * The SitemapLoader reads from a plain stream, detects Sitemaps (Indexes) and emits URLs.
 */
class Loader extends EventEmitter implements ReadableStreamInterface
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
    private const XML_SITEMAP_ELEMENT = 'sitemap';

    /**
     * @see https://www.sitemaps.org/protocol.html
     *
     * @var string
     */
    private const XML_SITEMAP_ROOT_ELEMENT = 'urlset';

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var bool
     */
    private $closed = false;

    /**
     * @var ReadableStreamInterface
     */
    private $input;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $numberOfUrls = 0;

    public function __construct(ReadableStreamInterface $input, LoggerInterface $logger = null)
    {
        $this->input = $input;
        $this->logger = $logger ?? new NullLogger();

        if (!$input->isReadable()) {
            $this->close();
        }

        $this->input->on('data', $this->getHandleDataCallback());
        $this->input->on('end', $this->getHandleEndCallback());
        $this->input->on('error', $this->getHandleErrorCallback());
        $this->input->on('close', $this->getHandleCloseCallback());
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function getNumberOfUrls(): int
    {
        return $this->numberOfUrls;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = '';

        $this->input->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function pause(): void
    {
        $this->input->pause();
    }

    public function resume(): void
    {
        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function addUrl(string $url): void
    {
        ++$this->numberOfUrls;
        $this->emit('data', [$url]);
    }

    private function getHandleDataCallback(): callable
    {
        return function ($data): void {
            $this->buffer .= $data;
        };
    }

    private function getHandleEndCallback(): callable
    {
        return function (): void {
            if ($xmlElement = $this->getSimpleXMLElement($this->buffer)) {
                if ($this->isXmlSitemapIndex($xmlElement)) {
                    $this->processSitemapIndex($xmlElement);

                    return;
                }
                if ($this->isXmlSitemap($xmlElement)) {
                    $this->processSitemapElement($xmlElement);

                    return;
                }
            }

            if (!$this->closed) {
                $this->emit('end');
                $this->close();
            }
        };
    }

    private function getHandleErrorCallback(): callable
    {
        return function (\Exception $error): void {
            $this->emit('error', [$error]);
            $this->close();
        };
    }

    private function getHandleCloseCallback(): callable
    {
        return function (): void {
            $this->close();
        };
    }

    private function isXmlSitemap(SimpleXMLElement $xmlElement): bool
    {
        $xmlRootElement = $xmlElement->getName();

        return $xmlRootElement === self::XML_SITEMAP_ROOT_ELEMENT //Used by standalone Sitemaps
            || $xmlRootElement === self::XML_SITEMAP_ELEMENT; //Used when part of a Sitemap Index
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
                $this->logger->debug('processed URLs in '.$sitemapUrl.', now totalling '.$this->getNumberOfUrls());
            }
        }
    }

    private function processSitemapUrl(string $sitemapUrl): void
    {
        if ($data = $this->loadExternalData($sitemapUrl)) {
            if ($sitemapElement = $this->getSimpleXMLElement($data)) {
                $this->processSitemapElement($sitemapElement);
            }
        }
    }

    private function getSimpleXMLElement(string $data): ?SimpleXMLElement
    {
        try {
            return @(new SimpleXMLElement($data));
        } catch (\Exception $exception) {
            $this->logger->error('Failed instantiating SimpleXMLElement:'.$exception->getMessage());
        }

        return null;
    }

    private function loadExternalData(string $file): ?string
    {
        if ($data = @\file_get_contents($file)) {
            return $data;
        }

        return null;
    }

    private function processSitemapElement(SimpleXMLElement $sitemapElement): void
    {
        if ($sitemapLocationElements = $this->getSitemapLocationElements($sitemapElement)) {
            foreach ($sitemapLocationElements as $sitemapLocationElement) {
                $sitemapUrl = (string) $sitemapLocationElement;

                $this->addUrl($sitemapUrl);
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
