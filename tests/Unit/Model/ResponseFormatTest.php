<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Steg\Model\ResponseFormat;
use Steg\Model\ResponseFormatMode;

final class ResponseFormatTest extends TestCase
{
    public function testTextWireShape(): void
    {
        $format = ResponseFormat::text();

        self::assertSame(ResponseFormatMode::Text, $format->mode);
        self::assertSame(['type' => 'text'], $format->toArray());
    }

    public function testJsonObjectWireShape(): void
    {
        $format = ResponseFormat::jsonObject();

        self::assertSame(ResponseFormatMode::JsonObject, $format->mode);
        self::assertSame(['type' => 'json_object'], $format->toArray());
    }

    public function testJsonSchemaWireShape(): void
    {
        $schema = ['type' => 'object', 'required' => ['translation']];
        $format = ResponseFormat::jsonSchema($schema);

        self::assertSame(ResponseFormatMode::JsonSchema, $format->mode);
        self::assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'output', 'schema' => $schema, 'strict' => true],
        ], $format->toArray());
    }

    public function testJsonSchemaCarriesNameAndStrict(): void
    {
        $format = ResponseFormat::jsonSchema(['type' => 'object'], name: 'translation_output', strict: false);

        self::assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'translation_output', 'schema' => ['type' => 'object'], 'strict' => false],
        ], $format->toArray());
    }

    public function testJsonSchemaFactoryGuaranteesSchemaPresent(): void
    {
        // The illegal state "JsonSchema without a schema" is unrepresentable — the factory requires it.
        $format = ResponseFormat::jsonSchema(['type' => 'object']);

        self::assertNotNull($format->schema);
    }
}
