<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ELS'te port güvenliğinin iki alanı VLAN KAPSAMINDA yaşar.
 *
 *   dhcp-trusted → vlans <v> forwarding-options dhcp-security group <g>
 *                    { overrides trusted; interface <if>; }
 *   allowed-mac  → vlans <v> switch-options interface <if> static-mac <mac>
 *
 * ELS öncesinde ikisi de switch genelindedir (secure-access-port interface
 * <if>) ve VLAN sorulmaz. Alan bu yüzden NULLABLE: pre-ELS satırlarda boş
 * kalır, ELS satırlarda o iki alan verildiğinde zorunludur.
 *
 * Biçimlerin tamamı test4'te (EX2300-24T, 24.4R2-S4) commit check ile
 * doğrulandı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_secure_interfaces', function (Blueprint $table): void {
            $table->string('vlan_name')->nullable()->after('interface_name');
        });
    }

    public function down(): void
    {
        Schema::table('switch_secure_interfaces', function (Blueprint $table): void {
            $table->dropColumn('vlan_name');
        });
    }
};
