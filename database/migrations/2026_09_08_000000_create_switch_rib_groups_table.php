<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIB grupları ve arayüz yollarının hangi gruba kopyalanacağı.
 *
 * Filtre tabanlı yönlendirme (FBF) üç parçadan oluşur ve üçü de olmadan
 * çalışmaz:
 *
 *   1. firewall … then routing-instance tablo-a   (trafiği atar)
 *   2. routing-instances tablo-a                  (tabloyu tanımlar)
 *   3. routing-options rib-groups rib-grup-a      (arayüz yollarını
 *      + interface-routes rib-group inet rib-grup-a  tabloya kopyalar)
 *
 * Üçüncüsü olmadan tabloya giden trafik geri dönüş yolunu bulamaz.
 *
 * Biçimler test1'de ölçüldü: import-rib yinelenen düz elemanlardır,
 * interface-routes ise "rib-group inet <ad>" biçiminde tek bir işaretçidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_rib_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();
            $table->string('name', 63);

            /** Kopyalanacak tablolar, SIRASIYLA: sıra Junos'ta anlamlıdır. */
            $table->json('import_ribs')->nullable();

            $table->json('residual_xml')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });

        Schema::table('switches', function (Blueprint $table): void {
            /*
             * Arayüz yollarının kopyalanacağı grup. Switch başına TEK
             * işaretçidir (interface-routes rib-group inet <ad>), bu yüzden
             * ayrı tablo değil sütun.
             */
            $table->string('interface_routes_rib_group', 63)->nullable()->after('aggregated_device_count');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('interface_routes_rib_group');
        });

        Schema::dropIfExists('switch_rib_groups');
    }
};
