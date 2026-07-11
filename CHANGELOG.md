# Changelog

All notable changes to `lotse/steg` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Structured output (steg v1.1, phase 1): decode-time `response_format` request parameter.
  - `Steg\Model\ResponseFormat` — provider-neutral value object — plus `Steg\Model\ResponseFormatMode`
    enum (`Text`/`JsonObject`/`JsonSchema`). Named constructors (`text()`/`jsonObject()`/`jsonSchema()`)
    make illegal states unrepresentable (`JsonSchema` without a schema cannot be built).
  - `CompletionOptions` gains an additive, nullable, trailing `responseFormat` field plus
    `withResponseFormat()`; serialised OpenAI-canonically and carried into both `complete()` and
    `stream()` payloads without any client change. Unconstrained requests (`null` / `Text`) serialise
    byte-identically to v1.0. `InferenceClientInterface` is unchanged (BC-safe).
- Transport retry (steg v1.1, phase 2): `Steg\Client\RetryingInferenceClient` decorator.
  - Bounded exponential backoff + full jitter over any `InferenceClientInterface`, retrying only
    transport failures — `ConnectionException` and HTTP 5xx / 429 (`InferenceException`). Client errors
    (other 4xx, model-not-found) and malformed responses are not retried. Streaming is retried only before
    the first chunk. `Retry-After` handling for 429 is deferred (headers are not surfaced yet).
  - Opt-in via the `StegClientFactory` `retries` DSN/config option (default `0` = no retry, unchanged from
    v1.0). Injectable sleeper keeps the policy testable without real waiting.
- Tool calling (steg v1.1, phase 3): response parsing + opaque request passthrough.
  - `Steg\Model\ToolCall` (`id`, `name`, `arguments`) — normalised tool call. Arguments arrive as a JSON
    string (OpenAI/vLLM) or a native object (Ollama) and are normalised to an associative array.
  - `CompletionResponse.content` widens to `?string` (null only on a tool-call-only response) and gains
    `list<ToolCall> $toolCalls` + `hasToolCalls()`; `fromApiResponse()` accepts `content: null` with
    `tool_calls`, still rejects a message with neither. `StegClient::ask()`/`chat()` keep returning
    `string` and now fail closed (throw) on a tool-call-only response instead of returning null.
  - `CompletionOptions` gains additive, nullable `tools` / `toolChoice` fields, passed through opaquely to
    the request (no typed tool contract — that is a future agent-runtime concern). Streaming tool-call
    deltas are deferred.

## [1.0.0] - 2026-03-24

First stable release. BC-promise starts here — no breaking changes to public interfaces
without a major version bump.

### Added

- `InferenceClientInterface` — BC-stable contract for all LLM inference communication
- `OpenAiCompatibleClient` — Full HTTP client for vLLM, Ollama, LiteLLM, LocalAI, llama.cpp
  - Streaming via SSE (`stream()` returns `Generator<StreamChunk>`)
  - Model listing (`listModels()`)
  - Health check (`isHealthy()`)
  - Full exception mapping (4xx/5xx → typed exceptions)
- `MockClient` — deterministic test double
  - Fixed response cycling via `withResponses()`
  - Dynamic responses via `withCallback()`
  - Streaming support
- `StegClient` — convenience façade with `ask()`, `chat()`, `complete()`, `stream()`
- `StegClientFactory` — DSN-based and array-config-based factory
  - Supported DSN schemes: `vllm://`, `ollama://`, `litellm://`, `localai://`, `llama://`, `mock://`
- Value Objects (`final readonly`):
  - `ChatMessage` with named constructors (`user()`, `system()`, `assistant()`)
  - `CompletionRequest`, `CompletionResponse`, `StreamChunk`, `ModelInfo`
  - `CompletionOptions` with presets: `default()`, `precise()`, `creative()`, `leichteSprache()`
- Exception hierarchy:
  - `StegException` (abstract base)
  - `ConnectionException` — server unreachable or transport error
  - `InferenceException` — server returned 4xx/5xx
  - `ModelNotFoundException` — model not loaded (404)
  - `InvalidResponseException` — response parsing failed
- 58 tests (40 unit + 18 integration), PHPStan Level 9, PHP-CS-Fixer clean
- GitHub Actions CI: PHP 8.2, 8.3, 8.4
- EUPL-1.2 license
