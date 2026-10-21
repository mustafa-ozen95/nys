<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazdan okunan anlık trafik örnekleri.
 *
 * Geçmiş grafiği normalde izleme sisteminden gelir; onun kullanılmadığı ya da
 * erişilemediği durumda cihaz yalnızca ANLIK hız verir, geçmiş vermez. Panel
 * o örnekleri buraya yazar ve grafik buradan çizilir — böylece izleme sistemi
 * devre dışıyken de grafik özelliği kapanmaz, ileriye doğru dolar.
 *
 * Zabbix kullanılırken bu tabloya hiç yazılmaz; orada geçmiş zaten vardır.
 *
 * Saklama süresi ayarlardan gelir ve bakım işi eskiyeni siler; tablo
 * sınırsız büyümez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_port_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_port_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();

            // Grafik tek portun bir zaman aralığını ister; silme ise yalnızca
            // zamana bakar.
            $table->index(['switch_port_id', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_port_samples');
    }
};
