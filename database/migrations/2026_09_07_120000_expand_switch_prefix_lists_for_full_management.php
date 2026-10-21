<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prefix listenin TAMAMI panelden yönetilebilsin.
 *
 * Panel şimdiye kadar yalnızca statik girdileri (prefix-list-item)
 * modelliyordu. Junos bir listeyi "apply-path" ile DİNAMİK de doldurabiliyor
 * (test1'de commit check ile ölçüldü) ve panel bunu göremediği için listeyi
 * SALT OKUNUR ilan ediyordu — oysa alan cihazca yazılabilir.
 *
 * İki sütun eklenir:
 *
 *   apply_paths   Listeyi dinamik dolduran ifadeler. Modellenir: okunur,
 *                 formda düzenlenir, cihaza yazılır.
 *   residual_xml  Panelin tanımadığı çocuk düğümler (apply-groups gibi) ham
 *                 hâlleriyle. Ekranda "N korunan satır" olarak gösterilir;
 *                 listenin kendisi sil+yeniden yaz EDİLMEDİĞİ için cihazda
 *                 zaten yerinde kalırlar.
 *
 * "is_manageable" sütunu KALDIRILMAZ ama artık hep true yazılır: apply-path
 * modellendiğine göre panelin temsil edemediği bir liste kalmadı. Sütun,
 * ileride gerçekten temsil edilemeyen bir yapı çıkarsa yerinde dursun diye
 * korunuyor (ve göç geri alınabilir kalsın diye).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_prefix_lists', function (Blueprint $table): void {
            $table->json('apply_paths')->nullable()->after('name');
            $table->json('residual_xml')->nullable()->after('apply_paths');
        });
    }

    public function down(): void
    {
        Schema::table('switch_prefix_lists', function (Blueprint $table): void {
            $table->dropColumn(['apply_paths', 'residual_xml']);
        });
    }
};
