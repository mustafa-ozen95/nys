<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eski toplu yetkileri yeni nesne yetkilerine taşır.
 *
 * "configure" tek başına switchin her şeyine yazma hakkıydı: port, VLAN,
 * route, prefix list, politika, toplama. Bu göçten sonra her nesne kendi
 * yetkisini taşır. Taşıma yapılmazsa bu yetkiye sahip herkes bir anda hiçbir şeye
 * yazamaz hâle gelir ve bunu ancak deneyince fark eder.
 *
 * Karşılıklar:
 *   configure      → portlar, toplama, VLAN, IP, route, prefix list,
 *                    politika (+ community) yazma yetkileri
 *   configure_bgp  → switch_bgp yazma yetkileri
 *
 * Firewall taşınmaz: "configure" onu KAPSAMAZ. Firewall ayrı ve
 * verilebilir bir yetkidir; kime verileceği bilinçli bir karardır ve
 * kendiliğinden dağıtılmaz.
 *
 * "configure" satırları SİLİNMEZ: cihazın kendi ayarları (SNMP adı,
 * yapılandırma yedeği) hâlâ o yetkiye bağlıdır.
 */
return new class extends Migration
{
    /** configure → bu kaynaklarda aynı kapsam (wildcard/kayıt) ve aynı etki. */
    private const CONFIGURE_TARGETS = [
        'switch_ports' => ['update'],
        'switch_aggregation' => ['create', 'update', 'delete'],
        'switch_vlans' => ['create', 'update', 'delete'],
        'switch_ip_addresses' => ['create', 'update', 'delete'],
        'switch_routes' => ['create', 'update', 'delete'],
        'switch_prefix_lists' => ['create', 'update', 'delete'],
        'switch_policies' => ['create', 'update', 'delete'],
    ];

    private const BGP_TARGETS = [
        'switch_bgp' => ['create', 'update', 'delete'],
    ];

    public function up(): void
    {
        $this->expand('configure', self::CONFIGURE_TARGETS);
        $this->expand('configure_bgp', self::BGP_TARGETS);

        /*
         * Görüntüleme: switchi görebilen, nesnelerini de görebiliyordu.
         * Ekranlar artık nesne bazında yetki istiyor; aynı kapsam
         * kopyalanmazsa herkesin bütün sekmeleri kaybolur.
         */
        $this->expand('view', array_map(
            static fn (): array => ['view'],
            array_flip([
                'switch_ports', 'switch_aggregation', 'switch_vlans',
                'switch_ip_addresses', 'switch_routes', 'switch_prefix_lists',
                'switch_firewall', 'switch_policies', 'switch_bgp',
            ]),
        ));

        // Artık hiçbir yerde sorulmayan yetki satırı kalmasın.
        DB::table('user_permissions')
            ->where('resource', 'switches')
            ->where('action', 'configure_bgp')
            ->delete();
    }

    public function down(): void
    {
        DB::table('user_permissions')
            ->whereIn('resource', array_keys([
                ...self::CONFIGURE_TARGETS,
                ...self::BGP_TARGETS,
                'switch_firewall' => null,
            ]))
            ->delete();
    }

    /**
     * Switch kaynağındaki bir aksiyonun satırlarını nesne kaynaklarına
     * kopyalar. Kapsam (wildcard ya da tek kayıt) ve etki (izin/red) aynen
     * korunur.
     *
     * @param  array<string, list<string>>  $targets
     */
    private function expand(string $action, array $targets): void
    {
        $rows = DB::table('user_permissions')
            ->where('resource', 'switches')
            ->where('action', $action)
            ->get();

        $new = [];

        foreach ($rows as $row) {
            foreach ($targets as $resource => $actions) {
                foreach ($actions as $target) {
                    $new[] = [
                        'user_id' => $row->user_id,
                        'resource' => $resource,
                        'action' => $target,
                        'resource_id' => $row->resource_id,
                        'effect' => $row->effect,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        foreach (array_chunk($new, 500) as $chunk) {
            DB::table('user_permissions')->insertOrIgnore($chunk);
        }
    }
};
