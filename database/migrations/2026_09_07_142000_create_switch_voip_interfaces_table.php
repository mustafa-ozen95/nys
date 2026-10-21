<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VoIP VLAN (ses VLAN'ı).
 *
 * IP telefon ile ona bağlı bilgisayar TEK kabloyu paylaşır: telefon
 * trafiği ses VLAN'ında ETİKETLİ, bilgisayarınki portun kendi access
 * VLAN'ında ETİKETSİZ gider. Ayar cihazda vardı, panelde yoktu.
 *
 * Ayarın YERİ şema ailesine bağlıdır:
 *   ELS öncesi : ethernet-switching-options voip interface <ad>
 *   ELS        : switch-options voip interface <ad>
 *
 * ELS öncesi şema test1'de (12.3R12.4) commit check ile doğrulandı; cihaz
 * yapıyı KABUL etti, reddetme yalnızca semantikti:
 * "Interface ge-0/0/20.0 not found or does not have ethernet-switching family".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_voip_interfaces', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            /*
             * Junos'un yazdığı ad BİRİMİ de taşır (ge-0/0/5.0). "all" ve
             * "access-ports" cihazın kabul ettiği sanal adlardır; port
             * tablosunda karşılıkları yoktur.
             */
            $table->string('interface_name', 64);

            /*
             * ELS öncesinde ses VLAN'ı ADIYLA gösterilir; VLAN'ın kimliği
             * cihazda adıdır (bkz. .ai/rules/junos.md → VLAN).
             */
            $table->string('vlan_name', 64)->nullable();

            // class-of-service sınıfı; boş bırakılırsa cihaz kendi sınıfını uygular.
            $table->string('forwarding_class', 64)->nullable();

            // Panelin tanımadığı çocuk düğümler ham hâliyle korunur.
            $table->json('residual_xml')->nullable();

            $table->timestamps();
            $table->unique(['switch_id', 'interface_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_voip_interfaces');
    }
};
