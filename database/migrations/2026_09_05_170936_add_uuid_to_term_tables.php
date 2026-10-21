<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Terimlere de uuid verir.
 *
 * Terim uçları (düzenle, çıkar, sırala) sıralı birincil anahtar taşıyordu:
 * bir terimin kimliğini bilen diğerlerininkini tahmin edebilirdi. Projenin
 * kuralı açık — adres çubuğunda, form değerinde ve DOM kimliğinde uuid
 * bulunur (bkz. CLAUDE.md, "Nesne erişimi (IDOR)").
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const TABLES = ['switch_policy_terms', 'switch_firewall_terms'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });

            foreach (DB::table($table)->whereNull('uuid')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('uuid')->nullable(false)->change();
                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_uuid_unique');
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
