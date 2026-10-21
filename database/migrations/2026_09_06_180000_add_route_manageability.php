<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Route'un cihazdaki TAM hâli.
 *
 * Panel route yazarken sil+yaz yapıyor; okumadığı bir alan o anda sessizce
 * kayboluyordu (preference, retain, no-readvertise, ikinci sonraki atlama).
 * Artık bütün atlamalar okunuyor ve panelin yeniden üretemeyeceği route
 * SALT OKUNUR işaretleniyor — sebebiyle birlikte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            // Cihazdaki bütün sonraki atlamalar (ECMP dâhil).
            $table->json('next_hops')->nullable()->after('next_hop');

            // Doluysa route panelden düzenlenemez; metin kullanıcıya gösterilir.
            $table->string('unmanageable_reason')->nullable()->after('next_hops');
        });
    }

    public function down(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            $table->dropColumn(['next_hops', 'unmanageable_reason']);
        });
    }
};
