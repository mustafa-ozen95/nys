<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prefix list de "panelin temsil edebildiği yapı" sınırını taşımalı.
 *
 * Firewall filtresi ve politika bu bilgiyi zaten taşıyor. Prefix list'te yoktu
 * ve bir eksik doğuruyordu: Junos'ta bir liste "apply-path" ile DİNAMİK
 * doldurulabiliyor (test1'de commit check ile geçerli olduğu ölçüldü).
 * Panel yalnızca statik girdileri okuduğu için böyle bir listeyi SIFIR GİRDİLİ
 * gösteriyor — oysa kod tabanı boş prefix list'i tehlike sayıyor ("uplink
 * çıkış filtresi boş listeye dayanıyorsa bütün trafiği düşürür"). Kullanıcı
 * olmayan bir tehlikeyi "düzeltmeye" çalışırdı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_prefix_lists', function (Blueprint $table): void {
            $table->boolean('is_manageable')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('switch_prefix_lists', function (Blueprint $table): void {
            $table->dropColumn('is_manageable');
        });
    }
};
