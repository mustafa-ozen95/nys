<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "user" rolü "teknik" olur.
 *
 * Yerleşik roller birer yetki şablonuna karşılık gelir: yönetici, ağ ekibi
 * ve teknik ekip. "Kullanıcı" adı hiçbir şey söylemiyordu; "teknik" kısıtlı
 * yetkili şablonun adıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'user')->update(['role' => 'teknik']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'teknik')->update(['role' => 'user']);
    }
};
