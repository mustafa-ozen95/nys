<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statik route'un TAMAMI panelden yönetilebilsin.
 *
 * Panel şimdiye kadar yalnızca hedef + tek sonraki atlama modelliyordu; geri
 * kalan her şeyi (ECMP, preference, metric, tag, retain, resolve,
 * qualified-next-hop, community, IPv6) "salt okunur" sayıp düzenlemeyi
 * kapatıyordu. Oysa hepsi cihazca yazılabilir; test1'de commit check ile
 * tek tek ölçüldü.
 *
 * Modellenmeyen uzun kuyruk için residual_xml var: panelin tanımadığı çocuk
 * düğümler ham hâlleriyle saklanır ve geri yazılırken aynen basılır. Böylece
 * hiçbir satır kaybolmaz ve nesne yine de düzenlenebilir kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            /*
             * Route hangi RIB'de duruyor. Boş = "routing-options static"
             * (inet.0). IPv6 statik route'lar "rib inet6.0" altındadır —
             * IPv6 desteğinin anahtarı bu sütun.
             */
            $table->string('rib', 64)->nullable()->after('family');

            $table->unsignedInteger('preference')->nullable()->after('next_hops');
            $table->unsignedInteger('metric')->nullable()->after('preference');
            $table->unsignedBigInteger('tag')->nullable()->after('metric');
            $table->unsignedBigInteger('tag2')->nullable()->after('tag');

            // retain, no-readvertise, resolve, install, passive, receive...
            $table->json('flags')->nullable()->after('tag2');

            // Kendi preference/metric'i olan atlamalar.
            $table->json('qualified_next_hops')->nullable()->after('flags');

            $table->json('communities')->nullable()->after('qualified_next_hops');
            $table->string('next_table', 64)->nullable()->after('communities');

            /*
             * Panelin tanımadığı çocuk düğümler, ham XML. Geri yazarken aynen
             * basılır; "anlamadığını sessizce sil" hatasının kökten çözümü.
             */
            $table->json('residual_xml')->nullable()->after('next_table');
        });
    }

    public function down(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            $table->dropColumn([
                'rib', 'preference', 'metric', 'tag', 'tag2', 'flags',
                'qualified_next_hops', 'communities', 'next_table', 'residual_xml',
            ]);
        });
    }
};
