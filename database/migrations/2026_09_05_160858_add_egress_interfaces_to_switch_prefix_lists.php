<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bu prefix list hangi arayüzlerin ÇIKIŞINDA bekçilik ediyor.
 *
 * Yaygın yapı şu: uplink çıkışına bir firewall filtresi bağlanır, filtre
 * "kaynağı şu prefix list'te olanı kabul et, kalanı düşür" der.
 *
 *   filter koruma {
 *       term list     { from { source-prefix-list { koru; } } then accept; }
 *       term deny-any { then discard; }
 *   }
 *
 * Sonuç: bir blok o listede DEĞİLSE o uplinkten dışarı çıkamaz. Anons
 * listesine girmiş olması yetmez — anons edilir ama trafiği geçmez ve bu
 * sessiz bir arızadır.
 *
 * Değer cihazdan ÖĞRENİLİR (firewall + arayüz bağlamaları okunur), elle
 * işaretlenmez: kullanıcı hangi listenin bekçi olduğunu bilmek zorunda
 * kalmamalı ve cihazda değişirse panel kendiliğinden düzelmelidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_prefix_lists', function (Blueprint $table): void {
            $table->json('egress_interfaces')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('switch_prefix_lists', function (Blueprint $table): void {
            $table->dropColumn('egress_interfaces');
        });
    }
};
