<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Politika terimleri.
 *
 * Junos'ta terim SIRASI anlamlıdır: ilk eşleşen terim kazanır, sonuncusu
 * genellikle "reject"tir. position bu yüzden saklanır ve yazarken korunur;
 * sıraya dikkat etmeyen bir güncelleme "her şeyi kabul et"i "her şeyi reddet"in
 * arkasına atıp politikayı sessizce etkisizleştirebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_policy_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_policy_statement_id')
                ->constrained('switch_policy_statements')
                ->cascadeOnDelete();

            $table->string('name');
            $table->unsignedSmallInteger('position');

            // from prefix-list-filter <ad> orlonger
            $table->json('match_prefix_lists')->nullable();
            $table->boolean('match_orlonger')->default(false);

            // then accept | reject | next-policy. Boşsa terimin eylemi yoktur.
            $table->string('action', 20)->nullable();

            // then community delete <ad>
            $table->string('community_delete')->nullable();

            $table->timestamps();

            $table->unique(['switch_policy_statement_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_policy_terms');
    }
};
