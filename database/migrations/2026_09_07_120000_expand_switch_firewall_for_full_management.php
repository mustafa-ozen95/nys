<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firewall filtresinin TAMAMI panelden yönetilebilsin.
 *
 * Panel şimdiye kadar filtrenin küçük bir alt kümesini modelliyordu ve
 * güncelleme filtreyi SİLİP yeniden yazdığı için (terim sırası anlamlı)
 * modellemediği her satır o sırada kayboluyordu. Çözüm "salt okunur yap"
 * değildi; iki katman birden gerekiyordu:
 *
 *   1. Modellenen alan kümesi Junos'un pratik yüzeyine kadar genişletildi —
 *      tcp-flags, dscp, packet-length, ip-options, interface, next-header,
 *      ether-type, MAC adresleri, policer, forwarding-class, sample...
 *   2. Geri kalan uzun kuyruk ham XML olarak saklanır (residual_xml) ve geri
 *      yazılırken aynen basılır.
 *
 * "except" ayrı bir sütundur: prefix list adlarının listesi (source_prefix_lists)
 * DependencyGuard ve EgressGuardReader tarafından okunuyor; şeklini değiştirmek
 * o iki yeri sessizce bozardı. Hangi adın "except" taşıdığı yan listede durur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_firewall_filters', function (Blueprint $table): void {
            // "ethernet-switching" 6 karaktere sığmaz.
            $table->string('family', 24)->default('inet')->change();

            // Arayüz başına ayrı sayaç. Filtrenin KENDİ altındadır; panel onu
            // okumadığı için her güncellemede siliniyordu.
            $table->boolean('interface_specific')->default(false)->after('name');

            // Filtre seviyesinde panelin tanımadığı çocuk düğümler, ham XML.
            $table->json('residual_xml')->nullable()->after('bindings');
        });

        Schema::table('switch_firewall_terms', function (Blueprint $table): void {
            // --- from: hangi adların "except" ile geldiği
            $table->json('source_prefix_list_excepts')->nullable()->after('source_prefix_lists');
            $table->json('destination_prefix_list_excepts')->nullable()->after('destination_prefix_lists');

            // --- from: TCP
            $table->string('tcp_flags')->nullable()->after('icmp_types');
            $table->boolean('is_tcp_initial')->default(false)->after('tcp_flags');
            $table->boolean('is_tcp_established')->default(false)->after('is_tcp_initial');

            // --- from: IP başlığı
            $table->json('icmp_codes')->nullable()->after('is_tcp_established');
            $table->json('dscps')->nullable()->after('icmp_codes');
            $table->json('precedences')->nullable()->after('dscps');
            $table->json('packet_lengths')->nullable()->after('precedences');
            $table->json('ip_options')->nullable()->after('packet_lengths');
            $table->json('fragment_flags')->nullable()->after('ip_options');
            $table->boolean('is_fragment')->default(false)->after('fragment_flags');
            $table->boolean('is_first_fragment')->default(false)->after('is_fragment');

            // --- from: arayüz
            $table->json('interfaces')->nullable()->after('is_first_fragment');

            // --- from: yalnızca inet6
            $table->json('next_headers')->nullable()->after('interfaces');
            $table->json('traffic_classes')->nullable()->after('next_headers');

            // --- from: yalnızca ethernet-switching
            $table->json('ether_types')->nullable()->after('traffic_classes');
            $table->json('source_mac_addresses')->nullable()->after('ether_types');
            $table->json('destination_mac_addresses')->nullable()->after('source_mac_addresses');

            // --- then
            $table->boolean('goes_to_next_term')->default(false)->after('action');
            $table->string('routing_instance')->nullable()->after('goes_to_next_term');
            $table->string('policer')->nullable()->after('routing_instance');
            $table->string('forwarding_class')->nullable()->after('policer');
            $table->string('loss_priority', 16)->nullable()->after('forwarding_class');
            $table->string('dscp', 32)->nullable()->after('loss_priority');
            $table->string('analyzer')->nullable()->after('dscp');
            $table->boolean('is_port_mirrored')->default(false)->after('analyzer');
            $table->boolean('is_sampled')->default(false)->after('is_port_mirrored');
            $table->boolean('is_syslogged')->default(false)->after('is_sampled');

            /*
             * Panelin tanımadığı çocuk düğümler. ÜÇ yerde birden gerekir:
             * terimin kendi altında, "from"un altında ve "then"in altında.
             * Yalnızca terim seviyesi saklansaydı, from içindeki tanınmayan
             * bir koşul yine kaybolurdu — gövde modellenen alanlardan
             * yeniden kuruluyor.
             */
            $table->json('residual_xml')->nullable();
            $table->json('from_residual_xml')->nullable();
            $table->json('then_residual_xml')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('switch_firewall_filters', function (Blueprint $table): void {
            $table->dropColumn(['interface_specific', 'residual_xml']);
            $table->string('family', 6)->default('inet')->change();
        });

        Schema::table('switch_firewall_terms', function (Blueprint $table): void {
            $table->dropColumn([
                'source_prefix_list_excepts', 'destination_prefix_list_excepts',
                'tcp_flags', 'is_tcp_initial', 'is_tcp_established',
                'icmp_codes', 'dscps', 'precedences', 'packet_lengths',
                'ip_options', 'fragment_flags', 'is_fragment', 'is_first_fragment',
                'interfaces', 'next_headers', 'traffic_classes',
                'ether_types', 'source_mac_addresses', 'destination_mac_addresses',
                'goes_to_next_term', 'routing_instance', 'policer',
                'forwarding_class', 'loss_priority', 'dscp', 'analyzer',
                'is_port_mirrored', 'is_sampled', 'is_syslogged',
                'residual_xml', 'from_residual_xml', 'then_residual_xml',
            ]);
        });
    }
};
