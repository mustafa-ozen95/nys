<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bir blok, switche route edilmenin yanı sıra ASN üzerinden internete anons
 * edilen bir prefix de olabilir. İkisi birbirini dışlamaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->boolean('is_announced')->default(false)->after('port_name');
            $table->string('asn', 20)->nullable()->after('is_announced');

            $table->index('is_announced');
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropIndex(['is_announced']);
            $table->dropColumn(['is_announced', 'asn']);
        });
    }
};
