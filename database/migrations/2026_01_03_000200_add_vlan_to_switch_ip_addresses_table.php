<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bir IP adresi çoğu zaman fiziksel portta değil, VLAN'ın L3 arayüzünde
 * durur (vlan.25). İkisi birbirini dışlar: VLAN seçiliyse port sorulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ip_addresses', function (Blueprint $table): void {
            $table->foreignId('vlan_id')->nullable()->after('switch_port_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('switch_ip_addresses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vlan_id');
        });
    }
};
