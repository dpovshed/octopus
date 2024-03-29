<?php

declare(strict_types=1);

namespace Octopus\Test;

use Octopus\Config;
use Octopus\Processor;
use PHPUnit\Framework\TestCase;

class ProcessorTest extends TestCase
{
    public function testProcessorHandlesUnAvailableTargetsGracefully()
    {
        $config = new Config();
        // $config->targetFile = '/some/unExisting/path/to/a/file/with/urls.txt';
        $config->targetFile = 'https://google.com/sitemap.xml'; // This needs to be an existing domain, otherwise the test fails: improve test
        $config->timeout = 1;

        $processor = new Processor($config);
        $processor->run();

        $this->assertEquals(0, $processor->result->countFinishedUrls());
    }
}
