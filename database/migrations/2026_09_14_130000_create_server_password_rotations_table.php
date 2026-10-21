<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sunucu parolası değiştirme turları.
 *
 * Switch turlarıyla AYNI ŞEKİLDE kurulur (switch_password_rotations) ve
 * bilinçli olarak ayrı bir tablodur: iki cihaz sınıfının turları aynı satırı
 * paylaşsaydı, "hangi sunucu" sorusunun cevabı iki ayrı sütuna dağılır ve
 * yabancı anahtar kısıtı ikisinden birinde kurulamazdı.
 *
 * Tur yarıda kalırsa makinede hangi parolanın geçerli olduğunu ve kurtarma
 * hesabının durup durmadığını yalnızca bu kayıt söyler. Kurtarma parolası
 * şifreli saklanır: panelin makineye geri dönebilmesi için gereken tek şeydir
 * ve ekranda hiç basılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_password_rotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            // Turu başlatan kişi. Kullanıcı silinse de kayıt kalır.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->index();
            $table->string('stage', 32)->nullable();

            $table->string('recovery_username')->nullable();
            $table->text('recovery_password')->nullable();

            /*
             * Makineye yazılacak yeni parola. YAZMADAN ÖNCE buraya konur:
             * yazma ile doğrulama arasında süreç ölürse parola yalnızca
             * bellekte kalmış olurdu ve makine kendi parolasını bilmediğimiz
             * bir makineye dönerdi. Tur başarıyla bitince silinir.
             */
            $table->text('new_password')->nullable();

            /*
             * Kurtarma hesabı makinede hâlâ duruyor mu. Duruyorsa tam yetkili
             * ikinci bir hesap açık demektir; ekran bunu öne çıkarır ve
             * temizlenene kadar uyarı verir.
             */
            $table->boolean('recovery_active')->default(false)->index();

            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_password_rotations');
    }
};
