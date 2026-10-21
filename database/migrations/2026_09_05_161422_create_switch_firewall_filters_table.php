<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazdaki firewall filtreleri.
 *
 * Bu tablo panelin en tehlikeli yazma yoludur: yanlış bir filtre trafiği
 * tamamen keser ve yönetim erişimini de koparabilir (RE_FILTER cihazın kendi
 * yönetim trafiğini süzüyor). Bu yüzden yazma yetkisi YALNIZCA yöneticidedir;
 * "configure" tek başına yetmez.
 *
 * bindings: filtrenin hangi arayüzde hangi yönde bağlı olduğu. Bir filtreyi
 * düzenlemeden önce nerede kullanıldığını görmek şart — bağlı olduğu yeri
 * bilmeden yapılan değişiklik hangi trafiği etkileyeceğini de bilmez.
 *
 * is_manageable: panelin temsil edemediği bir koşul ya da eylem (policer,
 * next-header, flexible-match...) görülürse false olur ve filtre salt okunur
 * kalır. Yarısını anlayıp geri yazmak, kullanıcının dokunmadığı satırları
 * silerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_firewall_filters', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // inet | inet6
            $table->string('family', 6)->default('inet');
            $table->string('name');

            $table->boolean('is_manageable')->default(true);
            $table->json('bindings')->nullable();

            $table->timestamps();

            $table->unique(['switch_id', 'family', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_firewall_filters');
    }
};
