<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * description kısa bir başlıktır ve satırda gösterilir; uzun açıklama için
 * ayrı bir metin alanı gerekiyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
