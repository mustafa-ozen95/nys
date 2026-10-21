<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            // Zabbix system.hw.serialnumber'dan gelir; elle girilmez.
            $table->string('serial_number')->nullable();
            $table->index('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            $table->dropIndex(['serial_number']);
            $table->dropColumn('serial_number');
        });
    }
};
