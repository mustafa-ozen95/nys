<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anons talebi alıcıları.
 *
 * Kime hangi dilde gidileceği config'de sabit duruyordu; artık panelden
 * yönetilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('email')->unique();
            $table->string('locale', 5)->default('tr');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }
};
