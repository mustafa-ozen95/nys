<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BGP komşuları. Yapılandırma alanları cihazın config'inden, durum alanları
 * "show bgp neighbor" karşılığı RPC'den gelir; ikisi de eşitleme ile yazılır.
 *
 * Durum alanları elle GİRİLMEZ. Eski bgp_sessions tablosunda durum forma
 * konmuştu ve hiçbir iş güncellemiyordu: ekran kalıcı olarak yanlış bilgi
 * gösteriyordu. Burada bu alanlar $fillable dışındadır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bgp_neighbors', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('bgp_group_id')->constrained('bgp_groups')->cascadeOnDelete();

            /*
             * Grup üzerinden de türetilebilirdi ama burada duruyor: komşu
             * adresi cihazda switch genelinde benzersizdir ve bu kısıt ancak
             * switch_id aynı tabloda olursa veritabanında kurulabilir.
             */
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('address', 45);
            $table->string('description')->nullable();

            // Grubun değerini ezer; boşsa grubunki geçerlidir.
            $table->string('peer_asn', 20)->nullable();
            $table->string('local_address', 45)->nullable();

            // ── Buradan aşağısı cihazdan okunur, formda sorulmaz ──
            $table->string('state', 20)->default('unknown');
            $table->unsignedInteger('active_prefixes')->nullable();
            $table->unsignedInteger('received_prefixes')->nullable();
            $table->unsignedInteger('accepted_prefixes')->nullable();
            $table->unsignedInteger('flap_count')->nullable();
            $table->unsignedBigInteger('state_changed_seconds')->nullable();

            $table->timestamps();

            $table->unique(['switch_id', 'address']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgp_neighbors');
    }
};
