<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Model;

/**
 * Immutable value object for LLM completion parameters.
 *
 * Use the static factory methods for common presets:
 *   - CompletionOptions::default()        — general purpose (temperature 0.7)
 *   - CompletionOptions::precise()        — structured/JSON output (temperature 0.1)
 *   - CompletionOptions::creative()       — creative text (temperature 0.9)
 *   - CompletionOptions::leichteSprache() — LS-KI default (temperature 0.3)
 */
final readonly class CompletionOptions
{
    public function __construct(
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 4096,
        public readonly float $topP = 1.0,
        /** @var list<string>|null */
        public readonly ?array $stop = null,
        public readonly float $frequencyPenalty = 0.0,
        public readonly float $presencePenalty = 0.0,
        public readonly ?ResponseFormat $responseFormat = null,
        /** @var list<array<string, mixed>>|null OpenAI-shaped tool/function definitions, passed through opaquely (steg v1.1) */
        public readonly ?array $tools = null,
        /** @var string|array<string, mixed>|null 'auto'/'none'/'required' or a named-tool object, passed through opaquely */
        public readonly string|array|null $toolChoice = null,
    ) {
    }

    public static function default(): self
    {
        return new self(
            temperature: 0.7,
            maxTokens: 4096,
        );
    }

    /**
     * Low temperature for structured/JSON outputs.
     */
    public static function precise(): self
    {
        return new self(
            temperature: 0.1,
            maxTokens: 4096,
        );
    }

    /**
     * High temperature for creative text generation.
     */
    public static function creative(): self
    {
        return new self(
            temperature: 0.9,
            maxTokens: 4096,
        );
    }

    /**
     * LS-KI default for Leichte Sprache translations.
     */
    public static function leichteSprache(): self
    {
        return new self(
            temperature: 0.3,
            maxTokens: 4096,
        );
    }

    public function withTemperature(float $temperature): self
    {
        return new self(
            temperature: $temperature,
            maxTokens: $this->maxTokens,
            topP: $this->topP,
            stop: $this->stop,
            frequencyPenalty: $this->frequencyPenalty,
            presencePenalty: $this->presencePenalty,
            responseFormat: $this->responseFormat,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
        );
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            temperature: $this->temperature,
            maxTokens: $maxTokens,
            topP: $this->topP,
            stop: $this->stop,
            frequencyPenalty: $this->frequencyPenalty,
            presencePenalty: $this->presencePenalty,
            responseFormat: $this->responseFormat,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
        );
    }

    /**
     * Request a decode-time output constraint (steg v1.1). Pass null to clear it.
     */
    public function withResponseFormat(?ResponseFormat $responseFormat): self
    {
        return new self(
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            topP: $this->topP,
            stop: $this->stop,
            frequencyPenalty: $this->frequencyPenalty,
            presencePenalty: $this->presencePenalty,
            responseFormat: $responseFormat,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
        ];

        if (null !== $this->stop) {
            $data['stop'] = $this->stop;
        }

        // Omit for Text/null so the payload stays byte-identical to pre-v1.1 for unconstrained calls.
        if (null !== $this->responseFormat && ResponseFormatMode::Text !== $this->responseFormat->mode) {
            $data['response_format'] = $this->responseFormat->toArray();
        }

        if (null !== $this->tools) {
            $data['tools'] = $this->tools;
        }

        if (null !== $this->toolChoice) {
            $data['tool_choice'] = $this->toolChoice;
        }

        return $data;
    }
}
