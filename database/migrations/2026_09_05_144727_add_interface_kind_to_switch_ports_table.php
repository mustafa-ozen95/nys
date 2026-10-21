<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arayüzün panel açısından türü: fiziksel port mu, toplama arayüzü (ae) mi.
 *
 * Mevcut "type" kolonu KULLANILAMAZ: orada Zabbix'in SNMP ifType değeri
 * duruyor ("ethernetCsmacd", "6"). İkisi farklı sorulardır ve aynı kolonda
 * tutulmaları birbirini ezerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->string('interface_kind', 12)->default('physical')->after('type');
            $table->index(['switch_id', 'interface_kind']);
        });
    }

    public function down(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->dropIndex(['switch_id', 'interface_kind']);
            $table->dropColumn('interface_kind');
        });
    }
};
