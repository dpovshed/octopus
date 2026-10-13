<?php

declare(strict_types=1);

namespace Octopus\Test;

use Octopus\Config;
use Octopus\Presenter;
use Octopus\Processor;
use Octopus\Result;
use Octopus\TargetManager\StreamTargetManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

use React\Socket\ConnectorInterface;
use React\Stream\ThroughStream;

class ProcessorTest extends TestCase
{
    #[DataProvider('requestFailures')]
    public function testRejectedRequestIsCountedAndTransformerContinues(\Throwable $error, string $errorType): void
    {
        $browser = $this->createMock(Browser::class);
        $browser->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            $this->wrapPromise(reject($error)),
            $this->wrapPromise(resolve(new Response(200))),
        );

        $processor = $this->createProcessor($browser);
        $transformer = (new \ReflectionMethod(Processor::class, 'getTransformer'))->invoke($processor);

        $transformer->write('https://example.com/failed');
        $this->assertTrue($transformer->isWritable());
        $transformer->write('https://example.com/success');

        $this->assertSame(2, $processor->result->countFinishedUrls());
        $this->assertSame(['https://example.com/failed' => $errorType], $processor->result->getBrokenUrls());
        $this->assertSame([$errorType => 1, 200 => 1], $processor->result->getStatusCodes());
    }

    public static function requestFailures(): iterable
    {
        yield 'connection failure' => [new \RuntimeException('Connection failed'), \RuntimeException::class];
        yield 'request timeout' => [new \RuntimeException('Request timed out after 1 seconds'), 'timeout'];
        yield 'connection timeout' => [new \RuntimeException('Connection to example.com:443 timed out after 1 seconds (ETIMEDOUT)'), 'timeout'];
        yield 'HTTP 404' => [new ResponseException(new Response(404)), '404'];
        yield 'HTTP 500' => [new ResponseException(new Response(500)), '500'];
        yield 'general failure' => [new \Exception('Request failed'), 'failure'];
        yield 'PHP error' => [new \Error('Request failed'), 'failure'];
    }

    public function testQueuedRequestsSurviveRejectionAndLoopStopsWhenComplete(): void
    {
        $pending = new Deferred();
        $browser = $this->createMock(Browser::class);
        $browser->expects($this->exactly(3))->method('request')->willReturnOnConsecutiveCalls(
            $this->wrapPromise($pending->promise()),
            $this->wrapPromise(reject(new ResponseException(new Response(500)))),
            $this->wrapPromise(resolve(new Response(200, [], 'OK'))),
        );
        $processor = $this->createProcessor($browser);
        $processor->config->concurrency = 1;
        $processor->config->presenter = $this->createStub(Presenter::class);

        $input = new ThroughStream();
        $targets = new StreamTargetManager(resolve(new Response(200, [], $input)));
        (new \ReflectionProperty(Processor::class, 'targetManager'))->setValue($processor, $targets);
        $transformer = (new \ReflectionMethod(Processor::class, 'getTransformer'))->invoke($processor);
        $targets->pipe($transformer);
        $input->end("https://example.com/timeout\nhttps://example.com/error\nhttps://example.com/success\n");

        $this->assertSame(3, $targets->getNumberOfUrls());
        $this->assertSame(0, $processor->result->countFinishedUrls());

        $loop = LoopFactory::create();
        (new \ReflectionProperty(Processor::class, 'loop'))->setValue($processor, $loop);
        $loop->addPeriodicTimer(0.001, $processor->getPeriodicTimerCallback());
        $timedOut = false;
        // Bound the test so a regression reports a failure instead of hanging PHPUnit.
        $loop->addTimer(1, static function () use ($loop, &$timedOut): void {
            $timedOut = true;
            $loop->stop();
        });
        $loop->futureTick(static fn () => $pending->reject(new \RuntimeException('Request timed out after 1 seconds')));

        $processor->run();

        $this->assertFalse($timedOut, 'Processor should stop when all URLs have finished.');
        $this->assertTrue($transformer->isWritable());
        $this->assertSame(3, $processor->result->countFinishedUrls());
        $this->assertSame(0, $processor->result->getNumberOfRemainingUrlsToProcess(3));
        $this->assertSame([
            'https://example.com/timeout' => 'timeout',
            'https://example.com/error' => '500',
        ], $processor->result->getBrokenUrls());
        $this->assertSame(['timeout' => 1, 500 => 1, 200 => 1], $processor->result->getStatusCodes());
        $this->assertSame(2, $processor->result->getTotalData());
    }

    public function testProcessorHandlesUnavailableTargetsGracefully(): void
    {
        $browser = $this->createMock(Browser::class);
        $browser->expects($this->never())->method('request');
        $processor = $this->createProcessor($browser);
        $targets = new StreamTargetManager(reject(new \RuntimeException('Cannot load sitemap')));
        (new \ReflectionProperty(Processor::class, 'targetManager'))->setValue($processor, $targets);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('stop');
        (new \ReflectionProperty(Processor::class, 'loop'))->setValue($processor, $loop);

        ($processor->getPeriodicTimerCallback())();

        $this->assertTrue($targets->isClosed());
        $this->assertSame(0, $processor->result->countFinishedUrls());
        $this->assertSame([], $processor->result->getBrokenUrls());
    }

    public function testBrowserTimeoutIsRecordedWithoutPromiseTimerDependency(): void
    {
        $loop = LoopFactory::create();
        $pending = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->willReturn($pending->promise());
        $browser = (new Browser($connector, $loop))->withTimeout(0.001);
        $processor = $this->createProcessor($browser);
        $callback = (new \ReflectionMethod(Processor::class, 'getLoadUrlUsingBrowserCallback'))->invoke($processor);
        $callback('https://example.com/timeout')->then(static fn () => $loop->stop());
        $timedOut = false;
        $loop->addTimer(1, static function () use ($loop, &$timedOut): void {
            $timedOut = true;
            $loop->stop();
        });

        $loop->run();

        $this->assertFalse($timedOut);
        $this->assertSame(1, $processor->result->countFinishedUrls());
        $this->assertSame(['https://example.com/timeout' => 'timeout'], $processor->result->getBrokenUrls());
    }

    private function createProcessor(Browser $browser): Processor
    {
        // Bypass constructor I/O; each test supplies its own browser, targets and loop.
        $reflection = new \ReflectionClass(Processor::class);
        $processor = $reflection->newInstanceWithoutConstructor();
        $processor->config = new Config();
        $processor->result = new Result($processor->config);
        $reflection->getProperty('logger')->setValue($processor, new NullLogger());
        $reflection->getProperty('browser')->setValue($processor, $browser);

        return $processor;
    }

    private function wrapPromise(PromiseInterface $promise): PromiseInterface
    {
        // Expose only PromiseInterface, as Promise v3 no longer has ExtendedPromiseInterface.
        $wrapped = $this->createStub(PromiseInterface::class);
        $wrapped->method('then')->willReturnCallback(
            fn ($onFulfilled = null, $onRejected = null): PromiseInterface => $this->wrapPromise($promise->then($onFulfilled, $onRejected)),
        );

        return $wrapped;
    }
}
