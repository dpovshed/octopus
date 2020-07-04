<?php

namespace Octopus\TargetManager;

use Evenement\EventEmitter;
use Octopus\TargetManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * The NullTargetManager does nothing on purpose, it can be considered a NULL Object.
 *
 * @see https://en.wikipedia.org/wiki/Null_object_pattern
 */
class NullTargetManager extends EventEmitter implements ReadableStreamInterface, TargetManager
{
    private ?ReadableStreamInterface $input;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ReadableStreamInterface $input = null, LoggerInterface $logger = null)
    {
        $this->input = $input;
        $this->logger = $logger ?? new NullLogger();
    }

    public function close(): void
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));
    }

    public function getNumberOfUrls(): int
    {
        return 0;
    }

    public function isInitialized(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function pause(): void
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));
    }

    public function resume(): void
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));

        return $destination;
    }

    public function addUrl(string $url): void
    {
        $this->logger->debug(\sprintf('adding URL "%s"', $url));
    }
}
