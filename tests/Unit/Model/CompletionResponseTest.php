<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Steg\Exception\InvalidResponseException;
use Steg\Model\CompletionResponse;

final class CompletionResponseTest extends TestCase
{
    public function testFromApiResponse(): void
    {
        $data = [
            'id' => 'chatcmpl-abc123',
            'model' => 'llama-3.3-70b',
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ];

        $response = CompletionResponse::fromApiResponse($data, 123.4);

        self::assertSame('Hello!', $response->content);
        self::assertSame('llama-3.3-70b', $response->model);
        self::assertSame(10, $response->promptTokens);
        self::assertSame(5, $response->completionTokens);
        self::assertSame(15, $response->totalTokens());
        self::assertSame('stop', $response->finishReason);
        self::assertSame(123.4, $response->durationMs);
        self::assertSame('chatcmpl-abc123', $response->id);
    }

    public function testFromApiResponseMissingChoicesThrows(): void
    {
        $this->expectException(InvalidResponseException::class);

        CompletionResponse::fromApiResponse([], 0.0);
    }

    public function testFromApiResponseMissingContentThrows(): void
    {
        $this->expectException(InvalidResponseException::class);

        CompletionResponse::fromApiResponse([
            'choices' => [['message' => ['role' => 'assistant']]],
        ], 0.0);
    }

    public function testTotalTokens(): void
    {
        $response = new CompletionResponse(
            content: 'test',
            model: 'test-model',
            promptTokens: 42,
            completionTokens: 8,
            finishReason: 'stop',
            durationMs: 0.0,
        );

        self::assertSame(50, $response->totalTokens());
    }

    public function testFromApiResponseKeepsTextAndReportsNoToolCalls(): void
    {
        $response = CompletionResponse::fromApiResponse([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
        ], 1.0);

        self::assertSame('Hi', $response->content);
        self::assertFalse($response->hasToolCalls());
        self::assertSame([], $response->toolCalls);
    }

    public function testFromApiResponseParsesToolCallWithJsonStringArguments(): void
    {
        // OpenAI/vLLM shape: content is null and arguments is a JSON string.
        $response = CompletionResponse::fromApiResponse([
            'model' => 'llama',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], 1.0);

        self::assertNull($response->content);
        self::assertTrue($response->hasToolCalls());
        self::assertCount(1, $response->toolCalls);
        self::assertSame('call_1', $response->toolCalls[0]->id);
        self::assertSame('get_weather', $response->toolCalls[0]->name);
        self::assertSame(['city' => 'Berlin'], $response->toolCalls[0]->arguments);
    }

    public function testFromApiResponseParsesToolCallWithObjectArguments(): void
    {
        // Ollama native shape: arguments is an already-decoded object.
        $response = CompletionResponse::fromApiResponse([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'tool_calls' => [[
                        'id' => 'call_2',
                        'function' => ['name' => 'lookup', 'arguments' => ['id' => 42]],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], 1.0);

        self::assertNull($response->content);
        self::assertSame(['id' => 42], $response->toolCalls[0]->arguments);
    }

    public function testFromApiResponseSkipsMalformedToolCallEntries(): void
    {
        // An entry without a function object is skipped; content carries the response instead.
        $response = CompletionResponse::fromApiResponse([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'text', 'tool_calls' => ['garbage']],
                'finish_reason' => 'stop',
            ]],
        ], 1.0);

        self::assertSame('text', $response->content);
        self::assertFalse($response->hasToolCalls());
    }
}
