<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_prefix_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });

        Schema::create('switch_prefix_list_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_prefix_list_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45);
            $table->unsignedTinyInteger('prefix_length');
            $table->string('family', 10)->default('inet');

            $table->unique(['switch_prefix_list_id', 'address', 'prefix_length'], 'prefix_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_prefix_list_entries');
        Schema::dropIfExists('switch_prefix_lists');
    }
};
