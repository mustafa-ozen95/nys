<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SNMP
|--------------------------------------------------------------------------
| Cihazlardan yalnızca OKUMA yapılır. Yazma (SET) hiçbir yerde yoktur ve
| eklenmemelidir.
|
| PHP'nin snmp eklentisi kurulu değil; NET-SNMP komutları kullanılır. Bu,
| ttyd/sshpass'te olduğu gibi mevcut bir desendir ve sisteme yeni paket
| gerektirmez.
*/

return [

    /*
    | SNMP kullanılsın mı. Kapatıldığında snmpwalk hiç çağrılmaz; IP adresi
    | ve VLAN okumaları NETCONF'a kalır.
    */
    'enabled' => (bool) env('SNMP_ENABLED', true),

    'binary' => env('SNMPWALK_BINARY', '/usr/bin/snmpwalk'),

    /*
    | Eşitleme işleri Zabbix işleriyle aynı kuyruğu paylaşır: tek bir işçi
    | süreci hepsini işler. Ayrı kuyruğa alınırsa ikinci bir supervisor
    | süreci gerekir ve işler sessizce beklemede kalır.
    */
    'queue' => env('SNMP_QUEUE', env('ZABBIX_QUEUE', 'nms-switch-sync')),

    // Saniye. Cihaz yanıt vermezse iş uzun sürmesin.
    'timeout' => (int) env('SNMP_TIMEOUT', 3),
    'retries' => (int) env('SNMP_RETRIES', 1),

    // Tek bir walk'ın döndürebileceği en fazla satır. Beklenmedik biçimde
    // büyük bir tabloya (route gibi) rastlanırsa iş kendini durdurur.
    'max_rows' => (int) env('SNMP_MAX_ROWS', 5000),

    /*
    | Hepsi bir EX2200 (JunOS) erişim switchi üzerinde snmpwalk ile doğrulandı.
    |
    | Standart Q-BRIDGE tabloları bu cihazda kullanılamaz:
    |   dot1qVlanFdbId               -> No Such Object
    |   dot1qVlanStaticEgressPorts   -> tamamı sıfır
    |   dot1qVlanStaticUntaggedPorts -> tamamı sıfır
    | Bu yüzden VLAN verisi Juniper'ın kendi MIB'inden alınır.
    */
    /*
    | Juniper'ın iç arayüzleri. Bunların adresleri envanterde işe yaramaz:
    | bme yönetim omurgası, vcp sanal şasi bağlantısı, diğerleri tünel ve
    | paket iletim sözde arayüzleridir.
    */
    'ignored_interfaces' => [
        'bme', 'vcp', 'jsrv', 'pfe', 'pfh', 'lsi', 'dsc',
        'tap', 'gre', 'ipip', 'mtun', 'pimd', 'pime', 'esi',
    ],

    'oids' => [

        /*
        | Ölçüm OID'leri. Hepsi bir EX4200 (12.3R12.4) üzerinde snmpwalk ile
        | doğrulandı.
        |
        | jnxOperating* tablosunda her şasi bileşeni için bir satır vardır ve
        | çoğu sıfır döner (fan, güç kaynağı); anlamlı değer en yüksek
        | olandır — izleme sisteminin yaptığı da budur.
        */
        'sys_uptime' => '1.3.6.1.2.1.1.3',
        'cpu' => '1.3.6.1.4.1.2636.3.1.13.1.8',
        'memory' => '1.3.6.1.4.1.2636.3.1.13.1.11',
        'temperature' => '1.3.6.1.4.1.2636.3.1.13.1.7',

        // Arayüz durumu ve sayaçları. İndeks doğrudan ifIndex'tir.
        'if_oper_status' => '1.3.6.1.2.1.2.2.1.8',
        'if_high_speed' => '1.3.6.1.2.1.31.1.1.1.15',
        'if_duplex' => '1.3.6.1.2.1.10.7.2.1.19',
        'if_in_octets' => '1.3.6.1.2.1.31.1.1.1.6',
        'if_out_octets' => '1.3.6.1.2.1.31.1.1.1.10',

        // JUNIPER-VLAN-MIB. İndeks Juniper'ın iç VLAN indeksidir, etiket değil.
        'vlan_name' => '1.3.6.1.4.1.2636.3.40.1.5.1.5.1.2',
        'vlan_tag' => '1.3.6.1.4.1.2636.3.40.1.5.1.5.1.5',
        'vlan_l3_interface' => '1.3.6.1.4.1.2636.3.40.1.5.1.6.1.5',

        // jnxExVlanPortGroupTable, indeks <vlanIdx>.<bridgePort>
        'vlan_port_status' => '1.3.6.1.4.1.2636.3.40.1.5.1.7.1.3',
        'vlan_port_tagness' => '1.3.6.1.4.1.2636.3.40.1.5.1.7.1.4',

        // Bridge port -> mantıksal ifIndex
        'bridge_port_ifindex' => '1.3.6.1.2.1.17.1.4.1.2',

        // Portun etiketsiz VLAN'ı; bu cihazda gerçek etiketi döndürüyor.
        'port_pvid' => '1.3.6.1.2.1.17.7.1.4.5.1.1',

        /*
        | ifStackTable, indeks <ustIfIndex>.<altIfIndex>.
        | JunOS'ta VLAN üyesi olan mantıksal birimdir (ge-0/0/1.0, tip 53);
        | panel fiziksel portu tutar (ge-0/0/1, tip 6). Çeviri buradan.
        */
        'if_stack' => '1.3.6.1.2.1.31.1.2.1.3',

        // ifName. IP adresleri çoğu zaman L3 arayüzündedir (vlan.32, irb.0);
        // bunlar fiziksel port listesinde olmadığı için adları buradan alınır.
        'if_name' => '1.3.6.1.2.1.31.1.1.1.1',

        // IP-MIB. Adres OID indeksindedir.
        'ip_ifindex' => '1.3.6.1.2.1.4.20.1.2',
        'ip_netmask' => '1.3.6.1.2.1.4.20.1.3',

        // JUNIPER-CFGMGMT-MIB jnxCmCfgChgEventTable
        'commit_time' => '1.3.6.1.4.1.2636.3.18.1.7.1.2',
        'commit_source' => '1.3.6.1.4.1.2636.3.18.1.7.1.4',
        'commit_user' => '1.3.6.1.4.1.2636.3.18.1.7.1.5',
    ],
];
