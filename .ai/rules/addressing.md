# Adresleme — IP adresi girilen her yer

## Adres elle yazılmaz, listeden seçilir

Panelde bir IPv4 adresi ya da ağ girilen her alan `x-form.ip-picker`
kullanır. Kaynak tektir: Adresleme'de tanımlı, `is_subnetted = false` olan
bloklar. Listeyi `App\Services\Addressing\AddressCandidateService` üretir:

- `hostOptions([AddressPurpose ...])` — blok başlığına göre gruplanmış host
  adresleri. Bir oturumun iki ucu (yerel + komşu) aynı grubun içinde görünür.
- `blockOptions([...])` — ağ seçilen yerler (prefix list girişi) için
  `cidr => etiket`.

Servis istek boyunca blokları TEK kez okur. Bir ekranda birden fazla adres
alanı olduğunda (yönetim IP'si + uplink IP'si) fazladan sorgu çıkmaz;
`NetworkSwitchCrudTest` sorgu sayısını bu varsayımla kilitler.

Serbest yazım şu üç sorunu üretir ve bu yüzden kullanılmaz: envanterde
karşılığı olmayan adres, aynı adresin iki yere verilmesi, bir bloğun gerçekte
kullanılıp kullanılmadığının Adresleme'den okunamaması.

## Elle giriş yalnızca belgelenmiş özel durumda

`manual` özniteliği açılan her alanın yanında NEDEN açıldığı yorumla yazılır.
Bugün açık olanlar ve gerekçeleri:

| Ekran / alan | Havuz | Gerekçe |
| --- | --- | --- |
| BGP grup ve komşu: yerel adres, komşu adresi | Peering + Uplink | Sağlayıcının kendi adresi havuzumuzda yer almaz |
| Switch kaydı: yönetim IP, uplink IP | Yönetim / Uplink + Peering | Yeni cihaz envantere alınırken adresi henüz Adresleme'de olmayabilir |
| Switch route: sonraki atlama | Uplink + Peering | Sağlayıcının ağ geçidi havuzda değildir |
| Prefix list: ağ | Bütün bloklar | Liste bizim olmayan bir ağı da taşıyabilir (sağlayıcı özet prefixi) |
| Config yükle: yönetim adresi, ağ geçidi | Yönetim | Kurulan cihaz henüz envanterde olmayabilir |
| Sunucu: adres | Yönetim + Müşteri | Alan adı da kabul edilir |
| Adres bloğu: sonraki atlama | Envanterdeki uplink IP'leri | Müşteri switchleri envanterde değildir |
| Config yükle: dış cihaz IP'si | — | Hedef zaten envanter dışıdır; havuz kavramı yoktur |

Yeni blok tanımlanan alan (`addressing.index` → "ağ adresi") bu listede
DEĞİLDİR: havuzun kendisi orada doğar, kaynağı kendisi olamaz.

Switchin arayüz adresleri (`switches.ip-addresses`) bu listede YOKTUR: orada
adres yalnızca o switche atanmış bloklardan seçilir, maske bloktan gelir.

## Cihazdan okunan adres her zaman düzenlenebilir

`switches.ip-addresses` ekranında bir adresin bloğu silinmiş ya da hiç
tanımlanmamış olabilir; adres yine de cihazda durur.
`SwitchAddressCandidateService::withCurrent()` kaydın kendi adresini listeye
"Cihazdaki adres" başlığıyla ekler, `SwitchIpAddressRequest::prefixLength()`
maskeyi kayıttan alır.

Bu olmadan alan hiç çizilmez: kullanıcı yalnızca portu değiştirmek
istediğinde bile kaydı açamaz.

## Elle giriş kutusu ile liste aynı adı taşır

`x-form.ip-picker` içinde `<select>` ve `<input>` aynı `name` değerini
kullanır ve her an YALNIZCA BİRİNDE `name` niteliği bulunur. Form Request
tarafında hiçbir şey değişmez ve JavaScript çalışmasa bile doğru değer
gönderilir.

**Mekanizma adrese özgü DEĞİL.** Aynı ihtiyaç AS numarası için de var
(müşteri anonsu); mekanizma `App\View\Components\Form\Picker`
ile ortak `x-form.picker` bileşenindedir; `IpPicker` yalnızca ADRESE ÖZGÜ
metinleri taşıyan bir alt sınıftır. İşaretleme `data-secim`, `data-secim-liste`
ve `data-secim-elle` kullanır, JS
`resources/js/components/selection.js` içindedir.

Yeni bir "listeden seç ya da elle gir" alanı için `x-form.picker` kullanılır;
mekanizma ikinci kez yazılmaz. `resources/js/components/selection.js` yalnızca
liste ile elle giriş arasındaki devri yapar, doğrulama yapmaz.

## Blok → VLAN bağı kayıtla kurulur

`address_blocks.vlan_id`, `vlans` tablosuna yabancı anahtardır. Etiketle
(`vlan_tag`) işaret etmenin üç sonucu olurdu ve üçü de sessiz:

- Etiketsiz VLAN hedef olamazdı. Junos `vlans <ad>` satırını vlan-id
  olmadan kabul eder; kabin switchlerinde üstünde müşteri IP'si taşıyan
  etiketsiz VLAN'lar olabilir.
- Cihazda etiket değişirse bağ başka bir VLAN'a kayardı.
- VLAN silinip aynı etiketle yenisi açılırsa blok yeni VLAN'a bağlanmış
  görünürdü.

Yazılacak arayüz adı da VLAN kaydının `l3_interface` alanından okunur,
etiketten TÜRETİLMEZ — birim numarası etikete eşit değildir ve ELS'te arayüz
`irb`dir. Ayrıntı: `.ai/rules/junos.md`, "l3-interface birimi ≠ vlan-id".

Bloğa bağlı VLAN silinemez (`DependencyGuard::blocksForVlan`): yabancı anahtar
`nullOnDelete` olduğu için bağ sessizce düşerdi.

L3 arayüzü olmayan VLAN'a blok bağlanamaz. Aynı koruma IP adresleri ekranında
da var; iki ekran da aynı cihaz satırına yazıyor ve ikisi de korunmalı.
