<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Basit mod — hangi switch bölümü görünür, hangisi yönetilebilir
|--------------------------------------------------------------------------
| Basit modda hangi bölümün görüntülenip yönetilebileceği buradan
| belirlenir.
|
| Her bölüm iki anahtar taşır: GÖRÜNTÜLE ve YÖNET (oluştur / düzenle / sil).
| Kural YETKİ KATMANINDA uygulanır (UserPermissionResolver): basit moddaki
| kişinin yetkisi "rolünün verdiği ∩ burada açık olan"dır. Kapatılan bölüm
| menüden kalkar ve adresi elle yazılsa da açılmaz. Rol yetkisini
| GENİŞLETMEZ, yalnızca daraltır. Yönet açık ama görüntüle kapalıysa
| yönetim de kapalıdır: görmediğiniz şeyi yönetemezsiniz.
|
| Varsayılanlar bugünkü davranıştır (hepsi açık); daraltma Ayarlar →
| Basit mod ekranından yapılır. Etiketler yetki kataloğundan gelir
| (config/permissions.php), burada ikinci bir kopya tutulmaz.
*/

$bolumler = [
    'switch_ports', 'switch_aggregation', 'switch_vlans', 'switch_ip_addresses',
    'switch_routes', 'switch_rstp', 'switch_voip', 'switch_mirroring',
    'switch_secure_access', 'switch_forwarding', 'switch_prefix_lists',
    'switch_firewall', 'switch_policies', 'switch_bgp',
];

