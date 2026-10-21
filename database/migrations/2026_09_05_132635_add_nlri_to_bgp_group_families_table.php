<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ailenin altındaki NLRI alt elemanı: any | unicast | multicast.
 *
 * Junos aynı prefix sınırını üç farklı alt elemanın altında tutabiliyor:
 *
 *   set ... family inet any     prefix-limit maximum 200
 *   set ... family inet unicast prefix-limit maximum 200
 *
 * Sınır açısından ikisi de aynı yere yazılır ama CİHAZDA farklı satırlardır.
 * Saklanmasaydı, "unicast" ile okunan bir grup panelden düzenlendiğinde
 * "any" olarak geri yazılır ve kullanıcının dokunmadığı bir ayar sessizce
 * değişirdi. En yaygın kullanım "any"dir, varsayılan odur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgp_group_families', function (Blueprint $table): void {
            $table->string('nlri', 10)->default('any')->after('family');
        });
    }

    public function down(): void
    {
        Schema::table('bgp_group_families', function (Blueprint $table): void {
            $table->dropColumn('nlri');
        });
    }
};
