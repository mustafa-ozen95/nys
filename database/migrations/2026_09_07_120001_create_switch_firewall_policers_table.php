<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazdaki firewall policer'ları.
 *
 * Policer, filtre teriminin "then policer <ad>" ile çağırdığı hız sınırıdır
 * ve cihazda filtrenin DEĞİL, "firewall" düğümünün doğrudan altındadır. Panel
 * onu hiç okumuyordu: terimde policer gören okuyucu filtreyi salt okunur
 * ilan ediyor, kullanıcı da filtreyi hiç düzenleyemiyordu.
 *
 * Cihazda ölçüldü (test1, commit check):
 *   firewall policer P { if-exceeding { bandwidth-limit 1m; burst-size-limit 15k; } then { discard; } }
 *
 * bandwidth_limit ile bandwidth_percent birbirini dışlar; cihaz ikisini
 * birlikte kabul etmez, form da önce söyler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_firewall_policers', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('name');

            // Policer sayacı filtre başına mı tutulsun.
            $table->boolean('filter_specific')->default(false);

            // if-exceeding
            $table->string('bandwidth_limit', 32)->nullable();
            $table->unsignedTinyInteger('bandwidth_percent')->nullable();
            $table->string('burst_size_limit', 32)->nullable();

            // then
            $table->boolean('is_discard')->default(false);
            $table->string('forwarding_class')->nullable();
            $table->string('loss_priority', 16)->nullable();

            // Panelin tanımadığı çocuk düğümler, ham XML.
            $table->json('residual_xml')->nullable();

            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_firewall_policers');
    }
};
