<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihaza atılan config'lerin geçmişi.
 *
 * "Kim, hangi cihaza, ne zaman, neyi yazdı" sorusunun cevabı burada durur.
 * Cihazın kendi commit geçmişi yalnızca paylaşılan NETCONF hesabını görür;
 * gönderilen metnin tamamı ise yalnızca panelde vardır.
 *
 * Gövde ŞİFRELİ saklanır: şablon çıktısı root parolası, admin parolası ve
 * yedek sunucusunun FTP kimliğini içerir. Elle yapıştırılan metin de
 * pekâlâ parola taşıyabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_pushes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Envanterdeki cihaz. Dış cihazlarda boştur; hedef adres yine yazılır.
            $table->foreignId('switch_id')->nullable()->constrained('switches')->nullOnDelete();

            $table->string('target_host', 45);
            $table->string('target_label');

            // set | merge | override
            $table->string('mode', 20);

            // manual | template
            $table->string('source', 20);

            $table->text('body');
            $table->text('diff')->nullable();
            $table->boolean('committed')->default(false);
            $table->text('warnings')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['switch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_pushes');
    }
};
