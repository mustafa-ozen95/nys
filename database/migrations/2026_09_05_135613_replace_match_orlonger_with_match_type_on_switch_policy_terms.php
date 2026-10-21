<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prefix-list-filter eşleşme türü boolean ile temsil edilemez.
 *
 * Junos üç tür kabul eder ve BİRİ ZORUNLUDUR: exact, longer, orlonger.
 * Türsüz yazılan filtre "syntax error, expecting <flag-value> or
 * <attribute-value>" ile reddedilir — gerçek cihazda doğrulandı.
 *
 * "orlonger mı değil mi" biçimindeki eski boolean, "exact" ile "longer"ı
 * ayırt edemiyor ve ikisini de aynı şeye çeviriyordu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_policy_terms', function (Blueprint $table): void {
            $table->string('match_type', 10)->nullable()->after('match_prefix_lists');
            $table->dropColumn('match_orlonger');
        });
    }

    public function down(): void
    {
        Schema::table('switch_policy_terms', function (Blueprint $table): void {
            $table->boolean('match_orlonger')->default(false)->after('match_prefix_lists');
            $table->dropColumn('match_type');
        });
    }
};
