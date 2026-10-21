<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arayüz MTU'su.
 *
 * Jumbo frame gerektiren uplink ve toplama bacaklarında panelden ayarlanır.
 * Sınırlar tahmin değil, cihazın kendi cevabıdır (jn32-test, 12.3R12.4):
 * "Value 9217 is not within range (256..9216)".
 *
 * ae ÜYESİ portta yazılamaz: cihaz "ae child device mtu setting and
 * vlan-tagging is not allowed" diyor. MTU o durumda ae arayüzünün kendisine
 * verilir; ae0 üzerinde denendi ve kabul edildi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('mtu')->nullable()->after('link_speed');
        });
    }

    public function down(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->dropColumn('mtu');
        });
    }
};
