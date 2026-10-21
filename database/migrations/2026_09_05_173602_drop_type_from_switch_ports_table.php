<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNMP ifType kolonunu kaldırır.
 *
 * Değer Zabbix'ten geliyordu ama HİÇBİR YERDE okunmuyordu: ne ekranda, ne
 * mantıkta, ne sorguda. Üstelik arayüz listesinin sahibi NETCONF olduğundan
 * (bkz. .ai/rules/netconf.md) NETCONF'lu switchlerde Zabbix artık satır
 * yazmıyor ve kolon bayatlayacaktı — okunmayan ve bayat bir alan, bir dahaki
 * denetimde "bu ne?" sorusundan başka bir şey üretmez.
 *
 * ifType OKUNMAYA devam ediyor: ethernet olmayan arayüzleri elemek için
 * gerekli (config/zabbix.php → ignored_interface_types). Yalnızca saklanması
 * bırakıldı.
 *
 * Panelin arayüz TÜRÜ ayrımı ayrı bir kolondadır: interface_kind
 * (physical | aggregated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->dropIndex(['switch_id', 'type']);
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->string('type', 50)->default('unknown')->after('description');
            $table->index(['switch_id', 'type']);
        });
    }
};
