<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fırtına denetimi (storm control).
 *
 * Yayın fırtınası bir anahtarı dakikalar içinde kullanılamaz hâle getirir ve
 * panel bugüne kadar bu ayarı hiç yönetmiyordu — cihazda vardı, panelde yoktu.
 *
 * Ayarın YERİ şema ailesine bağlıdır ve ikisi birbirinin yerine geçmez;
 * test1'de ölçüldü:
 *   ELS öncesi : ethernet-switching-options storm-control interface ...   → KABUL
 *   ELS        : forwarding-options storm-control-profiles ...            → syntax error
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_storm_controls', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // "all" ya da bir port adı (ge-0/0/5). Cihaz birim ekler (.0).
            $table->string('interface_name', 64);

            // Yüzde (level) ya da kbps (bandwidth); ikisi birlikte verilmez.
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedInteger('bandwidth')->nullable();

            // Hangi trafik türü denetim DIŞINDA bırakılıyor.
            $table->boolean('no_broadcast')->default(false);
            $table->boolean('no_unknown_unicast')->default(false);
            $table->boolean('no_multicast')->default(false);
            $table->boolean('no_registered_multicast')->default(false);
            $table->boolean('no_unregistered_multicast')->default(false);

            $table->json('residual_xml')->nullable();

            $table->timestamps();
            $table->unique(['switch_id', 'interface_name']);
        });

        Schema::table('switches', function (Blueprint $table): void {
            /*
             * Kapsayıcı seviyesindeki tek ayar: eşik aşılınca port kapatılsın
             * mı? Arayüz başına değil, cihaz başınadır.
             */
            $table->boolean('storm_control_shutdown')->default(false)->after('aggregated_device_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_storm_controls');

        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('storm_control_shutdown');
        });
    }
};
