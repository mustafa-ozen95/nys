<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Özetin hangi biçimle üretildiği.
 *
 * Özet, satırın alanlarının belirli bir SIRAYLA birleştirilmesinden üretilir.
 * O sıraya yeni bir alan eklemek (gerekçe gibi) eski satırların özetini
 * geçersiz kılar ve zincir kırık görünür.
 *
 * Sürüm numarası bunu çözer: her satır kendi biçimini söyler, doğrulama da
 * o biçimle hesaplar. Böylece ileride yeni bir alan eklendiğinde geçmiş
 * doğrulanabilir kalır ve zinciri "sıfırlamak" gerekmez — sıfırlamak,
 * zincirin var olma sebebini ortadan kaldırır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('chain_version')->default(1)->after('previous_hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropColumn('chain_version');
        });
    }
};
