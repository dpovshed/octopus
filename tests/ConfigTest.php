<?php

declare(strict_types=1);

namespace Octopus\Test;

use Octopus\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testValidationThrowsExceptionIfUnsupportedOutputModeIsUsed(): void
    {
        $config = new Config();
        $config->outputMode = 'someOutputModeThatIsNotSupported';

        $this->expectException(\InvalidArgumentException::class);

        $config->validate();
    }

    public function testValidationThrowsExceptionIfUnsupportedRequestTypeIsUsed(): void
    {
        $config = new Config();
        $config->requestType = 'someRequestTypeThatIsNotSupported';

        $this->expectException(\InvalidArgumentException::class);

        $config->validate();
    }

    public function testValidationThrowsExceptionIfUnsupportedTargetTypeIsUsed(): void
    {
        $config = new Config();
        $config->targetType = 'someTargetTypeThatIsNotSupported';

        $this->expectException(\InvalidArgumentException::class);

        $config->validate();
    }

    public function testValidationThrowsExceptionIfBonusRespawnIsTooHigh(): void
    {
        $config = new Config();
        $config->bonusRespawn = 100;

        $this->expectException(\InvalidArgumentException::class);

        $config->validate();
    }

    public function testValidationThrowsExceptionIfConcurrencyIsBelowOne(): void
    {
        $config = new Config();
        $config->concurrency = 0;

        $this->expectException(\InvalidArgumentException::class);

        $config->validate();
    }

    public function testValidationThrowsExceptionIfTimeoutIsTooLow(): void
    {
        $config = new Config();
        $config->timeout = 0.1;

        $this->expectException(\InvalidArgumentException::class);

        $config->validate();
    }
}
