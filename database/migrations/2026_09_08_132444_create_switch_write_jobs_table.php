<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuyruğa alınmış cihaz yazmalarının DURUMU.
 *
 * Bir cihaz yazması ELS switchte 45-60 saniye sürüyor ve HTTP isteğini o
 * kadar tutuyordu; sayfa kilitleniyordu. Yazma artık kuyruğa alınıyor,
 * kullanıcı beklemiyor ve sonucu buradan öğreniyor.
 *
 * NEDEN AYRI BİR TABLO: device_operations bir DENETİM KAYDIDIR — olmuş bir
 * şeyin değişmez kaydı. Bir işin ise yaşam döngüsü var (kuyrukta, çalışıyor,
 * bitti). İkisini karıştırmak denetim kaydını değişebilir yapardı.
 *
 * ARGÜMANLAR BURADA DURMAZ. Yazma argümanları parola, community ve anahtar
 * taşıyabiliyor; kayıt dışa aktarılabilir olduğu için sır oraya girmemeli.
 * Argümanları yalnızca kuyruktaki iş taşır (Redis, geçici).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_write_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            /*
             * İşi başlatan kullanıcı. Kullanıcı silinse de kayıt kalır:
             * "kim yaptı" sorusunun cevabı kaybolmamalı.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Kullanıcıya gösterilen özet: "VLAN ofis eklendi" gibi.
            $table->string('summary');

            $table->string('status', 16)->default('queued');

            /*
             * İsteğin kimliği İŞLE BİRLİKTE taşınır.
             *
             * Etkinlik kaydı "kim, neden, nereden" bilgisini istekten
             * okuyor; kuyrukta istek yok. Cihazın kabul ettiği FARK ise
             * ancak iş bitince biliniyor. İkisini birleştirmek için gerekçe
             * ve IP burada bekler — yoksa cihazdaki değişikliğin kaydı
             * sahipsiz kalırdı.
             */
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();

            /*
             * Sonuç. Cihazın kabul ettiği fark MASKELENEREK yazılır; hata
             * mesajı cihazdan geldiği gibi durur.
             */
            $table->text('message')->nullable();
            $table->text('diff')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Ekran "bu switchin son işlemleri"ni sorar.
            $table->index(['switch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_write_jobs');
    }
};
