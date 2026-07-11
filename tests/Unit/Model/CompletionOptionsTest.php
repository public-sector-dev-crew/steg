<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Steg\Model\CompletionOptions;
use Steg\Model\ResponseFormat;

final class CompletionOptionsTest extends TestCase
{
    public function testDefaultPreset(): void
    {
        $opts = CompletionOptions::default();

        self::assertSame(0.7, $opts->temperature);
        self::assertSame(4096, $opts->maxTokens);
    }

    public function testPrecisePreset(): void
    {
        $opts = CompletionOptions::precise();

        self::assertSame(0.1, $opts->temperature);
    }

    public function testCreativePreset(): void
    {
        $opts = CompletionOptions::creative();

        self::assertSame(0.9, $opts->temperature);
    }

    public function testLeichteSprachePreset(): void
    {
        $opts = CompletionOptions::leichteSprache();

        self::assertSame(0.3, $opts->temperature);
        self::assertSame(4096, $opts->maxTokens);
    }

    public function testWithTemperatureReturnsNewInstance(): void
    {
        $original = CompletionOptions::default();
        $modified = $original->withTemperature(0.5);

        self::assertSame(0.7, $original->temperature);
        self::assertSame(0.5, $modified->temperature);
        self::assertSame($original->maxTokens, $modified->maxTokens);
    }

    public function testWithMaxTokensReturnsNewInstance(): void
    {
        $original = CompletionOptions::default();
        $modified = $original->withMaxTokens(1024);

        self::assertSame(4096, $original->maxTokens);
        self::assertSame(1024, $modified->maxTokens);
    }

    public function testToArray(): void
    {
        $opts = CompletionOptions::precise();
        $array = $opts->toArray();

        self::assertArrayHasKey('temperature', $array);
        self::assertArrayHasKey('max_tokens', $array);
        self::assertSame(0.1, $array['temperature']);
        self::assertArrayNotHasKey('stop', $array);
    }

    public function testToArrayIncludesStopWhenSet(): void
    {
        $opts = new CompletionOptions(stop: ['</s>', '[STOP]']);
        $array = $opts->toArray();

        self::assertArrayHasKey('stop', $array);
        self::assertSame(['</s>', '[STOP]'], $array['stop']);
    }

    public function testToArrayOmitsResponseFormatByDefault(): void
    {
        // BC: an unconstrained request must serialise byte-identically to pre-v1.1.
        self::assertSame(
            [
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'top_p' => 1.0,
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            CompletionOptions::default()->toArray(),
        );
    }

    public function testToArrayIncludesJsonSchemaResponseFormat(): void
    {
        $schema = ['type' => 'object', 'properties' => ['translation' => ['type' => 'string']]];
        $opts = CompletionOptions::precise()->withResponseFormat(
            ResponseFormat::jsonSchema($schema, name: 'translation_output'),
        );

        self::assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'translation_output', 'schema' => $schema, 'strict' => true],
        ], $opts->toArray()['response_format']);
    }

    public function testToArrayOmitsTextResponseFormat(): void
    {
        // Explicit Text is treated like "no constraint" — the key stays absent (byte-identical).
        $opts = CompletionOptions::default()->withResponseFormat(ResponseFormat::text());

        self::assertArrayNotHasKey('response_format', $opts->toArray());
    }

    public function testWithResponseFormatReturnsNewInstance(): void
    {
        $original = CompletionOptions::default();
        $modified = $original->withResponseFormat(ResponseFormat::jsonObject());

        self::assertNull($original->responseFormat);
        self::assertNotNull($modified->responseFormat);
        self::assertSame($original->temperature, $modified->temperature);
    }

    public function testWithTemperatureCarriesResponseFormat(): void
    {
        // Regression guard: the 7th field must survive existing withers (named-arg reconstruction).
        $format = ResponseFormat::jsonObject();
        $opts = (new CompletionOptions(responseFormat: $format))->withTemperature(0.5);

        self::assertSame($format, $opts->responseFormat);
    }

    public function testWithMaxTokensCarriesResponseFormat(): void
    {
        $format = ResponseFormat::jsonObject();
        $opts = (new CompletionOptions(responseFormat: $format))->withMaxTokens(1024);

        self::assertSame($format, $opts->responseFormat);
    }

    public function testToArrayOmitsToolsByDefault(): void
    {
        $array = CompletionOptions::default()->toArray();

        self::assertArrayNotHasKey('tools', $array);
        self::assertArrayNotHasKey('tool_choice', $array);
    }

    public function testToArrayIncludesToolsAndToolChoiceWhenSet(): void
    {
        $tools = [['type' => 'function', 'function' => ['name' => 'get_weather']]];
        $array = (new CompletionOptions(tools: $tools, toolChoice: 'auto'))->toArray();

        self::assertSame($tools, $array['tools']);
        self::assertSame('auto', $array['tool_choice']);
    }

    public function testWithersPreserveToolsAndToolChoice(): void
    {
        // Regression guard: every wither must carry the tool fields, not silently reset them.
        $tools = [['type' => 'function', 'function' => ['name' => 'x']]];
        $base = new CompletionOptions(tools: $tools, toolChoice: 'required');

        self::assertSame($tools, $base->withTemperature(0.2)->tools);
        self::assertSame('required', $base->withTemperature(0.2)->toolChoice);
        self::assertSame($tools, $base->withMaxTokens(10)->tools);
        self::assertSame('required', $base->withMaxTokens(10)->toolChoice);
        self::assertSame($tools, $base->withResponseFormat(null)->tools);
    }
}
