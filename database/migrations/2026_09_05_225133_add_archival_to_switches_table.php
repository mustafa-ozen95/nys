<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yapılandırma yedeği artık CİHAZA ÖZGÜDÜR.
 *
 * Tek bir panel ayarı olarak tutulduğunda her switch aynı hedefe yazmak
 * zorunda kalıyordu; oysa hedef cihazdan cihaza değişebilir (farklı saha,
 * farklı yedek sunucusu) ve bazı cihazlarda hiç istenmez.
 *
 * Adres KİMLİK İÇERİR (ftp://kullanici:parola@...), bu yüzden şifreli
 * saklanır ve modelde $hidden'dır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->boolean('archival_enabled')->default(false)->after('measurement_source');
            $table->text('archival_site')->nullable()->after('archival_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn(['archival_enabled', 'archival_site']);
        });
    }
};
