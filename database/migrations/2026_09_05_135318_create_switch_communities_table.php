<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BGP community tanımları (policy-options community).
 *
 * Politika terimi bir community'ye atıfta bulunuyorsa o community ÖNCEDEN
 * tanımlı olmalıdır; aksi hâlde commit reddedilir:
 * "Policy error: topluluk-a community referenced (in term clear) but not defined"
 * (gerçek cihazda görüldü). Politika formundaki community listesi bu yüzden
 * serbest metin değil, buradan doldurulur.
 *
 * members bir listedir: bir community birden çok değer taşıyabilir ve joker
 * kabul edilir ("*:666").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_communities', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('name');
            $table->json('members');

            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_communities');
    }
};
