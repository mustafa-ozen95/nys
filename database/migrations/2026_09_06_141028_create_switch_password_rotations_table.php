<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihaz parolası değiştirme turları.
 *
 * Tur yarıda kalırsa cihazda hangi parolanın geçerli olduğunu ve kurtarma
 * hesabının durup durmadığını yalnızca bu kayıt söyler. Kurtarma parolası
 * şifreli saklanır: panelin cihaza geri dönebilmesi için gereken tek şeydir
 * ve ekranda hiç basılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_password_rotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // Turu başlatan kişi. Kullanıcı silinse de kayıt kalır.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->index();
            $table->string('stage', 32)->nullable();

            $table->string('recovery_username')->nullable();
            $table->text('recovery_password')->nullable();

            /*
             * Cihaza yazılacak yeni parola. Commit'ten ÖNCE buraya yazılır:
             * yazma ile doğrulama arasında süreç ölürse parola yalnızca
             * bellekte kalmış olurdu ve cihaza bir daha girilemezdi.
             * Tur başarıyla bitince silinir.
             */
            $table->text('new_password')->nullable();

            /*
             * Kurtarma hesabı cihazda hâlâ duruyor mu. Duruyorsa tam yetkili
             * ikinci bir hesap açık demektir; ekran bunu öne çıkarır ve
             * temizlenene kadar uyarı verir.
             */
            $table->boolean('recovery_active')->default(false)->index();

            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['switch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_password_rotations');
    }
};
