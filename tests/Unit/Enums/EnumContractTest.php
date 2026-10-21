<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\BadgeVariant;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Her enum'un her durumu için Türkçe etiket ve renk varyantı tanımlı olmalı.
 * Yeni bir durum eklendiğinde bu test eksik eşlemeyi yakalar.
 */
final class EnumContractTest extends TestCase
{
    #[DataProvider('enumCases')]
    public function test_every_case_has_a_label_and_a_badge(string $enum, string $case): void
    {
        /** @var BackedEnum $instance */
        $instance = constant("{$enum}::{$case}");

        $this->assertNotSame('', $instance->label(), "{$enum}::{$case} etiketi boş");
        $this->assertInstanceOf(BadgeVariant::class, $instance->badge());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function enumCases(): array
    {
        $cases = [];

        foreach (glob(__DIR__.'/../../../app/Enums/*.php') as $file) {
            $enum = 'App\\Enums\\'.basename($file, '.php');

            if ($enum === BadgeVariant::class || ! enum_exists($enum)) {
                continue;
            }

            foreach ((new ReflectionEnum($enum))->getCases() as $case) {
                $cases["{$enum}::{$case->getName()}"] = [$enum, $case->getName()];
            }
        }

        return $cases;
    }

    public function test_badge_variants_map_to_css_classes(): void
    {
        $this->assertSame('badge-green', BadgeVariant::Green->cssClass());
        $this->assertSame('badge-gray', BadgeVariant::Gray->cssClass());
    }
}
