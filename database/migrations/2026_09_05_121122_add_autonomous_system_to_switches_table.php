<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazın kendi AS numarası (routing-options autonomous-system).
 *
 * Switch başına TEK değerdir, oturum başına değil. Eski tabloda her satırda
 * "local_asn" olarak tekrar ediliyordu ve iki satır farklı değer taşıyabildiği
 * için cihazda karşılığı olmayan bir durum temsil edilebiliyordu.
 *
 * Cihazdan eşitleme doldurur; formda sorulmaz (bkz. .ai/rules/junos.md,
 * "Cihaza ait alanlar formda sorulmaz").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->string('autonomous_system', 20)->nullable()->after('uplink_ip');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('autonomous_system');
        });
    }
};
