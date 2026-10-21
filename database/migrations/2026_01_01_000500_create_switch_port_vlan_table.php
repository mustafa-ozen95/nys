<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_port_vlan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_port_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vlan_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_untagged')->default(false);

            $table->unique(['switch_port_id', 'vlan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_port_vlan');
    }
};
