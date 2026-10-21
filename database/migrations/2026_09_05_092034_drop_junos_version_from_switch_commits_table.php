<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Junos, commit başına sürüm bilgisi vermez: ne "show system commit" ne de
 * get-commit-information böyle bir alan döndürür. Kolon hiçbir zaman
 * dolmadı ve dolamaz; ekranda da kalıcı olarak "—" gösteriyordu.
 *
 * Toplanamayan alan tutulmaz (bkz. .ai/rules/seeders.md); sunucularda
 * cpu/ram/disk kolonları da aynı gerekçeyle kaldırılmıştı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_commits', function (Blueprint $table): void {
            $table->dropColumn('junos_version');
        });
    }

    public function down(): void
    {
        Schema::table('switch_commits', function (Blueprint $table): void {
            $table->string('junos_version')->nullable()->after('sequence_number');
        });
    }
};
