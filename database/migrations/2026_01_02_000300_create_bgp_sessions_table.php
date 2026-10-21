<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omurga switchlerdeki BGP komşulukları.
 *
 * Kayıtlar şimdilik elle girilir; ileride cihazdan eşitlenecek. Bu yüzden
 * eşitlemenin dolduracağı alanlar (durum, uptime, alınan prefix sayısı)
 * şimdiden ayrılmıştır ama boş bırakılabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bgp_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('group_name')->nullable();
            $table->string('neighbor_address', 45);
            $table->string('peer_asn', 20);
            $table->string('local_asn', 20);
            $table->string('description')->nullable();
            $table->string('state', 20)->default('idle');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['switch_id', 'neighbor_address']);
            $table->index('state');
            $table->index('peer_asn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgp_sessions');
    }
};
