<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arayüzde AYARLANAN duplex ("ether-options link-mode").
 *
 * Var olan "duplex" kolonu cihazın o anda ÖLÇTÜĞÜ değerdir ve eşitleme
 * yazar; ikisi karıştırılamaz — tıpkı speed_config (ayarlanan) ile
 * speed_mbps (ölçülen) gibi.
 *
 * Boş bırakmak "cihaz kendi seçsin" demektir; auto-negotiation devrededir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->string('duplex_config', 10)->nullable()->after('speed_config');
        });
    }

    public function down(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->dropColumn('duplex_config');
        });
    }
};
