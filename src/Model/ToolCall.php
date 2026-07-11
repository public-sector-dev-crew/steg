<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Model;

/**
 * Immutable, normalised tool call parsed from an inference response (steg v1.1).
 *
 * steg owns the wire format, so parsing the provider's `tool_calls` into this shape is its job.
 * Validating the arguments against a tool's input schema is structured-output quality assurance
 * and belongs to fender, not here — steg only guarantees the arguments are syntactically decoded
 * and encoding-normalised (a JSON string from OpenAI/vLLM or a native object from Ollama both
 * become an associative array).
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments decoded, encoding-normalised tool-call arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {
    }
}
