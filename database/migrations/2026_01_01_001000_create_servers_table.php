<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->string('short_name')->unique();
            $table->string('long_name')->nullable();
            $table->string('ip_address', 255);
            $table->string('os_type', 30)->default('linux');
            $table->string('os_version')->nullable();
            $table->string('connection_protocol', 30)->default('ssh');
            $table->unsignedSmallInteger('connection_port')->default(22);
            $table->string('connection_username')->nullable();

            // Şifreli çıktı 255 karakteri aşar; eski şemadaki varchar(255) hatası.
            $table->text('connection_password')->nullable();

            $table->string('location')->nullable();
            // Donanım alanları yok: sunucudan otomatik toplanamıyor, elle
            // girilen ölü veri olurdu. Durum elle belirlenir.
            $table->string('status', 30)->default('offline');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ip_address', 'connection_port']);
            $table->index('os_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
