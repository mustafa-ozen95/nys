# Örnek yapılandırma fixture'ları

`ornek-*.xml` dosyaları gerçek Junos çıktılarının yapısını taklit eden anonim
örneklerdir. Bir omurga (EX4500) ve bir kabin switchi (EX2200) tipindeki
yapılar `ornek-korpus*.set` içinde set satırı olarak durur; bu satırlar bir lab
cihazının (test1) ÖZEL adayına yüklenip cihazın ürettiği XML biçimi
kaydedilerek üretilir. Commit atılmaz. Adlar, adresler ve eşikler anonimdir
(belge aralıkları, `tablo-a`, `filtre-a` gibi nötr adlar).

Böylece sınanan şey "bizim beklediğimiz XML" değil, cihazın o yapıyı yazdığı
biçimdir. Panelin okuyucuları buna karşı
`tests/Unit/Netconf/OrnekKonfigurasyonTest.php` içinde çalıştırılır.

Kapsam: politika, prefix list, firewall, routing-options, routing-instances,
protocols, vlans, ethernet-switching-options ve **interfaces** (tek birimde
çok adres, IPv4+IPv6 bir arada, /128, ae üstünde filtre bağlaması, L3
arayüzü).

Bu yöntemle bulunan hatalar:

- `then routing-instance` sarmalanmış yazılıyor; düz metin arayan okuyucu onu
  NULL görüyordu ve filtre düzenlemesi eylemi tamamen siliyordu.
- `deactivate` işareti öznitelikte geliyor; okunmadığı için devre dışı BGP
  grupları panelde etkin görünüyordu.
- `from ttl` hiç modellenmiyordu.

## Yenilemek

`ornek-korpus*.set` dosyalarına yeni satır ekleyip üretim betiğini yeniden
çalıştırmak yeterli. Aday özeldir ve oturum kapanınca düşer. Korpusa eklenen
değerler de anonim olmalı: XML ile korpus aynı değeri taşır.

## Gizli veri

Korpus parola, SNMP community ve arşiv hedefi İÇERMEZ. Dosyalardaki
`community` satırları BGP politika community'leridir (64496:999 gibi),
SNMP değil.
