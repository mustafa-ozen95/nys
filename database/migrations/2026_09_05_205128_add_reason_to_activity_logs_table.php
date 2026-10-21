<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşlemin GEREKÇESİ.
 *
 * "Kim ne yaptı" denetim için yetmiyor; "neden yaptı" da sorulur. Ekleme,
 * düzenleme ve silme işlemlerinde kullanıcıdan gerekçe istenir ve işlem
 * gerekçesiz yapılamaz.
 *
 * Gerekçe kaydın hash zincirine girer: sonradan değiştirilemez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->string('reason', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
