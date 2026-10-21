<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BGP oturum durumu elle girilmez; cihazdan okunana kadar bilinmiyordur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgp_sessions', function (Blueprint $table): void {
            $table->string('state', 20)->default('unknown')->change();
        });
    }

    public function down(): void
    {
        Schema::table('bgp_sessions', function (Blueprint $table): void {
            $table->string('state', 20)->default('idle')->change();
        });
    }
};
