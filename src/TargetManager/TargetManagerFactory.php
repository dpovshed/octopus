<?php

namespace Octopus\TargetManager;

use Octopus\TargetManager;
use Psr\Log\LoggerInterface;
use React\Stream\ReadableStreamInterface;

/**
 * The TargetManager reads URLs from a plain stream and emits them.
 */
class TargetManagerFactory
{
    public static function getInstance(ReadableStreamInterface $input = null, LoggerInterface $logger = null): TargetManager
    {
        return $input
            ? new StreamTargetManager($input, $logger)
            : new NullTargetManager($input, $logger);
    }
}
