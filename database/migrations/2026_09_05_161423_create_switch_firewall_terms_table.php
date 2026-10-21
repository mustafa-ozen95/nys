<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firewall filtresinin terimleri.
 *
 * Junos'ta terim SIRASI anlamlıdır: ilk eşleşen kazanır ve sonuncusu
 * genellikle "discard"tır. Kabul terimini reddin ARKASINA koymak filtreyi
 * sessizce etkisizleştirir; bu yüzden position saklanır ve yazarken korunur.
 *
 * Koşullar liste olarak tutulur çünkü Junos hepsini tekrarlanabilir yazar:
 * bir terimde birden çok kaynak adres, protokol ya da port bulunabilir ve
 * aralarında VEYA ilişkisi vardır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_firewall_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_firewall_filter_id')
                ->constrained('switch_firewall_filters')
                ->cascadeOnDelete();

            $table->string('name');
            $table->unsignedSmallInteger('position');

            // from koşulları
            $table->json('source_addresses')->nullable();
            $table->json('destination_addresses')->nullable();
            $table->json('source_prefix_lists')->nullable();
            $table->json('destination_prefix_lists')->nullable();
            $table->json('protocols')->nullable();
            $table->json('ports')->nullable();
            $table->json('source_ports')->nullable();
            $table->json('destination_ports')->nullable();
            $table->json('icmp_types')->nullable();

            // then: accept | discard | reject. Boşsa terimin eylemi yoktur.
            $table->string('action', 10)->nullable();
            $table->string('count_name')->nullable();
            $table->boolean('is_logged')->default(false);

            $table->timestamps();

            $table->unique(['switch_firewall_filter_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_firewall_terms');
    }
};
