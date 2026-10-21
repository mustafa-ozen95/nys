<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_ip_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('switch_port_id')->nullable()->constrained()->nullOnDelete();

            // Arayüz henüz envantere girmemiş olabilir; adı ham olarak da tutulur.
            $table->string('interface_name');

            $table->string('address', 45);
            $table->unsignedTinyInteger('prefix_length');
            $table->string('family', 10)->default('inet');
            $table->timestamps();

            $table->unique(['switch_id', 'address', 'interface_name'], 'switch_ip_unique');
            $table->index('address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_ip_addresses');
    }
};
