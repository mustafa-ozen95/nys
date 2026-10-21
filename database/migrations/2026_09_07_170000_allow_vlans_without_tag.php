<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VLAN etiketi ZORUNLU DEĞİLDİR.
 *
 * Junos'ta "vlans <ad>" vlan-id taşımadan da geçerlidir ve böyle bir VLAN'ın
 * üzerinde adres de durabilir:
 *
 *     set vlans V27 l3-interface vlan.28
 *     set vlans V28 l3-interface vlan.29
 *     set vlans V29 l3-interface vlan.30
 *
 * Panel etiketsiz VLAN'ı okurken ATLIYORDU: bu VLAN'lar envanterde hiç
 * görünmüyor, üzerlerindeki adresler sahipsiz kalıyor ve o VLAN'lara üye
 * portlar tanımadığı bir VLAN'a işaret ediyordu. Etiketsiz VLAN'ın tek
 * başına, l3-interface'li ve port üyeli hâlleri test1 üzerinde commit check
 * ile doğrulandı; üçü de kabul ediliyor.
 *
 * Etiket tekilliği KORUNUR: cihazda iki VLAN aynı etiketi taşıyamaz. Boş
 * etiket tekillik kısıtını ihlal etmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vlans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('vlan_tag')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vlans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('vlan_tag')->nullable(false)->change();
        });
    }
};
