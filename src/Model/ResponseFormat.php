<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Model;

/**
 * Immutable, provider-neutral request for a decode-time output constraint (steg v1.1).
 *
 * steg only *sends* the constraint; parsing and validating the returned structure is the job of the
 * structured-output layer (fender), never steg. The named constructors make illegal states
 * unrepresentable — a {@see ResponseFormatMode::JsonSchema} without a schema cannot be built.
 *
 * Serialised once into the canonical OpenAI-compatible `response_format` wire shape via
 * {@see toArray()}; it travels through {@see CompletionOptions::toArray()} into both complete() and
 * stream() payloads without any change to the client.
 */
final readonly class ResponseFormat
{
    /**
     * @param array<string, mixed>|null $schema domain-neutral JSON Schema (e.g. from fender's SchemaGenerator); only set in JsonSchema mode
     */
    private function __construct(
        public ResponseFormatMode $mode,
        public ?array $schema = null,
        public ?string $name = null,
        public bool $strict = true,
    ) {
    }

    /**
     * No constraint — equivalent to omitting response_format (kept for explicit, self-documenting calls).
     */
    public static function text(): self
    {
        return new self(ResponseFormatMode::Text);
    }

    /**
     * Free-form JSON mode: the server must return syntactically valid JSON, without a fixed schema.
     */
    public static function jsonObject(): self
    {
        return new self(ResponseFormatMode::JsonObject);
    }

    /**
     * Schema-constrained JSON mode (the fender enabler).
     *
     * @param array<string, mixed> $schema JSON Schema the server must enforce — required, so a JsonSchema without a schema is impossible
     */
    public static function jsonSchema(array $schema, string $name = 'output', bool $strict = true): self
    {
        return new self(ResponseFormatMode::JsonSchema, $schema, $name, $strict);
    }

    /**
     * @return array<string, mixed> canonical OpenAI-compatible `response_format` payload
     */
    public function toArray(): array
    {
        return match ($this->mode) {
            ResponseFormatMode::Text => ['type' => 'text'],
            ResponseFormatMode::JsonObject => ['type' => 'json_object'],
            ResponseFormatMode::JsonSchema => ['type' => 'json_schema', 'json_schema' => [
                'name' => $this->name ?? 'output',
                'schema' => $this->schema ?? [],
                'strict' => $this->strict,
            ]],
        };
    }
}
