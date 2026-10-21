<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blok bir omurga switchteki prefix list'e girebilir:
 *
 *   set policy-options prefix-list musteri1-PREFIXES 192.0.2.0/24
 *
 * Bu atama erişim switchiyle ilgili değildir; liste omurgada durur. Liste
 * cihazdan silinirse kayıt da düşer (cascade), çünkü prefix list'ler
 * cihazdan eşitlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->foreignId('switch_prefix_list_id')->nullable()->after('next_hop')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('switch_prefix_list_id');
        });
    }
};
