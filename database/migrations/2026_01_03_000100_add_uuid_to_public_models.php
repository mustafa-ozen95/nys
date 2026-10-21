<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dışarıya açılan her kayda uuid verir.
 *
 * Adres çubuğunda sıralı birincil anahtar görünmemeli: bir kaydın kimliğini
 * bilen diğerlerininkini tahmin edememeli. İlişkiler tamsayı kalır.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const TABLES = [
        'switches',
        'switch_ports',
        'vlans',
        'switch_ip_addresses',
        'switch_routes',
        'switch_prefix_lists',
        'switch_prefix_list_entries',
        'switch_commits',
        'switch_credentials',
        'address_blocks',
        'bgp_sessions',
        'servers',
        'users',
        'announcement_recipients',
        'announcement_mails',
        'activity_logs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });

            // Mevcut kayıtlara da kimlik verilir.
            foreach (DB::table($table)->whereNull('uuid')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('uuid')->nullable(false)->change();
                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_uuid_unique');
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
