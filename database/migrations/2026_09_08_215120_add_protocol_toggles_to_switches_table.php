<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erişim switchlerinde yaygın üç protokol anahtarı.
 *
 * Erişim switchlerinin yapılandırmasında sık bulunurlar ve panel onları
 * göstermiyordu — cihazda duran ama panelde olmayan her satır, "panelden
 * yönetiyoruz" iddiasını zayıflatır:
 *
 *   set protocols lldp interface all
 *   set protocols lldp-med interface all
 *   set protocols igmp-snooping vlan all
 *
 * Liste olarak saklanır çünkü cihazda da listedir: "all" tek üyeli bir
 * listedir, gerçek arayüz adları da aynı yere yazılır. NULL = protokol
 * cihazda TANIMLI DEĞİL; boş liste değil.
 *
 * "dcbx" modellenmedi: erişim switchlerinde kullanılmıyor ve kullanılmayan
 * bir ayarı yönetmek, doğrulanmamış bir yol açmaktır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->json('lldp_interfaces')->nullable()->after('rstp_bpdu_block_on_edge');
            $table->json('lldp_med_interfaces')->nullable()->after('lldp_interfaces');
            $table->json('igmp_snooping_vlans')->nullable()->after('lldp_med_interfaces');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn(['lldp_interfaces', 'lldp_med_interfaces', 'igmp_snooping_vlans']);
        });
    }
};
