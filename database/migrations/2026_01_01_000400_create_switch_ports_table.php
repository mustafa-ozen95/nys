<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_ports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // SNMP ifIndex. Zabbix item eşlemesi bu değerle yapılır; item adını
            // her poll'da regex ile çözmeye gerek kalmaz.
            $table->unsignedInteger('if_index')->nullable();

            $table->string('description')->nullable();
            $table->string('type', 50)->default('unknown');
            $table->string('admin_status', 20)->default('up');
            $table->string('oper_status', 20)->default('down');
            $table->string('speed_config', 30)->default('auto');
            $table->unsignedInteger('speed_mbps')->nullable();
            $table->string('duplex', 20)->default('auto');
            $table->boolean('is_poe_enabled')->default(false);
            $table->string('port_mode', 30)->default('unconfigured');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            // Trafik sayaçları bilinçli olarak yok: anlık bps ve hata sayıları
            // Zabbix'ten okunup kısa süreli cache'lenir, geçmiş Zabbix'te durur.

            $table->unique(['switch_id', 'name']);
            $table->index(['switch_id', 'oper_status']);
            $table->index(['switch_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_ports');
    }
};
