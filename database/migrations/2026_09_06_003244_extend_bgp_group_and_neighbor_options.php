<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panelin BGP kapsamını Junos'un gerçekten sunduğu ayarlara genişletir.
 *
 * Eksik olanlar pratikte sık kullanılan ayarlardı: sağlayıcı peering'i MD5
 * anahtarı ister, iBGP ölçeklenirken route reflector gerekir, dinamik komşu
 * (allow) müşteri bacaklarında kullanılır, BFD hızlı düşüş algılar.
 *
 * Anahtar alanları KİMLİK taşır: şifreli saklanır, modelde $hidden'dır ve
 * cihaz farkı kayda geçerken maskelenir (bkz. ConfigurationRedactor).
 *
 * Her alan hem YAZILIR hem OKUNUR. Bir alan yazılıp okunmasaydı, güncelleme
 * döngüsü (önce sil, sonra yaz) cihazda elle kurulmuş değeri sessizce
 * silerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgp_groups', function (Blueprint $table): void {
            // MD5 oturum kimliği. Junos "$9$..." biçiminde döner; o biçim
            // çözülebilir olduğu için düz metin kadar hassastır.
            $table->text('authentication_key')->nullable()->after('local_address');

            // Karşı tarafın istediği hold-time; boşsa Junos varsayılanı (90).
            $table->unsignedSmallInteger('hold_time')->nullable()->after('authentication_key');

            // iBGP route reflector küme kimliği. Yalnızca internal grupta anlamlı.
            $table->string('cluster', 45)->nullable()->after('hold_time');

            /*
             * default: cihazda satır yok, Junos kendi davranışını uygular.
             * enable / disable: satır açıkça yazılır.
             */
            $table->string('graceful_restart', 10)->default('default')->after('cluster');

            $table->boolean('remove_private')->default(false)->after('graceful_restart');
            $table->boolean('as_override')->default(false)->after('remove_private');
            $table->boolean('advertise_inactive')->default(false)->after('as_override');
            $table->boolean('multipath')->default(false)->after('advertise_inactive');
            $table->boolean('damping')->default(false)->after('multipath');

            // Anons gecikmesi (saniye): dalgalanan yolun hemen duyurulmaması.
            $table->unsignedSmallInteger('out_delay')->nullable()->after('damping');

            // BFD en küçük aralık (ms). Boşsa BFD yapılandırılmaz.
            $table->unsignedInteger('bfd_minimum_interval')->nullable()->after('out_delay');

            /*
             * Dinamik komşu: "allow 10.0.0.0/24" verilen ağdaki her adresten
             * gelen oturumu kabul eder. Birden fazla olabilir.
             */
            $table->json('allow_prefixes')->nullable()->after('bfd_minimum_interval');
        });

        Schema::table('bgp_neighbors', function (Blueprint $table): void {
            /*
             * Junos grup ayarlarının hemen hepsini komşu seviyesinde de
             * kabul eder ve komşudaki değer grubunkini EZER. Sağlayıcı başına
             * ayrı MD5 anahtarı en yaygın kullanımdır.
             */
            $table->text('authentication_key')->nullable()->after('local_address');
            $table->unsignedSmallInteger('hold_time')->nullable()->after('authentication_key');
            $table->boolean('passive')->default(false)->after('hold_time');
            $table->boolean('multihop')->default(false)->after('passive');
            $table->unsignedTinyInteger('multihop_ttl')->nullable()->after('multihop');
            $table->json('import_policies')->nullable()->after('multihop_ttl');
            $table->json('export_policies')->nullable()->after('import_policies');
        });
    }

    public function down(): void
    {
        Schema::table('bgp_groups', function (Blueprint $table): void {
            $table->dropColumn([
                'authentication_key', 'hold_time', 'cluster', 'graceful_restart',
                'remove_private', 'as_override', 'advertise_inactive', 'multipath',
                'damping', 'out_delay', 'bfd_minimum_interval', 'allow_prefixes',
            ]);
        });

        Schema::table('bgp_neighbors', function (Blueprint $table): void {
            $table->dropColumn([
                'authentication_key', 'hold_time', 'passive', 'multihop',
                'multihop_ttl', 'import_policies', 'export_policies',
            ]);
        });
    }
};
