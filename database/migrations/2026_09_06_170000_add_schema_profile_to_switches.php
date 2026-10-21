<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazın yapılandırma şeması ailesi (ELS öncesi / ELS).
 *
 * Null "henüz sorulmadı" demektir; panel o cihaza ELS ÖNCESİ gibi yazar
 * (bugünkü davranış) ve ilk eşitlemede cihaza sorar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->string('schema_profile', 16)->nullable()->after('junos_version');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('schema_profile');
        });
    }
};
