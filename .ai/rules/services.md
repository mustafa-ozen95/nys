---
paths:
  - 'app/Services/**'
---

# Services

## Ağ switchlerına asla izinsiz bağlanılmaz
Switch, router ve sunuculara (SNMP, SSH, NETCONF, telnet — hangi protokol olursa olsun) izin alınmadan bağlanılmaz; geliştirme sırasında hiçbir gerçek switchte deneme yapılmaz.

Kütüphane denemeleri, protokol testleri ve geliştirme daima sahte/kaydedilmiş veriyle yapılır. Bağlantı hatası senaryoları 127.0.0.1 gibi servis olmayan adreslerle sınanır, gerçek switchla değil.

Zabbix API'den okuma ayrı bir izindir; switche bağlanma iznini kapsamaz.

Testlerde Http::preventStrayRequests() etkindir (tests/TestCase.php); SNMP/SSH/NETCONF okuyucuları da aynı şekilde sahte uygulamayla değiştirilebilir olmalı — üretim sınıfı testte asla gerçek bağlantı açmamalı.

## Zabbix'e yazılmaz
Panel Zabbix'ten yalnızca okur: host.get, item.get, history.get, trend.get. Host,
item, şablon, tetikleyici ya da bakım penceresi değiştiren bir API çağrısı eklenmez.
