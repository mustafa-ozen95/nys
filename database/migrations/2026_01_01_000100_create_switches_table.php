<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switches', function (Blueprint $table): void {
            $table->id();
            $table->string('hostname')->unique();
            $table->string('management_ip', 45)->unique();
            $table->string('uplink_ip', 45)->nullable();
            $table->string('model')->nullable();
            $table->string('junos_version')->nullable();
            $table->string('role', 30)->default('access');
            $table->string('location', 30)->default('plus');
            $table->string('status', 30)->default('offline');

            // Zabbix eşleşmesi bir kez çözülüp saklanır; her poll'da host.get çağrılmaz.
            $table->string('zabbix_host_id', 30)->nullable()->unique();

            // Zabbix'ten gelen anlık ölçümler. Geçmiş tutulmaz; liste ekranlarında
            // sıralama gerektiği için son değer burada saklanır.
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedTinyInteger('cpu_usage')->nullable();
            $table->unsignedTinyInteger('memory_usage')->nullable();
            $table->unsignedSmallInteger('temperature')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('role');
            $table->index('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switches');
    }
};
