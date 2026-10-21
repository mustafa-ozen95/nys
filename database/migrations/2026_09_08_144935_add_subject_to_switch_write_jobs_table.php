<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuyruktaki yazma HANGİ KAYNAĞA ait?
 *
 * Yazma kuyruğa alınınca ekran hemen dönüyor ve kullanıcı aynı porta ikinci
 * bir değişiklik gönderebiliyordu. İki yazma sırayla çalışır (kuyruk
 * çakışmayı önlüyor) ama İKİNCİSİ BİRİNCİNİN SONUCUNU GÖRMEDEN hazırlanmış
 * olur: kullanıcı henüz gerçekleşmemiş bir duruma bakarak karar verir.
 *
 * Kaynak bilinince ekran onu kilitleyebilir ve sunucu ikinci isteği
 * reddedebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_write_jobs', function (Blueprint $table): void {
            $table->nullableMorphs('subject');
        });
    }

    public function down(): void
    {
        Schema::table('switch_write_jobs', function (Blueprint $table): void {
            $table->dropMorphs('subject');
        });
    }
};
