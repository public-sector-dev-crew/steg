<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Steg\Client\InferenceClientInterface;
use Steg\Client\MockClient;
use Steg\Exception\InvalidResponseException;
use Steg\Model\ChatMessage;
use Steg\Model\CompletionOptions;
use Steg\Model\CompletionResponse;
use Steg\Model\StreamChunk;
use Steg\Model\ToolCall;
use Steg\StegClient;

final class StegClientTest extends TestCase
{
    public function testImplementsInferenceClientInterface(): void
    {
        $client = new StegClient(new MockClient());

        self::assertInstanceOf(InferenceClientInterface::class, $client);
    }

    public function testCompleteDelegatesToUnderlyingClient(): void
    {
        $client = new StegClient(new MockClient(response: 'pong', model: 'mock'));

        $response = $client->complete(
            [ChatMessage::user('ping')],
            CompletionOptions::default(),
        );

        self::assertSame('pong', $response->content);
        self::assertSame('mock', $response->model);
    }

    public function testIsHealthyDelegates(): void
    {
        $healthy = new StegClient(new MockClient());
        $unhealthy = new StegClient(MockClient::unhealthy());

        self::assertTrue($healthy->isHealthy());
        self::assertFalse($unhealthy->isHealthy());
    }

    public function testAskThrowsWhenResponseHasNoTextContent(): void
    {
        // A tool-call-only response has no text — the text convenience method must fail closed, not return null.
        $client = new StegClient(new class implements InferenceClientInterface {
            public function complete(array $messages, ?CompletionOptions $options = null): CompletionResponse
            {
                return new CompletionResponse(
                    content: null,
                    model: 'm',
                    promptTokens: 0,
                    completionTokens: 0,
                    finishReason: 'tool_calls',
                    durationMs: 0.0,
                    toolCalls: [new ToolCall('call_1', 'do_thing')],
                );
            }

            /**
             * @return \Generator<int, StreamChunk, mixed, void>
             */
            public function stream(array $messages, ?CompletionOptions $options = null): \Generator
            {
                yield from [];
            }

            public function listModels(): array
            {
                return [];
            }

            public function isHealthy(): bool
            {
                return true;
            }
        });

        $this->expectException(InvalidResponseException::class);
        $client->ask('do something');
    }
}
