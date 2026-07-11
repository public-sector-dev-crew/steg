<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Steg\Model\ToolCall;

final class ToolCallTest extends TestCase
{
    public function testHoldsIdNameAndArguments(): void
    {
        $call = new ToolCall('call_1', 'get_weather', ['city' => 'Berlin']);

        self::assertSame('call_1', $call->id);
        self::assertSame('get_weather', $call->name);
        self::assertSame(['city' => 'Berlin'], $call->arguments);
    }

    public function testArgumentsDefaultToEmpty(): void
    {
        self::assertSame([], (new ToolCall('call_2', 'no_args'))->arguments);
    }
}
