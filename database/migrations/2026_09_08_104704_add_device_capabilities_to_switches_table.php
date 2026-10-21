<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazın ÖLÇÜLMÜŞ yetenekleri.
 *
 * Bir alanın kabul ettiği değerler modele göre değişiyor (EX2300 "speed 1g"
 * ve "instance-type forwarding" reddediyor, EX3200 ikisini de kabul ediyor)
 * ve sürüm tablosuyla tahmin edilmiyor — cevabı cihaz veriyor.
 *
 * Sütun NULL kalabilir: hiç ölçülmemiş cihazda seçenek kısıtlanmaz
 * (bkz. App\Services\Capabilities\DeviceCapabilities).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->json('device_capabilities')->nullable()->after('schema_profile');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('device_capabilities');
        });
    }
};
