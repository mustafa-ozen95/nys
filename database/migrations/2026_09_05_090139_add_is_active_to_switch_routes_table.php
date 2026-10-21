<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yapılandırmada duran statik route, sonraki atlaması erişilemezse routing
 * tablosuna kurulmaz. İki durumu ayırt edemezsek panelden eklenen route
 * ekrandan kaybolur; bu kolon "yazıldı ama kurulmadı" hâlini gösterir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('family');
        });
    }

    public function down(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
