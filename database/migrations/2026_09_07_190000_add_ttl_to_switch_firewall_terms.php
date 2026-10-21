<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firewall teriminin "from ttl" koşulu.
 *
 * İstenmeyen trafiği ayıklamak için paket boyuyla birlikte tek terimde
 * kullanılabilir (örnek değerler):
 *
 *     firewall family inet filter koru term udp-filtre from packet-length 100-120
 *     firewall family inet filter koru term udp-filtre from protocol udp
 *     firewall family inet filter koru term udp-filtre from ttl 10-20
 *     firewall family inet filter koru term udp-filtre then policer udp-filtre-limit
 *
 * Panel bu koşulu modellemiyordu: satır ham XML olarak korunuyor ama panelden
 * ne görülüyor ne değiştirilebiliyordu. Cihazda "packet-length" ile yan yana
 * duran normal bir "from" koşulu olduğu test1'de doğrulandı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_firewall_terms', function (Blueprint $table): void {
            $table->json('ttls')->nullable()->after('packet_lengths');
        });
    }

    public function down(): void
    {
        Schema::table('switch_firewall_terms', function (Blueprint $table): void {
            $table->dropColumn('ttls');
        });
    }
};
