<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use Marcolin\SocketIO\Engine\RandomSessionIdGenerator;
use PHPUnit\Framework\TestCase;

final class RandomSessionIdGeneratorTest extends TestCase
{
    public function testGeneratesUrlSafeTwentyCharacterIds(): void
    {
        $generator = new RandomSessionIdGenerator();
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20}$/', $id);
            $ids[$id] = true;
        }

        self::assertCount(1000, $ids);
    }
}
