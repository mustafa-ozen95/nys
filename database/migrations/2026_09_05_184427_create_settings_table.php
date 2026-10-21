<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Çalışma zamanında değiştirilebilen ayarlar.
 *
 * Anahtar, üzerine bindirilecek config yolunun kendisidir ("zabbix.url").
 * Böylece config('zabbix.url') diyen kırk küsur çağrı yeri değişmeden kalır;
 * ayar silindiğinde de uygulama config dosyasındaki varsayılana döner.
 *
 * Değer JSON tutulur: bool, int ve string aynı kolonda tipini kaybetmeden
 * saklanır. Gizli alanlar (API jetonu) şifreli metin olarak yazılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();

            // Kimin değiştirdiği etkinlik kaydında da durur; burada tutulması
            // ayar ekranında "en son kim dokundu" sorusunu tek sorguyla
            // cevaplayabilmek içindir.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
