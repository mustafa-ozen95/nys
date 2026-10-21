<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ae limiti": chassis aggregated-devices ethernet device-count.
 *
 * Bu sayı bir üst sınır değil, KAÇ TANE ae ARAYÜZÜNÜN YARATILACAĞIDIR.
 * device-count 4 verildiğinde cihaz ae0, ae1, ae2 ve ae3'ü — hiç
 * yapılandırılmamış olsalar bile — gerçek arayüz olarak oluşturur.
 *
 * Sayı düşürüldüğünde üstteki arayüzler SESSİZCE yok olur: cihazda ölçüldü,
 * device-count 4'ten 2'ye indirildiğinde yapılandırması duran ae3 ortadan
 * kalktı ve commit hiçbir uyarı vermedi. Bu yüzden koruma panelde durur.
 *
 * Cihazdan eşitleme doldurur; aralık 1..111 (cihazın söylediği).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->unsignedSmallInteger('aggregated_device_count')->nullable()->after('autonomous_system');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('aggregated_device_count');
        });
    }
};
