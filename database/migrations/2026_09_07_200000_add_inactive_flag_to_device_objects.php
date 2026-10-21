<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazda "deactivate" edilmiş nesneler.
 *
 * Junos bir nesneyi silmeden devre dışı bırakabilir: yapılandırma olduğu yerde
 * durur ama ÇALIŞMAZ. İşaret gövdede değil ÖZNİTELİKTEDİR:
 *
 *     deactivate protocols bgp group router
 *     → <group inactive="inactive"><name>router</name>...
 *
 * Panel özniteliği hiç okumuyordu: devre dışı bir BGP grubu (ör. router ya
 * da router-v6) ETKİN görünürdü ve operatör kurulu sanılan bir oturumun
 * aslında çalışmadığını göremezdi.
 *
 * Yazma tarafı zaten güvendeydi — merge özniteliği koruyor (test1'de ölçüldü),
 * yani düzenleme devre dışı bir nesneyi sessizce açmıyordu. Eksik olan
 * GÖRÜNÜRLÜKTÜ; bu göç onu kapatır ve panelden açıp kapatmayı mümkün kılar.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'bgp_groups',
        'bgp_neighbors',
        'switch_policy_statements',
        'switch_policy_terms',
        'switch_firewall_filters',
        'switch_firewall_terms',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('is_inactive')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('is_inactive');
            });
        }
    }
};
