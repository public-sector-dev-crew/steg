<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Model;

/**
 * Decode-time output constraint mode requested from the inference server (steg v1.1).
 *
 * Provider-agnostic: the mode names mirror the OpenAI-compatible `response_format` vocabulary
 * that every targeted server speaks over `/v1` (vLLM, Ollama, LiteLLM, llama.cpp). No provider
 * identity leaks into this contract.
 */
enum ResponseFormatMode: string
{
    /** No constraint — free-form text (default; keeps the request byte-identical to pre-v1.1). */
    case Text = 'text';

    /** Free-form JSON mode: the server must emit syntactically valid JSON. */
    case JsonObject = 'json_object';

    /** Schema-constrained JSON: the server enforces the supplied JSON Schema during decoding. */
    case JsonSchema = 'json_schema';
}
