<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Son ölçümün hangi kaynaktan geldiği.
 *
 * Panelin gösterdiği CPU, sıcaklık ve uptime değerinin izleme sisteminden mi
 * yoksa cihazın kendisinden mi okunduğu görünmelidir: yedeğe düşüldüğünde
 * kullanıcı bunu sessizce değil, ekranda görmeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->string('measurement_source')->nullable()->after('zabbix_host_id');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn('measurement_source');
        });
    }
};
