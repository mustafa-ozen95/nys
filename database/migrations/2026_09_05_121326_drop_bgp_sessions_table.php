<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elle girilen BGP oturumu envanterini kaldırır.
 *
 * Bu tablo cihazla hiçbir zaman eşitlenmedi: "state" kolonu ekranda
 * gösteriliyordu ama hiçbir iş yazmıyordu, yani panel kalıcı olarak seeder'dan
 * gelen bir durumu gerçek diye sunuyordu. Yerini cihazdan okunan
 * bgp_groups / bgp_group_families / bgp_neighbors aldı.
 *
 * Geri alma tabloyu yeniden yaratır ama içeriğini geri getiremez; zaten
 * cihazda karşılığı olmayan bir veriydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bgp_sessions');
    }

    public function down(): void
    {
        Schema::create('bgp_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();
            $table->string('peering_network', 45)->nullable();
            $table->unsignedTinyInteger('peering_prefix_length')->nullable();
            $table->string('local_address', 45)->nullable();
            $table->string('neighbor_address', 45);
            $table->string('peer_asn', 20);
            $table->string('local_asn', 20);
            $table->string('description')->nullable();
            $table->string('state', 20)->default('unknown');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'neighbor_address']);
        });
    }
};
