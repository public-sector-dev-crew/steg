<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Steg\Client\InferenceClientInterface;
use Steg\Client\RetryingInferenceClient;
use Steg\Exception\ConnectionException;
use Steg\Exception\InferenceException;
use Steg\Exception\InvalidResponseException;
use Steg\Exception\ModelNotFoundException;
use Steg\Model\CompletionOptions;
use Steg\Model\CompletionResponse;
use Steg\Model\StreamChunk;

final class RetryingInferenceClientTest extends TestCase
{
    private static function response(string $content = 'ok'): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'mock-model',
            promptTokens: 1,
            completionTokens: 1,
            finishReason: 'stop',
            durationMs: 0.0,
        );
    }

    /**
     * Inner double whose complete()/stream() replay a fixed list of outcomes (response/chunks or throwable).
     *
     * @param list<CompletionResponse|\Throwable> $completeOutcomes
     * @param list<\Throwable|list<StreamChunk>>  $streamOutcomes
     *
     * @return InferenceClientInterface&object{completeCalls: int, streamCalls: int, healthyCalls: int}
     */
    private function inner(array $completeOutcomes = [], array $streamOutcomes = [], bool $healthy = true): InferenceClientInterface
    {
        return new class($completeOutcomes, $streamOutcomes, $healthy) implements InferenceClientInterface {
            public int $completeCalls = 0;
            public int $streamCalls = 0;
            public int $healthyCalls = 0;

            /**
             * @param list<CompletionResponse|\Throwable> $completeOutcomes
             * @param list<\Throwable|list<StreamChunk>>  $streamOutcomes
             */
            public function __construct(
                private readonly array $completeOutcomes,
                private readonly array $streamOutcomes,
                private readonly bool $healthy,
            ) {
            }

            public function complete(array $messages, ?CompletionOptions $options = null): CompletionResponse
            {
                $outcome = $this->completeOutcomes[$this->completeCalls] ?? throw new \LogicException('no more complete outcomes');
                ++$this->completeCalls;
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }

            /**
             * @return \Generator<int, StreamChunk, mixed, void>
             */
            public function stream(array $messages, ?CompletionOptions $options = null): \Generator
            {
                // Body runs lazily on rewind() — a throwable outcome surfaces there, before any chunk.
                $outcome = $this->streamOutcomes[$this->streamCalls] ?? throw new \LogicException('no more stream outcomes');
                ++$this->streamCalls;
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                yield from $outcome;
            }

            public function listModels(): array
            {
                return [];
            }

            public function isHealthy(): bool
            {
                ++$this->healthyCalls;

                return $this->healthy;
            }
        };
    }

    private function noSleep(): \Closure
    {
        return static function (int $microseconds): void {};
    }

    public function testRetriesOnConnectionExceptionThenSucceeds(): void
    {
        $inner = $this->inner([
            ConnectionException::timeout('http://localhost', 1),
            ConnectionException::timeout('http://localhost', 1),
            self::response('recovered'),
        ]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        $result = $client->complete([]);

        self::assertSame('recovered', $result->content);
        self::assertSame(3, $inner->completeCalls);
    }

    public function testRetriesOnServerError(): void
    {
        $inner = $this->inner([
            InferenceException::fromHttpResponse(503, 'Service Unavailable'),
            self::response(),
        ]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        $client->complete([]);

        self::assertSame(2, $inner->completeCalls);
    }

    public function testRetriesOnTooManyRequests(): void
    {
        $inner = $this->inner([
            InferenceException::fromHttpResponse(429, 'Too Many Requests'),
            self::response(),
        ]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        $client->complete([]);

        self::assertSame(2, $inner->completeCalls);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $inner = $this->inner([
            ConnectionException::timeout('http://localhost', 1),
            ConnectionException::timeout('http://localhost', 1),
        ]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 2, sleeper: $this->noSleep());

        $this->expectException(ConnectionException::class);
        try {
            $client->complete([]);
        } finally {
            self::assertSame(2, $inner->completeCalls);
        }
    }

    public function testDoesNotRetryClientError(): void
    {
        $inner = $this->inner([InferenceException::fromHttpResponse(400, 'Bad Request')]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        try {
            $client->complete([]);
            self::fail('expected InferenceException');
        } catch (InferenceException) {
            self::assertSame(1, $inner->completeCalls);
        }
    }

    public function testDoesNotRetryInvalidResponse(): void
    {
        $inner = $this->inner([new InvalidResponseException('malformed')]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        try {
            $client->complete([]);
            self::fail('expected InvalidResponseException');
        } catch (InvalidResponseException) {
            self::assertSame(1, $inner->completeCalls);
        }
    }

    public function testDoesNotRetryModelNotFound(): void
    {
        $inner = $this->inner([ModelNotFoundException::forModel('llama')]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        try {
            $client->complete([]);
            self::fail('expected ModelNotFoundException');
        } catch (ModelNotFoundException) {
            self::assertSame(1, $inner->completeCalls);
        }
    }

    public function testStreamRetriesBeforeFirstChunk(): void
    {
        $inner = $this->inner(streamOutcomes: [
            ConnectionException::timeout('http://localhost', 1),
            [new StreamChunk(delta: 'a', isLast: false), new StreamChunk(delta: 'b', isLast: true, finishReason: 'stop')],
        ]);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        $deltas = [];
        foreach ($client->stream([]) as $chunk) {
            $deltas[] = $chunk->delta;
        }

        self::assertSame(['a', 'b'], $deltas);
        self::assertSame(2, $inner->streamCalls);
    }

    public function testStreamDoesNotRetryAfterFirstChunk(): void
    {
        // Inner yields one chunk, then the transport fails mid-stream — no replay is possible.
        $inner = new class implements InferenceClientInterface {
            public int $streamCalls = 0;

            public function complete(array $messages, ?CompletionOptions $options = null): CompletionResponse
            {
                throw new \LogicException('unused');
            }

            /**
             * @return \Generator<int, StreamChunk, mixed, void>
             */
            public function stream(array $messages, ?CompletionOptions $options = null): \Generator
            {
                ++$this->streamCalls;
                yield new StreamChunk(delta: 'partial', isLast: false);

                throw ConnectionException::timeout('http://localhost', 1);
            }

            public function listModels(): array
            {
                return [];
            }

            public function isHealthy(): bool
            {
                return true;
            }
        };
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        $deltas = [];
        try {
            foreach ($client->stream([]) as $chunk) {
                $deltas[] = $chunk->delta;
            }
            self::fail('expected ConnectionException to propagate');
        } catch (ConnectionException) {
            self::assertSame(['partial'], $deltas);
            self::assertSame(1, $inner->streamCalls);
        }
    }

    public function testIsHealthyDelegatesWithoutRetry(): void
    {
        $inner = $this->inner(healthy: false);
        $client = new RetryingInferenceClient($inner, maxAttempts: 3, sleeper: $this->noSleep());

        self::assertFalse($client->isHealthy());
        self::assertSame(1, $inner->healthyCalls);
    }

    public function testBackoffDoublesAndUsesInjectedSleeperAndJitter(): void
    {
        $inner = $this->inner([
            ConnectionException::timeout('http://localhost', 1),
            ConnectionException::timeout('http://localhost', 1),
            ConnectionException::timeout('http://localhost', 1),
        ]);
        $sleptMicros = [];
        $client = new RetryingInferenceClient(
            $inner,
            maxAttempts: 3,
            baseDelayMs: 200,
            sleeper: static function (int $microseconds) use (&$sleptMicros): void { $sleptMicros[] = $microseconds; },
            jitter: static fn (int $maxMillis): int => $maxMillis, // deterministic: take the full capped delay
        );

        try {
            $client->complete([]);
        } catch (ConnectionException) {
            // exhausted after 3 attempts
        }

        // Backoff runs before attempt 2 (200ms) and attempt 3 (400ms); attempt 3 then gives up.
        self::assertSame([200_000, 400_000], $sleptMicros);
    }
}
