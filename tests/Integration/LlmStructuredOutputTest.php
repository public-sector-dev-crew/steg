<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Steg\Factory\StegClientFactory;
use Steg\Model\ChatMessage;
use Steg\Model\CompletionOptions;
use Steg\Model\ResponseFormat;

/**
 * Live integration skeletons against a real OpenAI-compatible inference server (steg v1.1, phase 4).
 *
 * These verify what the mock tests cannot: that a real server actually honours `response_format`
 * (schema-conforming JSON) and emits tool calls whose arguments parse across provider encodings.
 *
 * Excluded from the default suite via group "llm" (see phpunit.xml.dist) and additionally skipped
 * unless the relevant `*_DSN` env var points at a running server — so they never run in the sandbox
 * (no model; shmget/socket blocked) and only execute on a host/CI with real vLLM/Ollama. Configure e.g.:
 *   STEG_LLM_VLLM_DSN='vllm://localhost:8000/v1?model=<model>'
 *   STEG_LLM_OLLAMA_DSN='ollama://localhost:11434/v1?model=<model>'
 *   STEG_LLM_TOOL_DSN='vllm://localhost:8000/v1?model=<tool-capable-model>'
 * Run with: vendor/bin/phpunit --group llm
 */
#[Group('llm')]
final class LlmStructuredOutputTest extends TestCase
{
    private function dsnFromEnv(string $envVar): string
    {
        $dsn = getenv($envVar);
        if (!\is_string($dsn) || '' === $dsn) {
            self::markTestSkipped(\sprintf('%s is not set — needs a running inference server (host/CI only).', $envVar));
        }

        return $dsn;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function serverDsnEnvVars(): array
    {
        return [
            'vLLM' => ['STEG_LLM_VLLM_DSN'],
            'Ollama /v1' => ['STEG_LLM_OLLAMA_DSN'],
        ];
    }

    #[DataProvider('serverDsnEnvVars')]
    public function testResponseFormatJsonSchemaYieldsConformingJson(string $envVar): void
    {
        $client = StegClientFactory::fromDsn($this->dsnFromEnv($envVar));
        $schema = [
            'type' => 'object',
            'properties' => ['translation' => ['type' => 'string']],
            'required' => ['translation'],
            'additionalProperties' => false,
        ];

        $response = $client->complete(
            [
                ChatMessage::system('You translate German administrative text to Leichte Sprache. Reply as JSON.'),
                ChatMessage::user('Übersetze in Leichte Sprache: Der Antrag wurde bewilligt.'),
            ],
            (new CompletionOptions(temperature: 0.0))->withResponseFormat(
                ResponseFormat::jsonSchema($schema, name: 'translation_output'),
            ),
        );

        self::assertNotNull($response->content, 'expected text content, not a tool call');

        $decoded = json_decode($response->content, true);
        if (!\is_array($decoded)) {
            self::fail('server must honour json_schema and return a valid JSON object');
        }
        self::assertArrayHasKey('translation', $decoded, 'the decoded output must match the requested schema');
    }

    public function testToolCallAgainstToolCapableModel(): void
    {
        $client = StegClientFactory::fromDsn($this->dsnFromEnv('STEG_LLM_TOOL_DSN'));
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get the current weather for a city.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['city' => ['type' => 'string']],
                        'required' => ['city'],
                    ],
                ],
            ],
        ];

        $response = $client->complete(
            [ChatMessage::user('What is the weather in Berlin? Use the get_weather tool.')],
            new CompletionOptions(temperature: 0.0, tools: $tools, toolChoice: 'required'),
        );

        // A tool-capable server with tool_choice=required returns a tool call, not text.
        self::assertTrue($response->hasToolCalls(), 'expected the model to call a tool');
        $call = $response->toolCalls[0];
        self::assertSame('get_weather', $call->name);
        // arguments are normalised to an array regardless of the provider encoding (JSON string vs object).
        self::assertArrayHasKey('city', $call->arguments);
    }

    public function testRetryDecoratorHappyPathAgainstRealServer(): void
    {
        $dsn = $this->dsnFromEnv('STEG_LLM_VLLM_DSN');
        $dsn .= (str_contains($dsn, '?') ? '&' : '?').'retries=2';
        $client = StegClientFactory::fromDsn($dsn);

        $response = $client->complete([ChatMessage::user('Reply with the single word: OK.')]);

        self::assertNotNull($response->content);
        self::assertNotSame('', trim($response->content));
    }
}
