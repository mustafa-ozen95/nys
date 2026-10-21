<?php

declare(strict_types=1);

use App\Enums\PanelMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panelin ayrıntı düzeyi kullanıcı başına saklanır.
 *
 * Yeni kullanıcılar BASİT modda başlar: günlük işte alanların çoğu
 * kullanılmıyor ve cihazın varsayılanıyla ilerleniyor.
 *
 * MEVCUT kullanıcılar GELİŞMİŞ moda alınır. Çalışan bir panelden alanları
 * habersiz gizlemek, kullanıcının göremediği bir alanı "değiştirmedim"
 * sanmasına yol açar; moda geçiş bilinçli olmalı. Üst çubuktaki düğme tek
 * tıkla geçirir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('panel_mode', 20)->default(PanelMode::Simple->value)->after('status');
        });

        DB::table('users')->update(['panel_mode' => PanelMode::Advanced->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('panel_mode');
        });
    }
};
