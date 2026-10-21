<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Junos BGP peer grupları. Kaynağı cihazdır: eşitleme yazar, panel okur.
 *
 * Junos'ta komşu daima bir gruba bağlıdır; grupsuz komşu tanımlanamaz. Bu
 * yüzden hiyerarşi cihazdaki gibi kurulur ve düzleştirilmez: grup ayarları
 * (tip, peer-as, politika, multihop) altındaki bütün komşulara işler.
 *
 * Veri elle girilmez: cihazla bağı olmayan bir tablo, ekranda cihazın
 * gerçek durumu yerine sabit bir durum gösterirdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bgp_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('name');

            // external = eBGP, internal = iBGP. Junos yalnızca bu ikisini bilir.
            $table->string('type', 10);

            // Grup seviyesindeki varsayılanlar; komşu kendi değeriyle ezebilir.
            $table->string('peer_asn', 20)->nullable();
            $table->string('local_asn', 20)->nullable();
            $table->string('local_address', 45)->nullable();

            /*
             * multihop: doğrudan komşu olmayan eBGP eşi için. TTL boş
             * bırakılabilir; Junos o zaman kendi varsayılanını kullanır.
             */
            $table->boolean('multihop')->default(false);
            $table->unsignedTinyInteger('multihop_ttl')->nullable();

            $table->boolean('log_updown')->default(false);
            $table->boolean('passive')->default(false);

            /*
             * Junos import/export birden fazla politika kabul eder
             * ("import [ a b ]") ve sıra anlamlıdır; liste olarak saklanır.
             */
            $table->json('import_policies')->nullable();
            $table->json('export_policies')->nullable();

            $table->string('description')->nullable();

            $table->timestamps();

            // Grup adı cihazda kimliktir.
            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgp_groups');
    }
};
