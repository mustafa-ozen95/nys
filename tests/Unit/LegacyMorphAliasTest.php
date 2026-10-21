<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ConsoleSession;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sınıflar İngilizce adlandırılırken etkinlik kaydındaki eski sınıf adı satırları
 * (subject_type / konu_type) ve zincir özetleri bozulmamalı.
 */
class LegacyMorphAliasTest extends TestCase
{
    public static function renamed(): array
    {
        return [
            ['App\\Models\\KonsolOturumu', ConsoleSession::class],
        ];
    }

    #[DataProvider('renamed')]
    public function test_old_name_resolves_and_new_rows_keep_old_name(string $old, string $new): void
    {
        $this->assertSame($new, Relation::getMorphedModel($old));
        $this->assertSame($old, (new $new)->getMorphClass());
    }
}
