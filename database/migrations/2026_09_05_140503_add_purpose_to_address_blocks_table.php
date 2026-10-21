<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloğun ne için ayrıldığı: müşteri, uplink, BGP peering ya da yönetim.
 *
 * Uplink bacakları ve peering /30'ları private adreslerdir ve müşteri
 * bloklarıyla aynı havuzda durmamalı. Bu alan olmadan "peering için boş bir
 * /30 ver" sorusu yanıtlanamıyordu; müşteri blokları da aday çıkıyordu.
 *
 * Varsayılan "customer": mevcut kayıtlar müşteri bloklarıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->string('purpose', 20)->default('customer')->after('family');
            $table->index('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
