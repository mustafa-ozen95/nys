<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NTP açılış sunucusu ve cihaz saati sapması.
 *
 * NTP sunucusu tanımlı bir cihazın saati yine de yıllarca geride olabilir
 * (ör. pil bitmiş). Sebep iki katmanlıdır:
 *
 *  1. Sunucuya erişilemiyor ("No route to host"; ör. cihazda varsayılan rota
 *     yok, NTP sunucusu yönetim ağının dışında).
 *  2. Erişilse bile "ntp server" tek başına düzeltmezdi: ntpd, fark 1000
 *     saniyeyi aşınca saati adımlamayı REDDEDER. Bunun için "boot-server"
 *     gerekir — açılışta saat zorla yerine oturur.
 *
 * Panel bunların hiçbirini göstermiyordu; büyük bir sapma ancak commit
 * geçmişindeki tuhaf tarihlerden fark edilirdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->string('ntp_boot_server', 45)->nullable()->after('ntp_servers');

            /*
             * Cihaz saatinin panel sunucusundan farkı (saniye). Null =
             * hiç ölçülmedi. Eşiği aşınca ekranda uyarı çıkar.
             */
            $table->integer('clock_offset_seconds')->nullable()->after('ntp_boot_server');
            $table->timestamp('clock_checked_at')->nullable()->after('clock_offset_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn(['ntp_boot_server', 'clock_offset_seconds', 'clock_checked_at']);
        });
    }
};
