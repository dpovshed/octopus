<?php

declare(strict_types=1);

namespace Octopus;

/**
 * Handlers definition.
 *
 *
 * @package Octopus
 */
class Handlers
{
    protected $result;
    protected $path = '';

    public function __construct(Config $config, Result $result)
    {
        $this->result = $result;
    }
}
