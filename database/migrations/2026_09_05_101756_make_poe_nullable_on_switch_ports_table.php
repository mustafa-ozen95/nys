<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PoE her portta yoktur: bu kasalarda yalnızca ilk sekiz port PoE besler
 * (chassis "EX4200-24T, 8 POE" der, cihaz get-poe-interface-information ile
 * de yalnızca o portları döndürür).
 *
 * Kolon "not null default false" olduğu sürece "PoE kapalı" ile "bu portta
 * PoE yok" ayrılamıyordu ve panel ge-0/0/8 gibi portlarda da PoE anahtarı
 * gösteriyordu. Null artık "donanımda PoE yok" demektir ve o portlarda alan
 * hiç çizilmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->boolean('is_poe_enabled')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('switch_ports', function (Blueprint $table): void {
            $table->boolean('is_poe_enabled')->default(false)->change();
        });
    }
};
