<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DHCP snooping ve port güvenliği (ethernet-switching-options
 * secure-access-port).
 *
 * Cihazda ölçüldü (test1, commit check):
 *   secure-access-port { vlan all { examine-dhcp; } }   → KABUL
 *
 * İKİ TABLO, tek tablo değil. Junos'un altındaki iki düğüm birbirine
 * benzemez ve tek satıra sıkıştırıldıklarında her kolon nullable olur,
 * hangi kolonun hangi tür için anlamlı olduğunu da hiçbir şey söylemez:
 *
 *   vlan <ad>      examine-dhcp, arp-inspection, ip-source-guard,
 *                  mac-move-limit <n> action <...>
 *   interface <ad> dhcp-trusted | no-dhcp-trusted,
 *                  mac-limit <n> action <...>, allowed-mac [...]
 *
 * Ortak görünen tek şey "sınır + eylem"dir, onun bile Junos'taki adı farklı
 * (mac-move-limit / mac-limit). Doğrulama da ayrışıyor: VLAN adı bu switchte
 * TANIMLI olmalı, arayüz ise ANAHTARLAMA modunda. Korunan ham satırların
 * (residual_xml) "bilinen çocuk" listesi de iki düğümde farklıdır ve tek
 * tabloda tek listeye düşerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * VLAN kuralları. DHCP snooping buradan açılır: cihaz o VLAN'daki
         * DHCP trafiğini inceler ve bağlama tablosunu kurar. ARP denetimi ve
         * IP kaynak koruması bu tabloya dayanır — snooping olmadan ikisi de
         * anlamsızdır.
         */
        Schema::create('switch_secure_vlans', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // "all" ya da bu switchte tanımlı bir VLAN adı.
            $table->string('vlan_name', 64);

            $table->boolean('examine_dhcp')->default(false);
            $table->boolean('arp_inspection')->default(false);
            $table->boolean('ip_source_guard')->default(false);

            // mac-move-limit <n> action <drop|log|shutdown|none>
            $table->unsignedInteger('mac_move_limit')->nullable();
            $table->string('mac_move_action', 16)->nullable();

            // Panelin tanımadığı çocuk düğümler ham XML olarak korunur.
            $table->json('residual_xml')->nullable();

            $table->timestamps();
            $table->unique(['switch_id', 'vlan_name']);
        });

        /*
         * Arayüz kuralları. "dhcp-trusted" ÜÇ DURUMLUDUR ve boolean'a
         * sıkıştırılamaz:
         *
         *   satır yok        → cihazın varsayılanı (trunk güvenilir,
         *                      erişim portu güvenilmez)
         *   dhcp-trusted     → açıkça güvenilir (yalnızca uplink!)
         *   no-dhcp-trusted  → açıkça güvenilmez
         *
         * Varsayılanı "güvenilmez" diye yazmak, cihazda olmayan bir satırı
         * panelin uydurması olurdu.
         */
        Schema::create('switch_secure_interfaces', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // "all" ya da bir port adı. Cihaz birimi kendisi ekler (ge-0/0/5.0).
            $table->string('interface_name', 64);

            $table->boolean('dhcp_trusted')->nullable();

            // mac-limit <n> action <drop|log|shutdown|none>
            $table->unsignedInteger('mac_limit')->nullable();
            $table->string('mac_limit_action', 16)->nullable();

            // allowed-mac [ ... ]: portta kabul edilen MAC adresleri.
            $table->json('allowed_macs')->nullable();

            $table->json('residual_xml')->nullable();

            $table->timestamps();
            $table->unique(['switch_id', 'interface_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_secure_interfaces');
        Schema::dropIfExists('switch_secure_vlans');
    }
};
