<?php

namespace Octopus;

use Evenement\EventEmitterInterface;
use Psr\Log\LoggerInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * The TargetManager reads URLs from a plain stream and emits them.
 */
interface TargetManager extends EventEmitterInterface
{
    public function __construct(ReadableStreamInterface $input = null, LoggerInterface $logger = null);

    public function isInitialized(): bool;

    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface;

    public function addUrl(string $url): void;

    public function getNumberOfUrls(): int;
}
