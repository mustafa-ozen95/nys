<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BGP komşuluğu bir peering bloğu üzerinde kurulur: /30'un bir ucu biz,
 * diğeri karşı taraftır. Yalnızca komşu adresini tutmak eksikti.
 *
 * "Grup" (JunOS peer group) alanı kaldırıldı; bu kayıtta kullanılmıyordu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgp_sessions', function (Blueprint $table): void {
            $table->string('peering_network', 45)->nullable()->after('switch_id');
            $table->unsignedTinyInteger('peering_prefix_length')->nullable()->after('peering_network');
            $table->string('local_address', 45)->nullable()->after('peering_prefix_length');

            $table->dropColumn('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('bgp_sessions', function (Blueprint $table): void {
            $table->string('group_name')->nullable()->after('switch_id');

            $table->dropColumn(['peering_network', 'peering_prefix_length', 'local_address']);
        });
    }
};
