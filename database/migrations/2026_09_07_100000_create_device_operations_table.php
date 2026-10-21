<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihaz işlemlerinin YAPISAL kaydı — özellikle başarısız olanların.
 *
 * Cihaz bir yazmayı reddettiğinde kullanıcının gördüğü toast, sayfa
 * kapanınca iz bırakmaz. Hangi belge gönderildi, cihaz tam olarak ne dedi,
 * hangi satırda düştü, hangi adımdaydı — bunlar burada saklanır. Okuma
 * hataları da yalnızca dosya günlüğüne düşmez, uyarılar (severity=warning)
 * da controller'larda atılmaz; hepsi bu tabloya yazılır.
 *
 * Neden activity_logs'a bir sütun değil de AYRI TABLO: activity_logs hash
 * zinciriyle korunuyor ve her yeni alan zincir sürümünü artırmayı gerektirir.
 * Ayrıntı buraya, OLAYIN KENDİSİ zincire yazılır (ActivityChannel::Sync ve
 * Config) — böylece başarısız bir denemeyi görünmez yapmak için iki yeri
 * birden değiştirmek gerekir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Envanterde olmayan cihaz da olabilir (kurulum yüklemesi).
            $table->foreignId('switch_id')->nullable()->constrained('switches')->nullOnDelete();
            $table->string('target', 128);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // sync | write | rollback | push | measure | probe | rotate | read
            $table->string('operation', 24)->index();

            // load | check | commit | confirm | read | connect | lock
            $table->string('stage', 24)->nullable();

            // failed | warning | ok
            $table->string('status', 12)->index();

            // rpc | timeout | closed | auth | lock | framing | unknown
            $table->string('kind', 16)->nullable();

            $table->string('summary', 255);

            // Cihazın kendi cümlesi, olduğu gibi.
            $table->text('device_message')->nullable();

            /*
             * Her rpc-error'un severity/tag/path/daemon alanları. Tek dizeye
             * indirilirse "hangi satırda" bilgisi kaybolur.
             */
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();

            // Gönderilen belge ve cihazın ürettiği fark — ikisi de MASKELİ.
            $table->longText('document')->nullable();
            $table->longText('diff')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_operations');
    }
};
