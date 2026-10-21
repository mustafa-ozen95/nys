<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 30)->default('ssh');
            $table->string('username')->nullable();

            // Şifreli alanların çıktısı 255 karakteri aşabilir; daima text.
            $table->text('password')->nullable();
            $table->text('community')->nullable();
            $table->text('auth_key')->nullable();

            $table->unsignedSmallInteger('port')->default(22);
            $table->timestamps();

            $table->unique(['switch_id', 'protocol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_credentials');
    }
};
