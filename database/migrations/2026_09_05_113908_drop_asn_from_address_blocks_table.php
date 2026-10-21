<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloğun kendi ASN'i sorulmuyor artık: anons, bloğun omurgadaki bir prefix
 * list'e girmesiyle belli oluyor. Anons e-postasının kendi ASN alanı var
 * (announcement_mails.asn), bu kolon onunla karıştırılıyordu ve hiçbir yerden
 * doldurulmuyordu.
 *
 * Doldurulmayan alan tutulmaz (bkz. .ai/rules/seeders.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropColumn('asn');
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->string('asn', 20)->nullable()->after('is_announced');
        });
    }
};
