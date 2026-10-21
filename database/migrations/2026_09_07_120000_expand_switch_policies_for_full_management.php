<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Routing politikalarının TAMAMI panelden yönetilebilsin.
 *
 * Panel şimdiye kadar bir politikadan yalnızca şunu modelliyordu: terim adı,
 * prefix-list-filter, accept/reject/next-policy ve community silme. Junos'un
 * geri kalanını (route-filter, as-path, protokol, komşu, local-preference,
 * metric, community add, as-path-prepend, politikanın KENDİ varsayılan
 * eylemi...) görünce politikayı "salt okunur" ilan ediyordu.
 *
 * En tehlikelisi politikanın kendi "then"iydi:
 *
 *   term A { from { prefix-list-filter LISTE orlonger; } then accept; }
 *   then reject;
 *
 * Bu satır bütün BGP süzgeçlerinin standart biçimidir ve panel onu okumadığı
 * için güncellemede siliyordu: politika "gerisini reddet"ten "gerisini de
 * al"a dönüyordu (test1'de commit check ile ölçüldü).
 *
 * İki katman birden eklenir:
 *
 *   1. Modellenen alan kümesi Junos'un pratik yüzeyine kadar genişletilir.
 *   2. Geri kalan uzun kuyruk residual_xml içinde HAM XML olarak saklanır ve
 *      geri yazılırken aynen basılır.
 *
 * Göç EKLEMELİDİR: var olan sütunlara dokunulmaz, hepsi varsayılanlıdır ve
 * geri alınabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_policy_statements', function (Blueprint $table): void {
            /*
             * Politikanın KENDİ from/then'i: terimlerden sonra gelen
             * varsayılan davranış. Terimsiz politika biçimi de budur
             * (`policy-statement { from { protocol direct; } then accept; }`),
             * cihazda geçerli olduğu ölçüldü.
             */
            $table->json('default_match')->nullable()->after('name');
            $table->json('default_then')->nullable()->after('default_match');

            /*
             * Politika düğümünün panel tarafından TANINMAYAN çocukları
             * (node) ile from/then içindeki tanınmayan satırlar. Yazarken
             * aynen basılır; hiçbir satır kaybolmaz.
             */
            $table->json('residual_xml')->nullable()->after('default_then');
        });

        /*
         * Eski kayıtlarda kalmış "salt okunur" bayrakları kalkar.
         *
         * Bayrak, panelin temsil edemediği bir yapı görüldüğünde yazılıyordu;
         * artık modellenmeyen satırlar korunuyor ve okuyucu her zaman true
         * yazıyor. Bayrak temizlenmezse ekran bir sonraki zorlamalı
         * eşitlemeye kadar düzenlemeye kapalı kalırdı.
         */
        DB::table('switch_policy_statements')->update(['is_manageable' => true]);

        Schema::table('switch_policy_terms', function (Blueprint $table): void {
            // prefix-list-filter DIŞINDAKİ bütün "from" koşulları.
            $table->json('match_conditions')->nullable()->after('match_type');

            // Sonlandırıcı eylem dışındaki bütün "then" eylemleri.
            $table->json('then_actions')->nullable()->after('community_delete');

            $table->json('residual_xml')->nullable()->after('then_actions');
        });

        Schema::table('switch_communities', function (Blueprint $table): void {
            // policy-options community X { invert-match; }
            $table->boolean('invert_match')->default(false)->after('members');
            $table->json('residual_xml')->nullable()->after('invert_match');
        });

        /*
         * policy-options as-path <ad> { path "<düzenli ifade>"; }
         *
         * Politika terimi bir as-path'e atıfta bulunuyorsa o as-path ÖNCEDEN
         * tanımlı olmalıdır; community'de olduğu gibi tanımsız ada atıf
         * commit'i düşürür. Cihazda ölçüldü (test1, commit check):
         * `as-path DENEME { path ".* 65000"; }` kabul edildi.
         */
        Schema::create('switch_as_paths', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('name');
            $table->string('path');
            $table->json('residual_xml')->nullable();

            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_as_paths');

        Schema::table('switch_communities', function (Blueprint $table): void {
            $table->dropColumn(['invert_match', 'residual_xml']);
        });

        Schema::table('switch_policy_terms', function (Blueprint $table): void {
            $table->dropColumn(['match_conditions', 'then_actions', 'residual_xml']);
        });

        Schema::table('switch_policy_statements', function (Blueprint $table): void {
            $table->dropColumn(['default_match', 'default_then', 'residual_xml']);
        });
    }
};
