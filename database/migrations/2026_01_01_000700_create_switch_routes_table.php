<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->string('destination', 45);
            $table->unsignedTinyInteger('prefix_length');
            $table->string('next_hop', 45)->nullable();
            $table->string('protocol', 30)->default('static');
            $table->string('family', 10)->default('inet');
            $table->timestamps();

            $table->unique(['switch_id', 'destination', 'prefix_length', 'next_hop'], 'switch_route_unique');
            $table->index(['switch_id', 'protocol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_routes');
    }
};
