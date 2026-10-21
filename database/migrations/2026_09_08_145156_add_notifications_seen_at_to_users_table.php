<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kullanıcının bildirimleri en son ne zaman gördüğü.
 *
 * Bildirimler HERKESE görünür: cihazda olan bir şey, onu başlatan kişinin
 * değil ağın olayıdır. Ama "okundu" kişiye özeldir — biri baktı diye
 * ötekinin sayacı sıfırlanmamalı.
 *
 * Null = hiç bakmamış. O durumda geçmişin tamamı okunmamış sayılmaz;
 * akış zaten son N saatle sınırlı (bkz. NotificationFeed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('notifications_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notifications_seen_at');
        });
    }
};
