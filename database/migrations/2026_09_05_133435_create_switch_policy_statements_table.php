<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazdaki policy-statement'lar.
 *
 * BGP grubu bir politikaya, politika bir prefix list'e dayanır; tipik bir
 * omurgadaki zincir şöyledir:
 *
 *   group musteri1 import prefixes-from-musteri1
 *     -> term A from prefix-list-filter musteri1-PREFIXES orlonger -> accept
 *     -> term B -> reject
 *
 * Politikanın ÖNCE tanımlı olması şarttır: tanımsız bir politikaya atıfta
 * bulunan grup cihazda "Policy error: Policy p1 referenced but not defined"
 * ile reddedilir (gerçek cihazda görüldü). Bu yüzden grup formundaki politika
 * listesi uydurulmaz, buradan doldurulur.
 *
 * is_manageable: panelin temsil edemediği bir yapı (as-path, route-filter,
 * community add, next-hop değişikliği...) görülürse false olur ve o politika
 * salt okunur kalır. Temsil edilemeyen bir kaydı düzenlemeye açmak,
 * kullanıcının farkında olmadan cihazdaki satırları silmesine yol açardı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_policy_statements', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('name');
            $table->boolean('is_manageable')->default(true);

            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_policy_statements');
    }
};
