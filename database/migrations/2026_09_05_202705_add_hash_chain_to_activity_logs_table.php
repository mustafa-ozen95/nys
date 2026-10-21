<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etkinlik kaydını değiştirilemez kılan hash zinciri.
 *
 * Her satır, kendinden önceki satırın özetini içerir; kendi özeti de bir
 * sonrakinin içine girer. Bir satır sonradan değiştirilir ya da silinirse
 * ondan sonraki BÜTÜN özetler tutmaz ve doğrulama kırılmanın tam yerini
 * söyler.
 *
 * Özet düz SHA değil, gizli anahtarla üretilen HMAC-SHA256'dır. Düz özet
 * kullanılsaydı veritabanına yazabilen biri satırı değiştirip zinciri
 * baştan hesaplayabilirdi; anahtar olmadan bunu yapamaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            // 64 karakter: sha256'nın onaltılık gösterimi.
            $table->char('hash', 64)->nullable()->after('ip_address');
            $table->char('previous_hash', 64)->nullable()->after('hash');

            // Doğrulama zinciri sırayla yürür; kırılan yeri aramak hızlı olmalı.
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['hash']);
            $table->dropColumn(['hash', 'previous_hash']);
        });
    }
};
