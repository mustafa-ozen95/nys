<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vlans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('vlan_tag');
            $table->string('l3_interface')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'vlan_tag']);
            $table->index('vlan_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vlans');
    }
};
