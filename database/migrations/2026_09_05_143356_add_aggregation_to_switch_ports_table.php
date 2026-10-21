<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bağlantı toplama (LAG) alanları.
 *
 * ae bir ARAYÜZDÜR ve panel arayüzleri zaten switch_ports'ta modelliyor:
 * açıklama, port modu, VLAN üyeliği, IP adresi bağlama — hepsi hazır. Ayrı
 * bir tablo açmak bu makineyi ikiye bölerdi.
 *
 * Ayrım mevcut "type" kolonundadır: physical | aggregated. Aşağıdaki alanlar
 * yalnızca aggregated satırlarda doludur; fiziksel portta null kalırlar ve
 * formda hiç çizilmezler (PoE alanında olduğu gibi).
 *
 * aggregate_port_id ise tersidir: fiziksel portun hangi ae'ye üye olduğu.
 * Cihazda karşılığı "ether-options ieee-802.3ad bundle"dır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            // Üyelik: fiziksel port -> ae arayüzü (ikisi de switch_ports'ta).
            $table->foreignId('aggregate_port_id')->nullable()->after('type')
                ->constrained('switch_ports')->nullOnDelete();

            // Boş = LACP yok (statik LAG). Junos: lacp active | passive.
            $table->string('lacp_mode', 10)->nullable()->after('aggregate_port_id');
            $table->string('lacp_periodic', 10)->nullable()->after('lacp_mode');

            $table->unsignedTinyInteger('minimum_links')->nullable()->after('lacp_periodic');
            $table->string('link_speed', 10)->nullable()->after('minimum_links');

            $table->index('aggregate_port_id');
        });
    }

    public function down(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->dropForeign(['aggregate_port_id']);
            $table->dropColumn(['aggregate_port_id', 'lacp_mode', 'lacp_periodic', 'minimum_links', 'link_speed']);
        });
    }
};
