<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VLAN açıklaması.
 *
 * Junos "set vlans <ad> description" kabul eder ve bu satır cihazın kendi
 * üzerinde de okunur: terminale düşen biri VLAN'ın ne işe yaradığını panele
 * bakmadan görür. Panelde tutulup cihaza yazılmayan bir açıklama, cihazda
 * çalışan için yok demektir.
 *
 * Alan cihazdan OKUNUR da; yalnızca yazılsaydı bir sonraki eşitlemede
 * panelde boş görünür ve kullanıcı yazdığının kaybolduğunu sanırdı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vlans', function (Blueprint $table): void {
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('vlans', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
