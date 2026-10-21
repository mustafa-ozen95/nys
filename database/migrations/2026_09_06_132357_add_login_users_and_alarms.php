<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihaz üzerindeki yerel hesaplar ve şasi alarm ayarları.
 *
 * Hesaplar denetimin en can alıcı parçasıdır: cihaza kim girebiliyor sorusu
 * ancak panelden görülebiliyorsa cevaplanır. Terminalden açılmış bir hesap
 * bugüne kadar hiçbir yerde görünmüyordu.
 *
 * PAROLA SAKLANMAZ. Cihaz kendi özetini tutar; paneldeki kopyası hiçbir işe
 * yaramaz, yalnızca çalınabilecek bir sır daha olurdu. Panel yalnızca
 * "parolası var mı" bilgisini tutar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_login_users', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // Cihazda hesabın KİMLİĞİ adıdır; sonradan değiştirilemez.
            $table->string('name', 64);
            $table->string('full_name')->nullable();
            $table->unsignedInteger('uid')->nullable();
            $table->string('class', 32);

            // Parolanın kendisi DEĞİL, varlığı tutulur.
            $table->boolean('has_password')->default(false);
            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });

        Schema::table('switches', function (Blueprint $table): void {
            /*
             * Şasi alarmları: bağlantı düştüğünde ışık yansın mı, hangi
             * renkte. "ignore" alarmı bastırır — kullanılmayan portların
             * paneli kırmızıya boyamasını engellemek için yaygındır.
             */
            $table->string('alarm_ethernet_link_down', 8)->nullable()->after('rstp_bpdu_block_on_edge');
            $table->string('alarm_management_link_down', 8)->nullable()->after('alarm_ethernet_link_down');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_login_users');

        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn(['alarm_ethernet_link_down', 'alarm_management_link_down']);
        });
    }
};
