<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Auto-negotiation bir hız değeri değil, ayrı bir ayardır: açıkken de bir
 * hız bildirilebilir (auto + 1000 Mbps). İkisi tek kolonda tutulsaydı
 * "auto" seçilince hız bilgisi kaybolurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->boolean('is_autonegotiation_enabled')->default(true)->after('speed_config');
            $table->string('speed_config')->nullable()->change();
        });

        // Eski "auto" kayıtları: auto-negotiation açık, bildirilmiş hız yok.
        DB::table('switch_ports')
            ->where('speed_config', 'auto')
            ->update(['speed_config' => null, 'is_autonegotiation_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('switch_ports')
            ->whereNull('speed_config')
            ->update(['speed_config' => 'auto']);

        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->dropColumn('is_autonegotiation_enabled');
            $table->string('speed_config')->nullable(false)->change();
        });
    }
};