return [
    'bolumler' => array_fill_keys($bolumler, ['goruntule' => true, 'yonet' => true]),

    /*
    |--------------------------------------------------------------------------
    | Alanlar — bölümün içinde tek tek
    |--------------------------------------------------------------------------
    | Görünümdeki alan bu anahtarı taşır: <x-form.field kural="bölüm.form.alan">.
    | Basit modda KAPALI alan GÖRÜNMEZ AMA FORMDA KALIR ve dokunulmamış
    | değeriyle gönderilir: panelde birçok formda "gönderilmedi = cihazdan
    | sil" anlamına geldiği için alanı çıkarmak cihazdaki değeri silebilirdi.
    | Kullanıcı yalnızca onu değiştiremez.
    |
    | "advanced" işaretli üç port alanı (Hız, Duplex, MTU) eski davranışını
    | korur — çizilmez, cihazdaki değer salt okunur satırda söylenir — ve
    | varsayılan olarak kapalıdır; buradan açılabilir.
    |
    | ZORUNLU ve adı döngüyle üretilen alanlar katalogda YOK: gizli ve boş bir
    | zorunlu alan formu görünmez bir hatayla kilitlerdi.
    |
    | Görünüm ile katalog ayrışmamalı: KosulluAlanKataloguTest ikisini
    | karşılaştırır.
    */
    'alanlar' => [
        'switch_ports' => [
            'port:enabled' => ['etiket' => 'Port durumu', 'varsayilan' => true, 'acik' => true],
            'port:port_mode' => ['etiket' => 'Port modu', 'varsayilan' => true, 'acik' => true],
            'port:speed_config' => ['etiket' => 'Hız', 'varsayilan' => false, 'acik' => false],
            'port:duplex_config' => ['etiket' => 'Duplex', 'varsayilan' => false, 'acik' => false],
            'port:mtu' => ['etiket' => 'MTU', 'varsayilan' => false, 'acik' => false],
            'port:is_poe_enabled' => ['etiket' => 'PoE', 'varsayilan' => true, 'acik' => true],
            'port:vlans' => ['etiket' => 'VLAN üyeliği', 'varsayilan' => true, 'acik' => true],
            'port:ip_address' => ['etiket' => 'IP adresi', 'varsayilan' => true, 'acik' => true],
            'port:input_filter' => ['etiket' => 'Giriş filtresi', 'varsayilan' => true, 'acik' => true],
            'port:output_filter' => ['etiket' => 'Çıkış filtresi', 'varsayilan' => true, 'acik' => true],
            'port:untagged_vlan' => ['etiket' => 'Etiketsiz VLAN', 'varsayilan' => true, 'acik' => true],
            'port:description' => ['etiket' => 'Açıklama', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_aggregation' => [
            'aggregation:description' => ['etiket' => 'Açıklama', 'varsayilan' => true, 'acik' => true],
            'aggregation:enabled' => ['etiket' => 'Arayüz durumu', 'varsayilan' => true, 'acik' => true],
            'aggregation:lacp_mode' => ['etiket' => 'LACP', 'varsayilan' => true, 'acik' => true],
            'aggregation:lacp_periodic' => ['etiket' => 'LACP periyodu', 'varsayilan' => true, 'acik' => true],
            'aggregation:minimum_links' => ['etiket' => 'En az bağlantı sayısı', 'varsayilan' => true, 'acik' => true],
            'aggregation:link_speed' => ['etiket' => 'Bağlantı hızı', 'varsayilan' => true, 'acik' => true],
            'aggregation:port_mode' => ['etiket' => 'Port modu', 'varsayilan' => true, 'acik' => true],
            'aggregation:vlans' => ['etiket' => 'VLAN üyeliği', 'varsayilan' => true, 'acik' => true],
            'aggregation:untagged_vlan' => ['etiket' => 'Etiketsiz VLAN', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_vlans' => [
            'vlan:vlan_tag' => ['etiket' => 'VLAN etiketi', 'varsayilan' => true, 'acik' => true],
            'vlan:name' => ['etiket' => 'Ad', 'varsayilan' => true, 'acik' => true],
            'vlan:description' => ['etiket' => 'Açıklama', 'varsayilan' => true, 'acik' => true],
            'vlan:l3_interface' => ['etiket' => 'L3 arayüzü', 'varsayilan' => true, 'acik' => true],
            'vlan:ports' => ['etiket' => 'Portlar', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_ip_addresses' => [
            'ip:attachment' => ['etiket' => 'Adres nereye bağlı?', 'varsayilan' => true, 'acik' => true],
            'ip:vlan_id' => ['etiket' => 'VLAN', 'varsayilan' => true, 'acik' => true],
            'ip:switch_port_id' => ['etiket' => 'Port', 'varsayilan' => true, 'acik' => true],
            'ip:address' => ['etiket' => 'Adres', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_routes' => [
            'route:cidr' => ['etiket' => 'Hedef ağ', 'varsayilan' => true, 'acik' => true],
            'route:action' => ['etiket' => 'Yol ne yapsın?', 'varsayilan' => true, 'acik' => true],
            'route:next_hops' => ['etiket' => 'Sonraki atlamalar', 'varsayilan' => true, 'acik' => true],
            'route:qualified_next_hops' => ['etiket' => 'Yedek atlamalar', 'varsayilan' => true, 'acik' => true],
            'route:preference' => ['etiket' => 'Tercih (preference)', 'varsayilan' => true, 'acik' => true],
            'route:metric' => ['etiket' => 'Metrik', 'varsayilan' => true, 'acik' => true],
            'route:tag' => ['etiket' => 'Etiket (tag)', 'varsayilan' => true, 'acik' => true],
            'route:tag2' => ['etiket' => 'İkinci etiket (tag2)', 'varsayilan' => true, 'acik' => true],
            'route:flags' => ['etiket' => 'Seçenekler', 'varsayilan' => true, 'acik' => true],
            'route:communities' => ['etiket' => 'Community', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_rstp' => [
            'arayuz:interface_name' => ['etiket' => 'Arayüz · Arayüz', 'varsayilan' => true, 'acik' => true],
            'arayuz:edge' => ['etiket' => 'Arayüz · Uç nokta (edge)', 'varsayilan' => true, 'acik' => true],
            'arayuz:no_root_port' => ['etiket' => 'Arayüz · Kök port olmasın', 'varsayilan' => true, 'acik' => true],
            'arayuz:cost' => ['etiket' => 'Arayüz · Maliyet (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'arayuz:priority' => ['etiket' => 'Arayüz · Port önceliği (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'genel:enabled' => ['etiket' => 'Genel · Spanning tree', 'varsayilan' => true, 'acik' => true],
            'genel:bridge_priority' => ['etiket' => 'Genel · Köprü önceliği', 'varsayilan' => true, 'acik' => true],
            'genel:bpdu_block_on_edge' => ['etiket' => 'Genel · BPDU koruması', 'varsayilan' => true, 'acik' => true],
            'genel:storm_control_shutdown' => ['etiket' => 'Genel · Eşik aşılınca portu kapat', 'varsayilan' => true, 'acik' => true],
            'genel:interface_name' => ['etiket' => 'Genel · Arayüz', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_voip' => [
            'voip:vlan_name' => ['etiket' => 'Ses VLAN’ı', 'varsayilan' => true, 'acik' => true],
            'voip:forwarding_class' => ['etiket' => 'İletim sınıfı', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_mirroring' => [
            'analyzer:name' => ['etiket' => 'Aynalama adı', 'varsayilan' => true, 'acik' => true],
            'analyzer:output_interface' => ['etiket' => 'Çıkış arayüzü', 'varsayilan' => true, 'acik' => true],
            'analyzer:output_vlan' => ['etiket' => 'Çıkış VLAN’ı', 'varsayilan' => true, 'acik' => true],
            'analyzer:ratio' => ['etiket' => 'Örnekleme oranı', 'varsayilan' => true, 'acik' => true],
            'analyzer:loss_priority' => ['etiket' => 'Kayıp önceliği', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_secure_access' => [
            'vlan:examine_dhcp' => ['etiket' => 'VLAN · DHCP snooping', 'varsayilan' => true, 'acik' => true],
            'vlan:arp_inspection' => ['etiket' => 'VLAN · ARP denetimi', 'varsayilan' => true, 'acik' => true],
            'vlan:ip_source_guard' => ['etiket' => 'VLAN · IP kaynak koruması', 'varsayilan' => true, 'acik' => true],
            'vlan:mac_move_limit' => ['etiket' => 'VLAN · MAC taşıma sınırı', 'varsayilan' => true, 'acik' => true],
            'vlan:mac_move_action' => ['etiket' => 'VLAN · Sınır aşılınca', 'varsayilan' => true, 'acik' => true],
            'arayuz:vlan_name' => ['etiket' => 'Arayüz · VLAN', 'varsayilan' => true, 'acik' => true],
            'arayuz:dhcp_trusted' => ['etiket' => 'Arayüz · DHCP güven durumu', 'varsayilan' => true, 'acik' => true],
            'arayuz:mac_limit' => ['etiket' => 'Arayüz · MAC sınırı', 'varsayilan' => true, 'acik' => true],
            'arayuz:mac_limit_action' => ['etiket' => 'Arayüz · Sınır aşılınca', 'varsayilan' => true, 'acik' => true],
            'arayuz:allowed_macs' => ['etiket' => 'Arayüz · İzin verilen MAC adresleri', 'varsayilan' => true, 'acik' => true],
            'genel:vlan_name' => ['etiket' => 'Genel · VLAN', 'varsayilan' => true, 'acik' => true],
            'genel:interface_name' => ['etiket' => 'Genel · Arayüz', 'varsayilan' => true, 'acik' => true],
            'firtina:level' => ['etiket' => 'Fırtına denetimi · Eşik (yüzde)', 'varsayilan' => true, 'acik' => true],
            'firtina:bandwidth' => ['etiket' => 'Fırtına denetimi · Eşik (kbps)', 'varsayilan' => true, 'acik' => true],
            'firtina:exemptions' => ['etiket' => 'Fırtına denetimi · Denetim dışında bırak', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_forwarding' => [
            'ornek:instance_type' => ['etiket' => 'Tür', 'varsayilan' => true, 'acik' => true],
            'ornek:description' => ['etiket' => 'Açıklama', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_prefix_lists' => [
            'prefix-liste:cidr' => ['etiket' => 'Ağ', 'varsayilan' => true, 'acik' => true],
            'prefix-liste:apply_paths' => ['etiket' => 'apply-path ifadeleri', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_firewall' => [
            'terim:position' => ['etiket' => 'Terim · Sıra', 'varsayilan' => true, 'acik' => true],
            'terim:source_addresses' => ['etiket' => 'Terim · Kaynak adresler', 'varsayilan' => true, 'acik' => true],
            'terim:destination_addresses' => ['etiket' => 'Terim · Hedef adresler', 'varsayilan' => true, 'acik' => true],
            'terim:source_prefix_lists' => ['etiket' => 'Terim · Kaynak prefix listeler', 'varsayilan' => true, 'acik' => true],
            'terim:destination_prefix_lists' => ['etiket' => 'Terim · Hedef prefix listeler', 'varsayilan' => true, 'acik' => true],
            'terim:protocols' => ['etiket' => 'Terim · Protokoller', 'varsayilan' => true, 'acik' => true],
            'terim:next_headers' => ['etiket' => 'Terim · Sonraki başlık (next-header)', 'varsayilan' => true, 'acik' => true],
            'terim:traffic_classes' => ['etiket' => 'Terim · Trafik sınıfı', 'varsayilan' => true, 'acik' => true],
            'terim:ports' => ['etiket' => 'Terim · Portlar', 'varsayilan' => true, 'acik' => true],
            'terim:source_ports' => ['etiket' => 'Terim · Kaynak portlar', 'varsayilan' => true, 'acik' => true],
            'terim:destination_ports' => ['etiket' => 'Terim · Hedef portlar', 'varsayilan' => true, 'acik' => true],
            'terim:tcp_flags' => ['etiket' => 'Terim · TCP bayrakları', 'varsayilan' => true, 'acik' => true],
            'terim:is_tcp_initial' => ['etiket' => 'Terim · TCP kısayolları', 'varsayilan' => true, 'acik' => true],
            'terim:icmp_types' => ['etiket' => 'Terim · ICMP türleri', 'varsayilan' => true, 'acik' => true],
            'terim:icmp_codes' => ['etiket' => 'Terim · ICMP kodları', 'varsayilan' => true, 'acik' => true],
            'terim:dscps' => ['etiket' => 'Terim · DSCP eşleşmesi', 'varsayilan' => true, 'acik' => true],
            'terim:packet_lengths' => ['etiket' => 'Terim · Paket boyu', 'varsayilan' => true, 'acik' => true],
            'terim:ttls' => ['etiket' => 'Terim · TTL', 'varsayilan' => true, 'acik' => true],
            'terim:interfaces' => ['etiket' => 'Terim · Arayüzler', 'varsayilan' => true, 'acik' => true],
            'terim:precedences' => ['etiket' => 'Terim · IP önceliği (precedence)', 'varsayilan' => true, 'acik' => true],
            'terim:ip_options' => ['etiket' => 'Terim · IP seçenekleri', 'varsayilan' => true, 'acik' => true],
            'terim:fragment_flags' => ['etiket' => 'Terim · Parça bayrakları', 'varsayilan' => true, 'acik' => true],
            'terim:is_fragment' => ['etiket' => 'Terim · Parça koşulu', 'varsayilan' => true, 'acik' => true],
            'terim:ether_types' => ['etiket' => 'Terim · Ether türü', 'varsayilan' => true, 'acik' => true],
            'terim:source_mac_addresses' => ['etiket' => 'Terim · Kaynak MAC adresleri', 'varsayilan' => true, 'acik' => true],
            'terim:destination_mac_addresses' => ['etiket' => 'Terim · Hedef MAC adresleri', 'varsayilan' => true, 'acik' => true],
            'terim:action' => ['etiket' => 'Terim · Sonuç', 'varsayilan' => true, 'acik' => true],
            'terim:goes_to_next_term' => ['etiket' => 'Terim · Sonraki terim', 'varsayilan' => true, 'acik' => true],
            'terim:policer' => ['etiket' => 'Terim · Policer', 'varsayilan' => true, 'acik' => true],
            'terim:forwarding_class' => ['etiket' => 'Terim · İletim sınıfı', 'varsayilan' => true, 'acik' => true],
            'terim:loss_priority' => ['etiket' => 'Terim · Kayıp önceliği', 'varsayilan' => true, 'acik' => true],
            'terim:dscp' => ['etiket' => 'Terim · DSCP damgası', 'varsayilan' => true, 'acik' => true],
            'terim:routing_instance' => ['etiket' => 'Terim · Yönlendirme tablosu', 'varsayilan' => true, 'acik' => true],
            'terim:analyzer' => ['etiket' => 'Terim · Analyzer', 'varsayilan' => true, 'acik' => true],
            'terim:count_name' => ['etiket' => 'Terim · Sayaç adı', 'varsayilan' => true, 'acik' => true],
            'terim:is_logged' => ['etiket' => 'Terim · Kayıt ve kopyalama', 'varsayilan' => true, 'acik' => true],
            'policer:bandwidth_limit' => ['etiket' => 'Policer · Hız sınırı', 'varsayilan' => true, 'acik' => true],
            'policer:bandwidth_percent' => ['etiket' => 'Policer · Hız yüzdesi', 'varsayilan' => true, 'acik' => true],
            'policer:burst_size_limit' => ['etiket' => 'Policer · Burst boyu', 'varsayilan' => true, 'acik' => true],
            'policer:filter_specific' => ['etiket' => 'Policer · Sayaç kapsamı', 'varsayilan' => true, 'acik' => true],
            'policer:is_discard' => ['etiket' => 'Policer · Sınırı aşan pakete', 'varsayilan' => true, 'acik' => true],
            'policer:forwarding_class' => ['etiket' => 'Policer · İletim sınıfına al', 'varsayilan' => true, 'acik' => true],
            'policer:loss_priority' => ['etiket' => 'Policer · Kayıp önceliğini yükselt', 'varsayilan' => true, 'acik' => true],
            'filtre:family' => ['etiket' => 'Filtre · Adres ailesi', 'varsayilan' => true, 'acik' => true],
            'filtre:interface_specific' => ['etiket' => 'Filtre · Sayaç kapsamı', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_policies' => [
            'terim:position' => ['etiket' => 'Terim · Sıra', 'varsayilan' => true, 'acik' => true],
            'terim:match_prefix_lists' => ['etiket' => 'Terim · Eşleşecek prefix listeler (filtre)', 'varsayilan' => true, 'acik' => true],
            'terim:match_type' => ['etiket' => 'Terim · Eşleşme türü', 'varsayilan' => true, 'acik' => true],
            'terim:community_delete' => ['etiket' => 'Terim · Silinecek community', 'varsayilan' => true, 'acik' => true],
            'politika:invert_match' => ['etiket' => 'Politika · Ters eşleşme', 'varsayilan' => true, 'acik' => true],
        ],
        'switch_bgp' => [
            'grup:type' => ['etiket' => 'Grup · Tür', 'varsayilan' => true, 'acik' => true],
            'grup:peer_asn' => ['etiket' => 'Grup · Karşı taraf AS', 'varsayilan' => true, 'acik' => true],
            'grup:local_address' => ['etiket' => 'Grup · Yerel adres', 'varsayilan' => true, 'acik' => true],
            'grup:authentication_key' => ['etiket' => 'Grup · Oturum anahtarı (MD5)', 'varsayilan' => true, 'acik' => true],
            'grup:clear_authentication_key' => ['etiket' => 'Grup · Kayıtlı anahtar', 'varsayilan' => true, 'acik' => true],
            'grup:hold_time' => ['etiket' => 'Grup · Hold-time (saniye)', 'varsayilan' => true, 'acik' => true],
            'grup:cluster' => ['etiket' => 'Grup · Route reflector kümesi', 'varsayilan' => true, 'acik' => true],
            'grup:local_asn' => ['etiket' => 'Grup · Yerel AS (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'grup:multihop' => ['etiket' => 'Grup · Multihop', 'varsayilan' => true, 'acik' => true],
            'grup:multihop_ttl' => ['etiket' => 'Grup · Multihop TTL', 'varsayilan' => true, 'acik' => true],
            'grup:graceful_restart' => ['etiket' => 'Grup · Graceful restart', 'varsayilan' => true, 'acik' => true],
            'grup:out_delay' => ['etiket' => 'Grup · Anons gecikmesi (saniye)', 'varsayilan' => true, 'acik' => true],
            'grup:bfd_minimum_interval' => ['etiket' => 'Grup · BFD aralığı (ms)', 'varsayilan' => true, 'acik' => true],
            'grup:multipath' => ['etiket' => 'Grup · Çoklu yol', 'varsayilan' => true, 'acik' => true],
            'grup:remove_private' => ['etiket' => 'Grup · Özel AS’leri temizle', 'varsayilan' => true, 'acik' => true],
            'grup:as_override' => ['etiket' => 'Grup · AS override', 'varsayilan' => true, 'acik' => true],
            'grup:is_inactive' => ['etiket' => 'Grup · Devre dışı (deactivate)', 'varsayilan' => true, 'acik' => true],
            'grup:advertise_inactive' => ['etiket' => 'Grup · Etkin olmayan yolları anons et', 'varsayilan' => true, 'acik' => true],
            'grup:damping' => ['etiket' => 'Grup · Yol bastırma (damping)', 'varsayilan' => true, 'acik' => true],
            'grup:log_updown' => ['etiket' => 'Grup · Kayıt', 'varsayilan' => true, 'acik' => true],
            'grup:passive' => ['etiket' => 'Grup · Pasif', 'varsayilan' => true, 'acik' => true],
            'grup:allow_prefixes' => ['etiket' => 'Grup · Dinamik komşu ağları', 'varsayilan' => true, 'acik' => true],
            'grup:description' => ['etiket' => 'Grup · Açıklama', 'varsayilan' => true, 'acik' => true],
            'grup:import_policies' => ['etiket' => 'Grup · İçeri alma politikaları', 'varsayilan' => true, 'acik' => true],
            'grup:export_policies' => ['etiket' => 'Grup · Dışarı verme politikaları', 'varsayilan' => true, 'acik' => true],
            'komsu:address' => ['etiket' => 'Komşu · Komşu adresi', 'varsayilan' => true, 'acik' => true],
            'komsu:description' => ['etiket' => 'Komşu · Açıklama', 'varsayilan' => true, 'acik' => true],
            'komsu:peer_asn' => ['etiket' => 'Komşu · Karşı taraf AS (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'komsu:is_inactive' => ['etiket' => 'Komşu · Devre dışı (deactivate)', 'varsayilan' => true, 'acik' => true],
            'komsu:local_address' => ['etiket' => 'Komşu · Yerel adres (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'komsu:authentication_key' => ['etiket' => 'Komşu · Oturum anahtarı (MD5)', 'varsayilan' => true, 'acik' => true],
            'komsu:clear_authentication_key' => ['etiket' => 'Komşu · Kayıtlı anahtar', 'varsayilan' => true, 'acik' => true],
            'komsu:hold_time' => ['etiket' => 'Komşu · Hold-time (saniye)', 'varsayilan' => true, 'acik' => true],
            'komsu:passive' => ['etiket' => 'Komşu · Pasif', 'varsayilan' => true, 'acik' => true],
            'komsu:multihop' => ['etiket' => 'Komşu · Multihop', 'varsayilan' => true, 'acik' => true],
            'komsu:multihop_ttl' => ['etiket' => 'Komşu · Multihop TTL', 'varsayilan' => true, 'acik' => true],
            'komsu:import_policies' => ['etiket' => 'Komşu · İçeri alma politikaları (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'komsu:export_policies' => ['etiket' => 'Komşu · Dışarı verme politikaları (isteğe bağlı)', 'varsayilan' => true, 'acik' => true],
            'karsi-taraf:peer_config' => ['etiket' => 'Karşı taraf · Karşı tarafın configi', 'varsayilan' => true, 'acik' => true],
            'karsi-taraf:peer_interface' => ['etiket' => 'Karşı taraf · Karşı tarafın bacak adresi', 'varsayilan' => true, 'acik' => true],
            'karsi-taraf:our_config' => ['etiket' => 'Karşı taraf · Bizim taraf', 'varsayilan' => true, 'acik' => true],
        ],
    ],
];
