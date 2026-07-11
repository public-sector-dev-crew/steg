<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Model;

/**
 * Immutable value object representing a completed inference response.
 */
final readonly class CompletionResponse
{
    /**
     * @param list<ToolCall> $toolCalls normalised tool calls; empty for a plain text response (steg v1.1)
     */
    public function __construct(
        public readonly ?string $content,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $finishReason,
        public readonly float $durationMs,
        public readonly ?string $id = null,
        public readonly array $toolCalls = [],
    ) {
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->toolCalls;
    }

    /**
     * @param array<string, mixed> $data Raw OpenAI-compatible response payload
     */
    public static function fromApiResponse(array $data, float $durationMs): self
    {
        $choices = $data['choices'] ?? null;
        if (!\is_array($choices) || !isset($choices[0]) || !\is_array($choices[0])) {
            throw new \Steg\Exception\InvalidResponseException('Expected field "choices[0]" missing in inference server response.');
        }

        /** @var array<string, mixed> $choice */
        $choice = $choices[0];
        $message = $choice['message'] ?? null;
        if (!\is_array($message)) {
            throw new \Steg\Exception\InvalidResponseException('Expected field "choices[0].message" missing or invalid.');
        }

        $content = \is_string($message['content'] ?? null) ? $message['content'] : null;
        $toolCalls = self::parseToolCalls($message['tool_calls'] ?? null);

        // A valid message carries text content OR tool calls; a message with neither is malformed.
        if (null === $content && [] === $toolCalls) {
            throw new \Steg\Exception\InvalidResponseException('Expected field "choices[0].message.content" missing or invalid.');
        }

        /** @var array<string, mixed> $usage */
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new self(
            content: $content,
            model: \is_string($data['model'] ?? null) ? $data['model'] : 'unknown',
            promptTokens: \is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            completionTokens: \is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            finishReason: \is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : 'unknown',
            durationMs: $durationMs,
            id: \is_string($data['id'] ?? null) ? $data['id'] : null,
            toolCalls: $toolCalls,
        );
    }

    /**
     * @return list<ToolCall>
     */
    private static function parseToolCalls(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $calls = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry) || !\is_array($entry['function'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $function */
            $function = $entry['function'];
            $calls[] = new ToolCall(
                id: \is_string($entry['id'] ?? null) ? $entry['id'] : '',
                name: \is_string($function['name'] ?? null) ? $function['name'] : '',
                arguments: self::normaliseArguments($function['arguments'] ?? null),
            );
        }

        return $calls;
    }

    /**
     * Tool-call arguments arrive as a JSON string (OpenAI/vLLM) or a decoded object (Ollama native).
     * Both are normalised to an associative array so consumers see one shape.
     *
     * @return array<string, mixed>
     */
    private static function normaliseArguments(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = '' === $raw ? [] : json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $arguments = [];
        foreach ($raw as $key => $value) {
            $arguments[(string) $key] = $value;
        }

        return $arguments;
    }
}
