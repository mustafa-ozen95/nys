<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bir BGP grubunun taşıdığı adres aileleri ve her ailenin prefix sınırı.
 *
 * Ayrı tablodur çünkü Junos aynı grupta hem inet hem inet6 taşımaya ve HER
 * BİRİNE AYRI prefix-limit vermeye izin verir:
 *
 *   set protocols bgp group X family inet  any prefix-limit maximum 200
 *   set protocols bgp group X family inet6 any prefix-limit maximum 50
 *
 * Gruba tek bir "maximum" kolonu koymak bu yapıyı temsil edemezdi ve cihazdan
 * okunan ikinci aile sessizce düşerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bgp_group_families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bgp_group_id')->constrained('bgp_groups')->cascadeOnDelete();

            // inet | inet6
            $table->string('family', 6);

            $table->unsignedInteger('prefix_limit_maximum')->nullable();

            /*
             * teardown: sınır aşılınca oturumu düşürür. Eşik yüzdedir ve
             * verilmeyebilir (Junos o zaman yalnızca sınırda düşürür).
             */
            $table->boolean('teardown')->default(false);
            $table->unsignedTinyInteger('teardown_threshold')->nullable();

            /*
             * Oturum düştükten sonra ne kadar bekleneceği. Junos'ta iki ayrı
             * durum var ve tek kolonla ayrılamazlar:
             *   idle-timeout forever  -> elle müdahale edilene kadar kapalı
             *   idle-timeout <dakika> -> süre sonunda kendiliğinden dener
             * Cihaz "forever"ı boş bir <idle-timeout/> elemanı olarak döner.
             */
            $table->boolean('idle_timeout_forever')->default(false);
            $table->unsignedSmallInteger('idle_timeout_minutes')->nullable();

            $table->timestamps();

            $table->unique(['bgp_group_id', 'family']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgp_group_families');
    }
};
