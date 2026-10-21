<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bir blok her zaman bir switchin VLAN'ına ya da portuna bağlanmaz; bir
 * sonraki atlama IP'sine de route edilebilir (müşteri routerı, güvenlik
 * duvarı). O durumda erişim switchi yoktur ve hedef bu kolonda durur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->string('next_hop', 45)->nullable()->after('port_name');
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropColumn('next_hop');
        });
    }
};
