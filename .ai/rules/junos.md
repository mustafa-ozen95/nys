---
paths:
  - 'app/Services/Netconf/**'
  - 'app/Http/Requests/Switching/**'
  - 'app/Http/Controllers/Switch*'
  - 'app/Http/Controllers/VlanController.php'
  - 'resources/views/switches/**'
---

# Junos kısıtları (EX3200/EX4200, 12.3R12.4)

Panel yalnızca cihazın kabul ettiğini önermeli. Buradaki her madde ya Juniper
belgesinden ya da doğrudan cihazda denenerek doğrulandı; tahmin yok.

Yeni bir alan eklerken sorulacak soru: **cihaz bunu kabul eder mi, ve hangi
başka alanla çelişir?** Cevap belgeden ya da `commit-configuration check`'ten
alınır — sahte veriyle değil.

## Birbirini dışlayan alanlar
Bu ikililer aynı anda ayarlanamaz; formda da tek alan olarak sorulmalıdır.

| Alan | Çelişki |
| --- | --- |
| Hız ↔ auto-negotiation | İkisi de `ether-options speed` düğümüdür. Tek seçim: hız verilirse otomatik kapanır. |
| ethernet-switching ↔ inet | "When ethernet-switching family is configured on an interface, no other family type can be configured on the same interface." Porta IP vermek onu ROUTED yapar ve VLAN üyeliğini düşürür. |
| Access modu ↔ çoklu VLAN | Access portta tek üye olabilir: "Access interface \<ge-0/0/9.0\> has more than one vlan member". Tek istisna VoIP voice VLAN'ıdır, onu yönetmiyoruz. |
| Access modu ↔ ayrı etiketsiz VLAN | Access portta tek üye zaten etiketsizdir; ayrıca sorulmaz. |

## Etiketsiz VLAN moda göre farklı yazılır
- **Access**: tek üye etiketsizdir, ek satır yok.
- **Trunk**: `native-vlan-id <etiket>` ARAYÜZ seviyesinde yazılır (unit'in değil,
  interface'in altında) ve o VLAN üye listesinde de durur. Yalnızca üye
  listesine yazılırsa etiketli olur — sessiz bir hata.

## Donanım yeteneği cihazdan sorulur
PoE her portta yoktur: bu kasalarda ilk sekiz port besler. Liste
`get-poe-interface-information` ile alınır ve `switch_ports.is_poe_enabled`
null ise porta PoE alanı hiç çizilmez. Şasi de "EX4200-24T, 8 POE" der.

Port listesinin kaynağı da donanımdır: yapılandırmada ge-0/1/* ve xe-0/1/*
uplink modülü tanımlı ama modül takılı değil, o portlar envantere girmez.

## VLAN
- Etiket 1–4094. 0 ve 4095 Junos'ta ayrılmıştır; 1 varsayılan VLAN'dır.
- Ad harfle başlamalı: `90vlan` cihazda "statement creation failed" verir.
- `all` ayrılmış anahtar sözcüktür, `default` cihazda zaten var olan VLAN'ın
  adıdır; ikisi de kullanılamaz.
- Ad DEĞİŞTİRİLEMEZ: cihazda VLAN'ın kimliği adıdır ve port üyelikleri adla
  tutulur. Yeniden adlandırma = sil + yarat + bütün üye portları yeniden yaz.
- `l3-interface vlan.N` verilecekse `interfaces vlan unit N` AYNI yüklemede
  tanımlanmalı, yoksa "Interface must already be defined under
  [edit interfaces]".
- VLAN silinmeden önce üye portlardan çıkarılmalı; referans kalırsa commit
  reddedilir.

## Port
- Üyelik boş bırakılabilir: kullanılmayan port hiçbir VLAN'a ait olmaz.
- Yazılabilen hızlar yalnızca 10/100/1000 (`config/netconf.php` → speeds).
  2000/10000/25000 gibi değerleri cihaz reddeder; listede yer almazlar.
- Port modu yalnızca access ya da trunk sorulur. "routed" `family inet`
  yapılandırmasıdır (IP adresleri ekranı), "unconfigured" bir seçim değil,
  hiç yapılandırılmamış olma hâlidir.

## IP adresi
- Normal kullanım adresi VLAN'ın L3 arayüzüne (`vlan.N`) vermektir.
- Bir arayüzün son adresi silinirken `family inet` de kaldırılmalı; yoksa
  cihazda boş `family inet;` satırı kalır.

## Maskeler
Maske hiçbir formda elle yazılmaz, dropdown'dan seçilir
(`AddressAllocationService::maskOptions()`). Route formunda varsayılan route
için /0 da gerekir, aralık sıfırdan başlar.

## Cihaza ait alanlar formda sorulmaz
Model, seri numarası ve JunOS sürümü cihazdan gelir (get-chassis-inventory /
get-software-information) ve eşitleme yazar. Formda sorulsalardı kullanıcının
yazdığı değer bir sonraki eşitlemede sessizce ezilirdi. Aynı kural her
"eşitleme dolduruyor" alanı için geçerlidir.

## Panelden dokunulamayacak arayüzler
me0 ve vme yönetim arayüzüdür; adresi panelden değiştirilirse panelin cihaza
erişimi kesilir. lo0, bme ve vcp iç arayüzlerdir ve formda karşılıkları yoktur.
Bu adresler listede görünür ama düzenlenemez (`ManageableInterface`), istek
sunucuda da reddedilir — sadece düğmeyi gizlemek yetmez.

Genel kural: form yalnızca temsil edebildiği şeyi düzenlettirir. Temsil
edemediği bir kaydı düzenlemeye açmak, kullanıcının yanlış bir seçim yapıp
cihaza beklemediği bir satır yazmasına yol açar.

## Ekranda aynı bilgi iki kez yazılmaz
Öğrenilen route'larda eylem hücresi boştur; protokol rozeti (Doğrudan, Yerel,
Diğer) zaten yolun cihazdan geldiğini söyler. Kilitli bir satırın SEBEBİ
rozetten anlaşılmıyorsa yazılır (IP ekranında "Yönetim arayüzü" gibi),
anlaşılıyorsa yazılmaz.

## Adresleme ekranı: yöntem önce sorulur
Alan sırası bilinçlidir: önce **Yöntem**, sonra omurga switch, sonra yöntemin
gerektirdiği alanlar. Yöntem seçilmeden switch sormak, kullanıcıya işe
yaramayacak bir seçim yaptırır.

| Yöntem | Zorunlu | Hedef nereden okunur |
| --- | --- | --- |
| VLAN | en az bir switch + VLAN | erişim seçiliyse ondan, değilse omurgadan |
| Port | en az bir switch + port | erişim seçiliyse ondan, değilse omurgadan |
| Static Route | sonraki atlama IP'si | switch gerekmez |
| Prefix list | OMURGA switch + liste | omurgadan |

VLAN ve port yalnızca erişim switchinde olmak zorunda değil, omurgada da
olabilir. Erişim switchi boş bırakılırsa hedef listesi omurgadan doldurulur ve
doğrulama da ona bakar; hiç switch seçilmezse hata omurga alanında raporlanır.

Static route hedefi iki yoldan gelir: envanterdeki bir switchin uplink adresi
(dropdown) ya da elle girilen IP. Elle giriş şarttır — müşteri switchleri
envanterde yoktur.

Prefix list omurga switchte aranır:
`set policy-options prefix-list musteri1-PREFIXES 192.0.2.0/24`. BGP orada
yönetilir; erişim switchiyle ilgisi yoktur. Seçilen liste seçilen omurga
switche ait olmalı.

**Anons ayrı bir alan değildir**: blok omurgadaki bir prefix list'e girmişse
anons ediliyordur. Ne "anons" kutusu ne de ASN sorulur — anons e-postasının
kendi ASN alanı vardır. /24'ten dar prefix anons edilemez kuralı prefix list
yöntemi seçiliyken geçerlidir.

## Form görünürlüğü TEK yerde hesaplanır
Adres bloğu formunda iki ayrı mekanizma (yönteme göre gizle, adıma göre aç)
birbirini ezer: yöntem VLAN iken "Port" alanı, erişim switchi seçildiği için
geri açılır. Bu yüzden bir alan ancak BÜTÜN koşulları sağlıyorsa görünür:

| Öznitelik | Koşul |
| --- | --- |
| `data-atama-alani` | seçili yöntem bu listede mi |
| `data-atama-hedef` | seçili yöntem tam olarak bu mu |
| `data-atama-atlama-alani` | seçili sonraki-atlama kaynağı bu mu |
| `data-atama-adim` | önceki soru cevaplandı mı |

Gizlenen alanın girdileri devre dışı bırakılır; açık kalan alan sunucuya
çelişkili değer gönderir.

## Arayüzdeki her kısıt sunucuda da vardır
Alanı gizlemek, düğmeyi saklamak, listeyi filtrelemek — hiçbiri koruma
değildir; istek doğrudan da gelebilir. Her kısıt iki yerde durur:

| Arayüzde | Sunucuda |
| --- | --- |
| Yazma düğmeleri yalnızca yetkiliye görünür | `can:configure,switch` middleware |
| Öğrenilen route'ta düzenle yok | `abort_unless(isWritable)` |
| me0 satırı kilitli | `abort_unless(isManageable)` |
| PoE alanı yalnızca destekleyen portta | `prohibitedIf(is_poe_enabled === null)` |
| Access portta tek VLAN | `max:1` + `untagged` `prohibited` |
| Yönteme ait olmayan alan gizli | ilgili alanlarda `prohibitedIf` |
| Omurga/erişim listeleri role göre | `exists(...)->whereIn('role', ...)` |
| Cihazın kabul etmediği hız listede yok | `Rule::in(config('netconf.speeds'))` |

Yönteme ait olmayan alan sessizce yok SAYILMAZ, reddedilir: kullanıcı
gönderdiği değerin kaydedildiğini sanmamalı.

`tests/Feature/Security/DeviceWriteGuardTest.php` bütün yazma uçlarını
tabloya bağlar: "görüntüle + düzenle" yetkisiyle hiçbiri geçmez ve reddedilen
istekte cihaza bağlantı bile açılmaz.

## Onay kutusu aranırken TÜRÜ de belirtilir
İşaretsiz kutunun "0" göndermesi için her kutunun önünde AYNI ADI taşıyan
gizli bir input durur:

```blade
<input type="hidden" name="multihop" value="0">
<input type="checkbox" name="multihop" value="1">
```

`querySelector('[name="multihop"]')` bu yüzden GİZLİ inputu bulur ve kutu her
zaman "işaretsiz" görünür. Sorguya `[type="checkbox"]` eklenmeli. Bu hata
(ör. BGP grup formunda multihop açılınca TTL alanının açılmaması) sunucu
testlerinden geçer, yalnızca tarayıcı testi yakalar.

## Sayısal dize dizi anahtarı olurken int'e döner
`array_combine(['65000'], ['65000'])` anahtarı **int** yapar. `?string`
döndüren bir metot (ör. `AsnDirectory`) bu anahtarı döndürmeye çalışırsa ekran
500 verir. Cihazdan gelen AS
numaraları sayısal olduğu için bu her ASN listesinde geçerli: anahtar üretirken
`(string)` ile geri çevrilmeli.

## Arayüz hataları tarayıcı testiyle yakalanır
Bu sınıf hata sunucu testlerinden geçer: alan HTML'de vardır, yalnızca yanlış
anda görünür. `tests/Browser/specs/step-by-step-form.spec.js` adım adım açılmayı
sınar. **JS değiştiyse `npm run build` çalıştırılmadan hiçbir şey
değişmez**.

Omurga ve erişim seçicileri aynı listeyi göstermez: alanın adı rolü
söylüyorsa listede başka roldeki switch bulunmaz ve doğrulama da rolü
kontrol eder (listeyi filtrelemek yetmez, istek doğrudan da gelebilir).

Panelin modelinde iki rol var: omurga ve erişim. Dağıtım katmanı YOK — `SwitchRole`
enum'ında da yok. Olmayan bir rolü listede tutmak kullanıcıya yanlış seçenek
sunar.

`AddressBlockService` alan adlarını tek tek sayar; yeni bir kolon eklerken
hem `create` hem `update` listesine yazılmalı, yoksa değer sessizce düşer.

## BGP

BGP verisi cihazdan okunan `bgp_groups`, `bgp_group_families` ve
`bgp_neighbors` tablolarındadır. Elle girilen bir oturum tablosu yoktur: durumu
ekranda gösterilip hiçbir işin yazmadığı bir tablo yanıltır.

### Lisans uyarıdır, engel değil
EX4200/EX3200'de BGP lisansı yok ve her commit'te
`mgd: requires 'bgp' license` gelir. Bu satırın `error-severity` değeri
**`warning`**'dir; `<commit-success/>` yine döner ve BGP gerçekten çalışır.
İşi durdurmayın. `set system license override-license-check` bu sürümde YOKTUR
(SRX ve daha yeni Junos'ta var), aramayın.

### Sırayla doğrulanmış kısıtlar
| Kısıt | Cihazın söylediği |
| --- | --- |
| AS numarası olmadan BGP olmaz | "must define local autonomous system when enabling BGP" |
| Politika önce tanımlı olmalı | "Policy error: Policy p1 referenced but not defined" |
| Komşu adresi switch genelinde tekil | aynı adres iki grupta duramaz |
| internal grupta `peer-as` | KABUL EDİLİR, yasak değil |
| internal grupta `multihop` | KABUL EDİLİR |
| Komşusuz grup | KABUL EDİLİR |

Bu yüzden grup formundaki politika listesi serbest metin DEĞİLDİR;
`switch_policy_statements` tablosundan doldurulur ve doğrulama da oraya bakar.

### Aynı ayarın iki yazımı vardır, ikisi de korunmalı
- `multihop` ttl'siz kullanılırsa **boş eleman** olarak döner
  (`<multihop></multihop>`); metnine bakan kod bunu "kapalı" sanar.
- `idle-timeout forever` da **boş elemandır**; dakikalı hâlinde altında
  `<timeout>` bulunur. İkisi ancak alt eleman kontrolüyle ayrılır.
- Prefix sınırı `family inet` altında **`any`, `unicast` ya da `multicast`**
  düğümünün altında durabilir. Hangisi olduğu `bgp_group_families.nlri` ile
  saklanır: saklanmasaydı "unicast" ile okunan bir grup düzenlendiğinde "any"
  olarak geri yazılır, kullanıcının dokunmadığı satır sessizce değişirdi.
- `local-as` düz değer değildir: `<local-as><as-number>N</as-number></local-as>`.
- Junos `prefix-list-filter` içinde alanı ALT ÇİZGİYLE yazar: `<list_name>`.
  Şemanın geri kalanı tire kullandığı için gözden kaçar.

### Panelin BGP kapsamı

Grup seviyesinde yazılan ve okunan alanlar:

| Alan | Junos | Not |
| --- | --- | --- |
| tür, peer-as, local-as, local-address | `type`, `peer-as`, `local-as`, `local-address` | |
| oturum anahtarı | `authentication-key` | Cihaz `$9$...` döner; şifreli saklanır, `$hidden`, farkta maskelenir |
| hold-time | `hold-time` | 0 ya da 3-65535; 1 ve 2 cihazca reddedilir (bkz. `App\Rules\HoldTime`) |
| route reflector | `cluster` | iBGP'de anlamlı; eBGP'de de kabul edildiği için yasaklanmadı |
| graceful restart | `graceful-restart` / `graceful-restart disable` | ÜÇ durumlu: satır yok ≠ kapalı |
| remove-private, as-override, advertise-inactive, multipath, damping | boş elemanlar | |
| anons gecikmesi | `out-delay` | |
| BFD | `bfd-liveness-detection minimum-interval` | Aralıksız düğüm commit hatası verir; verilmezse hiç yazılmaz |
| dinamik komşu | `allow <ağ>` | Birden fazla olabilir; her satır maskeye hizalı olmalı |
| multihop, log-updown, passive, import, export, aileler | | |

Komşu seviyesinde AYNI ayarların çoğu tekrar yazılabilir ve komşudaki değer
grubunkini EZER: `authentication-key`, `hold-time`, `passive`, `multihop`,
`import`, `export`. Sağlayıcı başına ayrı MD5 anahtarı en yaygın kullanımdır.

**Yazılan her alan OKUNMAK ZORUNDA.** Güncelleme "önce yönetilen alanları sil,
sonra yaz" akışıdır ve yalnızca panelin bildiği alanları geri yazar; okunmayan
bir alan cihazda elle kurulmuş olsa bile ilk güncellemede sessizce silinirdi.
`BgpConfigurator::GROUP_RESET` / `NEIGHBOR_RESET` ile `BgpReader` birlikte
değişir.

Kapsam dışı bırakılanlar ve nedeni: confederation (bu ağda karşılığı yok),
`family inet flow` / `labeled-unicast` / EVPN / L3VPN (EX4200 12.3 desteklemez),
keychain tabanlı kimlik doğrulama (`authentication-key-chain`), traceoptions.

### Komşu adresi ve grup adı cihazda KİMLİKTİR

İkisi de sonradan değiştirilemez. Güncelleme belgesi her zaman eski adı/adresi
taşır; formda değiştirilmiş bir değer gönderilirse SESSİZCE yok sayılırdı.
Bu yüzden ikisi de formda salt okunur gösterilir ve sunucu değişmiş değeri
açıkça reddeder. Gerçekten değişecekse kayıt silinip yeniden eklenir.

### Kapsayıcıyı silmek çalışmaz
`<family delete="delete"/>` cihazda **sessizce hiçbir şey yapmıyor**: ne hata
ne uyarı veriyor, aile olduğu gibi kalıyor. Gerçek cihazda görüldü: grup
güncellemesinde kaldırılan `inet6` ailesi ve `teardown` ayarı yerinde kalır. Aileler alt elemanlarıyla tek tek silinmeli
(`<inet delete="delete"/><inet6 delete="delete"/>`).

Genel kural: `merge` yalnızca EKLER. Bir alanı boşaltmak için önce silinmeli;
grup güncellemesi bu yüzden iki belgedir ve TEK commit'te uygulanır. Grubun
KENDİSİ silinmez — silinseydi altındaki komşular da giderdi.

### Politika kısıtları (cihazda doğrulandı)
| Kısıt | Cihazın söylediği |
| --- | --- |
| `prefix-list-filter` eşleşme türü ZORUNLU | "syntax error, expecting \<flag-value\> or \<attribute-value\>" |
| Community önce tanımlı olmalı | "topluluk-a community referenced (in term clear) but not defined" |
| Terimsiz politika sessizce yok sayılır | "mgd: statement has no contents; ignored" |

Eşleşme türü üç değerden biridir: `exact`, `longer`, `orlonger`. Boolean bir
alan `exact` ile `longer`ı ayırt edemez; bu yüzden `match_type` gerçek bir
enum'dur.

Terimsiz politika sessizce yok sayıldığı için panel politikayı İLK TERİMİYLE
yaratır ve son terimin silinmesine izin vermez — izin verseydi politika
cihazda kaybolur, ona dayanan BGP grubu farkında olmadan politikasız kalırdı.

Terim SIRASI anlamlıdır ve `merge` yeni terimi hep SONA ekler; bu yüzden
politika her değişiklikte silinip bütün terimleriyle yeniden yazılır (tek
commit). Bu yol BGP grubunda kullanılamaz: grubu silmek komşularını da silerdi.

### Politikalar yarım anlaşılmaz
Junos politika dili çok geniştir (as-path, route-filter, community add,
next-hop). Panel bir alt kümesini temsil eder; tanımadığı bir yapı görürse
politikayı `is_manageable = false` ile işaretler ve salt okunur bırakır.
Yarısını anlayıp geri yazmak, kullanıcının dokunmadığı satırları silerdi.


## Prefix list ataması CİHAZA yazılır
Adresleme ekranında "prefix list" yöntemi seçmek, omurga switchteki
listeye gerçek bir satır yazar:

```
set policy-options prefix-list <liste> <blok>/<maske>
```

Yalnızca envantere yazmak, panelin "bu blok anons ediliyor" deyip cihazda o
satırın olmaması demektir. Panelin söylediği ile cihazın gerçeğinin ayrılması
en zararlı hata sınıfıdır — kimse fark etmez.

Atama kaldırıldığında, başka listeye taşındığında ve blok silindiğinde satır
cihazdan da ÇIKARILIR. Envanter ile cihaz TEK işlemde değişir: cihaza yazma
düşerse envanter değişikliği de geri alınır.

Cihaza yazdığı için omurga switchte `configure` yetkisi istenir; adresleme
yetkisi tek başına yetmez.

## BGP'de alınan prefixler
"Komşu bize ne duyuruyor, hangisini kabul ediyoruz" sorusunun cevabı tek
RPC'dedir:

```
show route receive-protocol bgp <komşu> all
```

Ayrımı `active-tag` yapar: `*` ise kabul edildi, TEK BOŞLUK ise içeri alma
politikası reddetti (hidden). Reddedilen yolda eleman VARDIR ama içi boştur;
varlığına bakan bir kontrol ikisini ayırt edemez.

`all` olmadan yalnızca kabul edilenler döner ve panel "hepsini kabul
ediyoruz" gibi yanlış bir izlenim verir.

Junos ayrıca `__juniper_private*__` tablolarını da döndürür; onlar cihazın iç
kullanımıdır ve atlanır.

Bu veri SAKLANMAZ: alınan tablo binlerce satır olabilir ve sürekli değişir.
Kullanıcı pencereyi açtığında okunur, sayfa yüklenirken değil.

## Adres havuzları amaçlarına göre ayrılır
`address_blocks.purpose`: müşteri, uplink, BGP peering, yönetim.

Adresleme ekranında kök bloklar İKİ AYRI listede durur (Müşteri / Altyapı):
uplink bacakları ve peering /30'ları günlük işte müşteri bloklarının arasında
aranmaz. Seçili bir blok varsa sekme ONA göre açılır, yoksa derin bağlantı boş
listeye düşerdi.

Uplink bacakları ve peering /30'ları private adreslerdir. Amaç alanı olmadan
"peering için boş bir /30 ver" sorusu yanıtlanamaz; müşteri blokları da
aday çıkar. Alt blok üst bloğun amacını DEVRALIR: bir peering havuzundan
kesilen /30 da peering içindir.

BGP komşu formundaki havuz seçici yalnızca altyapı amaçlı ve HENÜZ
BÖLÜNMEMİŞ blokları önerir (bölünmüş blok bir havuzdur, adres taşımaz).
Seçim alanları yalnızca DOLDURUR; iki adres de elle değiştirilebilir, çünkü
havuz dışında kalan komşular (sağlayıcı adresleri) da tanımlanabilmeli.


## Bağlantı toplama (LAG)

### "ae limiti" bir üst sınır değildir
`chassis aggregated-devices ethernet device-count N`, cihazda **ae0..ae(N-1)
arayüzlerini YARATIR**. Hiç yapılandırılmamış olsalar bile gerçek arayüz
olarak görünürler.

Sayıyı DÜŞÜRMEK üsttekileri **sessizce yok eder**: cihazda doğrulandı —
device-count 4'ten 2'ye inince yapılandırması duran ae3 ortadan kalkar ve
commit hiçbir uyarı vermez. Bu yüzden panel, yapılandırılmış bir
ae'yi yok edecek limiti reddeder. Aralık 1..111'dir ("Value 112 is not within
range").

Aynı sebeple ae arayüzü panelde YARATILMAZ; ekranda "yeni ae" düğmesi yoktur.
Yeni bir LAG için önce limit artırılır.

### Üye olacak port kendi birimini kaybeder
Bir portu ae'ye bağlamak, o portun `unit`'ini AYNI commit'te silmeyi
gerektirir; yoksa cihaz **"logical unit is not allowed on aggregated links"**
der ve commit düşer. Portun VLAN'ı da onunla gider — bu geri alınamaz bir yan
etkidir ve formda yazar.

Üyelikten çıkarılan port birimsiz kalır: hiçbir VLAN'a ait olmaz ve port
ekranından yeniden yapılandırılması gerekir.

### Cihazın söylediği sınırlar
| Kısıt | Cihazın söylediği |
| --- | --- |
| En fazla 8 üye | "group limit of 8 exceeded" |
| minimum-links 1..8 | "Value 9 is not within range (1..8)" |
| minimum-links ≤ üye sayısı | "minimum-links is greater than the num of links configured" |
| device-count 1..111 | "Value 112 is not within range (1..111)" |

`minimum-links`, üyesi olmayan bir ae'de 1 bile olsa reddedilir.

### Junos eleman adı "ieee-802.3ad"dır
Set komutunda `802.3ad` yazılır ama XML'de eleman
`<ether-options><ieee-802.3ad><bundle>ae0</bundle></ieee-802.3ad>` olarak
döner. Set yazımına bakarak aranırsa üyelik hiç bulunamaz.

`aggregated-ether-options` kapsayıcısını silmek DOĞRU çalışır (BGP'deki
`family` kapsayıcısının aksine); ayarları boşaltmak için sil-sonra-yaz
kullanılabilir.

### ae bir arayüzdür, ayrı bir tablo değil
`switch_ports` zaten arayüzleri modelliyor (açıklama, port modu, VLAN
üyeliği, IP adresi); ae de oraya `interface_kind = aggregated` ile yazılır.
SNMP ifType ("ethernetCsmacd", "6") arayüz türü olarak kullanılmaz ve
saklanmaz (bkz. `.ai/rules/netconf.md`).

Fiziksel port silinmez ama **ae silinir**: donanım değildir, limit
düşürüldüğünde gerçekten yok olur.

## Müşteriye gönderilen BGP configi
Karşı tarafın configi bizim tarafın AYNASIDIR: bizim komşu adresimiz onların
`local-address`'i, bizim yerel adresimiz onların `neighbor`'ı, bizim AS'imiz
onların `peer-as`'ı.

`passive` AYNALANMAZ — bizde pasifse oturumu onlar başlatmalı, yani onlarda
yazılmamalı.

Blok configure modunda yapıştırılacağı için içinde YALNIZCA `set` satırı
bulunur; Junos configure modu yorum kabul etmez ve araya karışan tek satır
bütün yapıştırmayı bozar. Açıklamalar kutunun dışında durur.

Bilinmeyen değer UYDURULMAZ: eksikse o satır hiç üretilmez ve not düşülür.
Yanlış bir satır, eksik bir satırdan zararlıdır — müşteri onu fark etmeden
uygular.

Anons politikası tahmin değildir: bizim gruba bağlı içeri alma politikasının
dayandığı prefix list, o komşudan KABUL ETTİĞİMİZ prefixlerdir; anons etmeleri
gereken de tam olarak odur. Arayüz satırı ayrı durur, çünkü onların port adını
bilemeyiz.

### Ayarlar KOMŞU seviyesinden okunur
Junos'ta komşudaki değer grubunkini ezer. Aynayı üretirken yalnızca gruba
bakılırsa, komşuya özel açılmış bir `multihop` karşı tarafın configine
girmez ve oturum tek taraflı kalır. Etkin değerler `BgpNeighbor` üzerindeki
`effective_*` erişimcilerinden okunur; karar tek yerdedir.

### İki taraflı olmak zorunda olanlar
| Ayar | Neden |
| --- | --- |
| `multihop` | Tek taraflı multihop oturum kurmaz |
| `bfd-liveness-detection` | Karşı tarafta yoksa oturum kurulur ama BFD hiç kalkmaz — kimse fark etmez |
| `family inet6 ...` | Satır yoksa v6 prefixleri hiç taşınmaz |
| MD5 anahtarı | Aynı olmalı; ama METNE KONMAZ, yalnızca not düşülür |
| `hold-time` | BGP küçüğü seçer; yine de iki taraf bilerek aynı değeri yazsın diye üretilir |

`passive`, `cluster`, `remove-private`, `as-override`, prefix sınırı ve
`out-delay` AYNALANMAZ: bunlar bizim tarafın kararıdır.

### "Bizim taraf" configi de üretilir
Aynı pencerede oturumun BİZDEKİ hâli de `set` satırları olarak durur
(`PeerConfigurationBuilder::ourSide`). Cihaza yazmak için değildir — panel
yazmayı kendisi yapar; kaydı gözden geçirmek ve gerekirse aynı oturumu başka
bir cihaza kurmak içindir.

Oturum anahtarı oraya da YAZILMAZ: yerine `"<ANAHTAR>"` tutucusu konur.
Metin ekranda görünür ve kopyalanabilir; cihazdan okunan `$9$` biçimi
çözülebilir olduğu için sır oraya girmemeli.


## Firewall filtreleri

Panelin EN TEHLİKELİ yazma yolu budur: yanlış bir filtre bütün trafiği keser
ve RE filtresi söz konusuysa panelin cihaza erişimini de koparır. Yetki bu
yüzden `configure` DEĞİL, kendi kaynağıdır: `switch_firewall`, switch bazında
verilir (bkz. `.ai/rules/permission.md`).

Firewall yazma yetenekleri de `Gate::before` yönetici geçişinden muaftır. Muaf
olmasaydı "NETCONF kimliği var mı" kontrolü hiç çalışmazdı — yönetici yazacak yolu
olmayan bir switchte düğme görür, tıklayınca hata alırdı. Aynı kural `connect`
ve `configure` için de geçerli.

### Cihazda doğrulanmış biçimler
| Yapı | XML |
| --- | --- |
| Kaynak/hedef adres | `<source-address><name>10.0.0.0/8</name></source-address>` |
| Prefix list | `<source-prefix-list><name>koru</name></source-prefix-list>` |
| Protokol / port / icmp-type | düz metin, tekrarlanabilir |
| Sayaç | **`<count>sayac</count>`** — DÜZ METİN |
| Günlük | `<log/>` |
| Sonuç | `<accept/>`, `<discard/>`, `<reject/>` |

Sayaç için `<count><counter-name>` beklemek "syntax error" verir; gerçek
cihazda görüldü. Okuyucu da aynı yerden okur.

### Junos burada müsamahakârdır, panel kısıt UYDURMAZ
Cihaz şunları KABUL EDİYOR (denendi): protokolsüz port, protokolsüz
icmp-type, eylemsiz terim. Panel bunları reddetmez — cihazda olmayan bir
kısıt uydurmak, kullanıcının geçerli bir yapılandırmayı yazamamasına yol
açar. Yönlendirme formdaki açıklama metniyle yapılır.

Eylemsiz terim Junos'ta paketi KABUL EDER (Juniper belgesi; cihazda trafikle
ölçülmedi), o yüzden "eylem yok" seçeneği "Junos kabul eder" diye yazar.

### Sıra ve bağlama
Terim sırası anlamlıdır ve `merge` yeni terimi hep SONA ekler; bu yüzden
filtre silinip bütün terimleriyle yeniden yazılır (tek commit) — politika
terimlerindeki yolun aynısı.

Filtrenin hangi arayüzde hangi yönde bağlı olduğu da okunur ve ekranda
gösterilir: bağlı olduğu yeri bilmeden yapılan değişiklik, hangi trafiği
etkileyeceğini de bilmez. Hiçbir arayüze bağlı olmayan filtre hiçbir trafiği
etkilemez ve ekranda öyle yazar.


## Terim sırası düzenlenebilir olmalı
Politika ve firewall terimleri yalnızca eklenip silinebilseydi bir terimi
değiştirmek için silip yeniden yazmak gerekir, sırayı değiştirmenin yolu
olmazdı. Junos'ta İLK EŞLEŞEN terim kazandığı için sıra bir görsel
tercih değil, trafiğin kendisidir: yeni eklenen terim hep sona gider ve
önündeki "her şeyi düşür" yüzünden hiç çalışmayabilir.

Üç yol vardır ve üçü de aynı yazma biçimini kullanır (filtre/politika
silinip bütün terimleriyle yeniden yazılır, tek commit):

| İşlem | Uç |
| --- | --- |
| Terimi düzenle (sıra dâhil) | `PUT .../terim/{term}` |
| Terim ekle (sıra seçilebilir) | `POST .../terim` |
| Sürükle-bırak sıralama | `PUT .../siralama` |

Sıralama ucuna gönderilen liste TAM olmalıdır: eksik bir liste, adı geçmeyen
terimi sessizce silerdi. Sunucu listeyi mevcut terimlerle birebir karşılaştırır
ve eşleşmezse 422 döner.

Güncellemede eski kayıt önce ÇIKARILIR: adı değişmemişse aynı terim iki kez
yazılırdı, değişmişse eskisi listede kalırdı.

Sürükleme için dış kütüphane kullanılmaz; tarayıcının kendi olayları yeterli.
Bırakma anında normal form gönderimi yapılır, böylece onay penceresi, hata
gösterimi ve yönlendirme diğer yazma yollarıyla aynı kalır.

## Terimler de uuid taşır
Terim uçları (`düzenle`, `çıkar`, `sırala`) sıralı birincil anahtar taşısaydı
bir terimin kimliğini bilen diğerlerininkini tahmin ederdi.
`switch_policy_terms` ve `switch_firewall_terms` bu yüzden uuid taşır ve rota
bağlaması, form değeri ile DOM kimliği ondan okunur. Kural (CLAUDE.md) yeni bir
tablo eklerken dışarı açılan her kayıt için geçerlidir.


## Onay kuralı: silme ve düzenleme YAZILI onay ister
Cihaza yazan ya da kayıt silen her form onay penceresinden geçer. Onayın türü
işlemin geri alınabilirliğine göre değişir:

| İşlem | Onay |
| --- | --- |
| Silme (`DELETE`) | nesnenin adı YAZILARAK doğrulanır |
| Düzenleme (`PUT`) | nesnenin adı YAZILARAK doğrulanır |
| Ekleme (`POST`) | normal onay |

Yazılan değer nesnenin kendi kimliğidir: port adı, VLAN adı, prefix, komşu
adresi, filtre/terim adı, switch adı. Amaç bir engel koymak değil, kullanıcıya
NE ÜZERİNDE çalıştığını okutmaktır — yanlış satırdaki "Sil" düğmesine basmak
bu panelde kesinti demektir.

Mekanizma `data-confirm-verify` özniteliğidir. Yeni bir yazma ucu eklerken bu
öznitelik de eklenir; denetim:

```
# silme/düzenleme formunda yazılı onay eksikse çıktı verir
grep -rn "@method('DELETE')\|@method('PUT')" resources/views --include=*.blade.php
```

Ekleme formuna `data-confirm-verify` KONMAZ: orada doğrulanacak bir nesne
henüz yoktur ve döngü dışındaki bir değişkene atıf sayfayı hataya düşürür.


## Mobil: şerit sayfa sayfa kayar

Sütunlu şerit telefonda yatay kayar. Üç kural birlikte çalışır ve biri
eksik olduğunda diğerleri işe yaramaz:

| Kural | Neden |
| --- | --- |
| Her bölme `100vw` | Bölme ekrandan darsa tek geçiş birkaç hareket sürer |
| `scroll-snap-type: x mandatory` | Yavaş/kısa hareket de bölmeyi tamamlar, yarıda kalmaz |
| `scroll-snap-stop: always` | Hızlı hareket bölmeleri atlamaz |

`mandatory`, bölmeler FARKLI genişlikteyken programlı kaydırmayı (bir düğmeyi
görünür alana getirmeyi) geri iter ve tıklamayı imkânsız hâle getirir —
tarayıcı testleri "element is outside of the viewport" ile zaman aşımına
düşer. Bölmelerin hepsi tam ekransa hizalama noktaları bölme kenarlarıyla
çakışır ve sorun çıkmaz. Bölme genişliklerini farklılaştıran bu tuzağa düşer.

### Şerit her ekranda EN SAĞDAKİ görünür bölmeyi açar
Kural tektir ve ekrana göre değişmez (`components/columns.js`): en sağdaki
bölme, kullanıcının indiği en derin seviyedir.

| Durum | Açılan |
| --- | --- |
| Bir kayıt seçili (BGP komşusu, sunucu, blok, port) | detay paneli |
| Seçim yok | liste / içerik sütunu |
| Switchin alt ekranı (portlar, firewall…) | içerik sütunu |

"İçerik sütununa in" gibi bir kural EKRANA GÖRE DEĞİŞİR: BGP'de detay paneli
içerik sütunundan SONRA geldiği için seçilen komşunun bilgileri açılmaz,
diğer ekranlarda açılır.

Detay paneli her zaman sayfa yenilemeyle gelmez: portlarda trafik paneli JS
ile açılır. Bu yüzden `hidden` özniteliği bir MutationObserver ile izlenir ve
panel görünür olduğunda şerit ona kayar — kural aynı, tetikleyeni farklı.

Seçim işareti telefonda 5px'tir: 2px'lik şerit ekranın kavisli kenarına denk
geldiği için ancak biraz sağa kaydırınca fark edilir. Seçili satıra EK
DOLGU verilmez; şerit zaten satırın dolgusunun solunda kalır, dolgu eklemek
satırı kaydırır.

Yatay kayan şeritlerde (bölüm menüsü, kırıntı yolu, sütun şeridi) kaydırma
çubuğu GİZLENİR ve bölüm menüsü SEÇİLİ öğeye kaydırılır; yoksa taşan menüde
hangi bölümde olunduğu görünmez.

Panel içi iki uçlu satırlar telefonda yığılır: ad üstte, açıklama altında,
eylemler en sonda eşit genişlikte. Eylem alanının doğrudan çocuğu hem düğme
hem FORM olabilir (silme ayrı bir formdur); yalnızca `.btn` hedeflenirse form
esnemez ve düğmeler hizasız durur.


## BGP prefix-limit: teardown bir YÜZDEDİR
`prefix-limit teardown <n>` mutlak prefix sayısı değil, azaminin YÜZDESİDİR.
Cihaz doğruladı: `teardown 150` → "Value 150 is not within range (1..100)".

Junos'ta oturum SINIR AŞILINCA düşer; teardown'a verilen yüzde uyarının
hangi noktada başlayacağını söyler (Juniper belgesi; cihazda trafikle
ölçülmedi). Örneğin `maximum 50` ile `teardown 100` yazan bir grupta anlamı
"50 prefixin %100'ünde uyar"dır.

Yüzde tek başına kaç prefixe denk geldiğini söylemediği için ekranda ikisi
birden yazılır: "en fazla 500 prefix · %90 (450 prefix) uyar, sınırda düşür".

## Sürüm çakışması (kayıp güncelleme)

Düzenleme formları gizli `record_version` alanı taşır (`<x-form.version>`);
değeri kaydın `updated_at` damgasıdır. `EnsureRecordIsUnchanged` ara katmanı
PUT/PATCH'te kaydın o damgadan sonra değişip değişmediğine bakar ve değiştiyse
işlemi reddeder. Bu olmadan iki yönetici aynı firewall term'ünü ya da BGP
grubunu düzenlediğinde son yazan kazanır, diğerine hiçbir şey söylenmez.

Ara katman GLOBAL yığına değil WEB GRUBUNA eklenir: rota parametreleri modele
ancak `SubstituteBindings`'ten sonra dönüşür, global yığında hâlâ ham metindir
ve kontrol sessizce hiçbir şey yapmazdı.

Hedef kayıt rotanın EN İÇTEKİ modelidir: `switchler/{switch}/firewall/{filter}`
isteğinde konu filtredir, switch değil.

Switch'in KENDİSİNİ düzenleyen formlara (envanter, SNMP adı, AS numarası, ae
limiti) bu alan KONULMAZ: `switches.updated_at` her ölçüm turunda değişir
(`last_sync_at`) ve her gönderim sahte çakışma verirdi.

Yeni kayıt formlarında sürüm damgası yoktur — ortada henüz bir kayıt yoktur.

## BGP yazma ayrı bir yetkidir

`configure_bgp`, `configure` yetkisini KAPSAMAZ ve onun tarafından da
kapsanmaz. Sebep: port ya da VLAN değiştiren biri, komşuluk kurup bir bloğu
internete anons edebilecek biri değildir — etkisi cihazın dışına, komşu ağa
taşan tek yazma yolu budur.

Yetki kayıt bazında verilir: bir kullanıcıya yalnızca belirli switchlerde BGP
tanınabilir ya da yalnızca belirli switchlerde reddedilebilir. `Gate::before`
bunu bypass ETMEZ (connect/configure/firewall yazma gibi), çünkü NETCONF
kimliği olmayan switchte yönetici de yazamaz.

Uçlar: `switches.bgp.groups.*`, `switches.bgp.neighbors.*` ve AS numarası.
Prefix list, politika ve community `configure` altındadır — onlar yalnızca
BGP'ye ait değildir.

Regresyon: `tests/Feature/Security/BgpPermissionTest.php`.

## Tam config yükleme

`ConfigurationWriter::applyText()` ham Junos metnini uygular; üç eylem
vardır ve seçimi metnin BİÇİMİ belirler:

- `set` — metin yalnızca `set/delete/deactivate/activate` satırlarından
  oluşuyorsa. Kullanıcının seçtiği biçim yok sayılır: Junos set satırlarını
  ancak `action="set"` ile kabul eder.
- `merge` — hiyerarşik metin, mevcut yapılandırmanın üstüne.
- `override` — hiyerarşik metin, cihazdaki yapılandırmanın TAMAMI silinerek.

Metin CDATA içinde gider: Junos yapılandırması "<" ve "&" taşıyabilir ve XML
gövdesine olduğu gibi konursa belge bozulur.

Override'ın güvenlik ağı geri alınabilir commit'tir: yeni metin mgmt/SSH
tanımlarını içermiyorsa onay oturumu açılamaz ve cihaz kendini eski hâline
döndürür. Bu yüzden onay ADIMI atlanamaz.

Önizleme (`previewText`) yükler ama commit ETMEZ; aday yapılandırma özel
oturumla birlikte atılır ve cihazda iz bırakmaz.

Hedef envanterdeki switch olabilir ya da yalnızca erişim bilgisi verilen dış
bir cihaz (`NetconfTarget`). Kilit anahtarı ikisinde de aynı biçimdedir;
`NetconfTarget::lockKey()` ile `SwitchWriteLock` ayrışırsa aynı cihaz iki
farklı kilit altında görünür ve kilit hiçbir şeyi korumaz.

Şablon cihazı ÖNCE okur: yönetim arayüzünün adı sanal şaside "vme", tek
cihazda "me0"dır ve varsayılmaz.

## Yapılandırma yedeği

`set system archival configuration transfer-on-commit` + `archive-sites`.
Hedef adres ayarlardan gelir (tek yedek sunucusu), kimlik içerdiği için
şifreli saklanır ve NE cihazın commit açıklamasına NE de etkinlik kaydına
yazılır.

`archive-sites` bir listedir: yazmadan önce `delete="delete"` ile temizlenir.
Eski adres kalırsa cihaz iki yere birden göndermeye çalışır ve erişemediği
için her commit'te hata basar.

## Commit geri alma

`<load-configuration rollback="N"/>` o commit'in yapılandırmasını aday kopyaya
koyar; gerisi normal yazma akışıdır ve AYNI güvenlik zincirinden geçer (fark,
`commit check`, geri alınabilir commit, ayrı oturumda onay). Yönetim erişimini
koparan bir geri alma bu sayede kendiliğinden geri döner.

### İndeks panelden gelmez
Junos'ta rollback numarası SIRADIR ve her yeni commit'te bir kayar. Ekran
açıkken cihaza başka biri commit atarsa, kullanıcının gördüğü satır ile o
numaranın gösterdiği commit ayrışır ve YANLIŞ yapılandırma yüklenir.

Bu yüzden hedef commit kimliğiyle seçilir, indeks yazmadan hemen önce
cihazdan yeniden okunur (zaman damgasıyla eşleştirilir) ve eşleşme
bulunamazsa işlem hiç yapılmaz. Panelde duran `sequence_number` yalnızca
gösterimdir.

### Önce fark, sonra commit
Geri alma cihazın TAMAMINI etkiler — panelin yönetmediği satırlar da o
commit'teki hâline döner. Bu yüzden düğme doğrudan geri almaz: önce
`previewRollback` ile fark okunur (commit atılmaz, aday oturumla birlikte
atılır), geri alma o farkın altından yapılır.

Geri almadan sonra envanterin TAMAMI yeniden okunur (`SyncSwitchNetconfJob`);
yazma yolunun kendi geri okuması yalnızca dokunduğu nesneyi tazeler, burada
dokunulan nesne "hepsi"dir.

## Metin yüklemesi: CDATA YOK, sarmalayıcı eyleme göre

İkisi de jn32-test (12.3R12.4) üzerinde doğrulandı; her yükü kabul eden bir
sahte cihazla testler ikisini de göremez:

1. **CDATA kullanılmaz.** Bu Junos `<![CDATA[...]]>` dizisini içerik değil
   ETİKET sanıyor: `internal communications error (tag '![CDATA[...')`.
   Metin XML kaçışıyla gönderilir (`htmlspecialchars`, `ENT_XML1`); `&` ve
   `<` cihaza olduğu gibi ulaşır.
2. **Sarmalayıcı eyleme göre değişir.** `action="set"` → `<configuration-set>`,
   `merge` / `override` → `<configuration-text>`. Yanlışı gönderilirse cihaz
   `expecting configuration-set` der.

Panelin ana kullanımı set satırı yapıştırmaktır. `Tests\Support\FakeJunosDevice`
ikisini de reddeder: bu hata sessizce geçemez.

## MTU

Aralık cihazın cevabıdır: `Value 9217 is not within range (256..9216)`.
ae ÜYESİ portta yazılamaz — `ae child device mtu setting and vlan-tagging is
not allowed`; MTU o durumda ae arayüzünün kendisine verilir (ae0 üzerinde
kabul edildi).

## Duplex (ether-options link-mode)

`Half duplex is an invalid configuration when speed is 10G/1G/Auto` — half
yalnızca 10m/100m ile geçerlidir. Kısıt üç yerde birden durur: `config
netconf.half_duplex_speeds`, `SwitchPortRequest` doğrulaması ve formda
seçeneği kapatan JavaScript.

Duplex ile HIZ aynı `ether-options` düğümündedir ve TEK elemanda yazılır;
ayrı belgelerde gönderilirse okuması zorlaşır ve ikinci belge birincinin
yazdığını taşımaz.

Ayarlanan duplex `switch_ports.duplex_config`, cihazın ölçtüğü duplex ise
`switch_ports.duplex` kolonundadır — tıpkı `speed_config` ile `speed_mbps`
gibi; ikisi karıştırılmaz.

Boş bırakmak "cihaz kendi seçsin" demektir ve satırı SİLER; merge yalnızca
eklediği için silme açıkça istenmezse eski değer cihazda kalırdı.

## ROUTED port anahtarlamaya dönerken inet SİLİNMELİ

`When ethernet-switching family is configured on an interface, no other
family type can be configured on the same interface.` Porta bir kez IP
verildikten sonra access/trunk'a dönmek için `family inet` AYNI belgede
silinir; ayrı belgede de olur ama tek belge yeterli ve denendi.

## Kullanılan nesne silinemez

Cihaz silmeye izin verir, commit'i reddeder:

| Silinen | Cihazın söylediği |
| --- | --- |
| Prefix list | `prefix-list kabul referenced (in term A) but not defined` |
| Politika | `Policy gelen referenced but not defined \| BGP: import list not applied` |
| Firewall filtresi | `Referenced filter 'koruma' is not defined` |

Panel bildiği bağımlılıkları ÖNCEDEN kontrol eder (`DependencyGuard`) ve
isteği cihaza hiç götürmez. Cihazda elle kurulmuş bir atıf panelden görünmez;
onu yakalayan commit check'tir ve mesajı kullanıcıya olduğu gibi gösterilir
(bkz. `bootstrap/app.php`, NetconfException render).

## Yazma yolları cihazda doğrulanır

`php artisan network:verify-writes test1` panelin cihaza yazan BÜTÜN
yollarını gerçek switchte çalıştırır: belgeler özel bir aday yapılandırmaya
yüklenir, `commit check` ile doğrulanır, oturum kapanınca aday atılır.
**Commit atılmaz, cihazda iz kalmaz.**

Yeni bir yazma yolu eklendiğinde `DeviceWriteScenarios` içine bir senaryo
eklenir. Birim testleri yalnızca kendi beklentimizi doğrular; cihazın kabul
edip etmediğini ancak cihaz söyler.

## Spanning tree (RSTP)

Cihazda doğrulanan kurallar (jn32-test, 12.3R12.4):

- Arayüz ayarı YALNIZCA anahtarlama modundaki portlarda tanımlanır:
  `XSTP : Interface ge-0/0/23.0 is not enabled for Ethernet Switching`.
  Routed ve yapılandırılmamış portlar reddedilir; `ae0` (trunk) kabul edilir.
  Panel bu yüzden yalnızca access/trunk portları ve `all`'ı sunar.
- Köprü önceliği serbest sayı değildir: 4096'nın katları, `8k` biçiminde.
- Port önceliği 16'nın katı olmalıdır (0-240).
- `all` Junos'un kabul ettiği sanal arayüz adıdır; port tablosunda karşılığı
  yoktur, o yüzden RSTP arayüzleri ayrı tabloda tutulur.

Üç durum ayrıdır: `rstp` düğümü hiç yok (cihazın varsayılanı), var ve
`disable` yok (açık), var ve `disable` var (açıkça kapalı). İkincisiyle
üçüncüsü tek bir boolean'a sıkıştırılamaz.

## Half-duplex akış kontrolüyle birlikte olmaz

`Half duplex and flow control enable is an invalid configuration.` 802.3x
duraklatma zaten yalnızca full-duplex'te anlamlıdır. Panel half seçildiğinde
`no-flow-control` satırını AYNI belgede yazar; kullanıcıdan ayrıca istemez
çünkü seçeneği yoktur. Full'e ya da otomatiğe dönerken akış kontrolüne
dokunulmaz — kapalı akış kontrolü full-duplex portta olağandır ve sessizce
geri açmak kullanıcının kurmadığı bir değişiklik olurdu.

## Yeni bir okuyucu eklendiğinde zorlamalı eşitleme gerekir

Eşitleme, cihazın son commit'i değişmediyse tam okumayı ATLAR. Panele yeni
bir alan eklendiğinde cihazda yeni commit olmadığı için o alan boş kalır ve
"özellik çalışmıyor" gibi görünür.

`php artisan network:sync-netconf <switch> --force` değişim algısını atlar ve
her şeyi yeniden okur. Yeni bir okuyucu eklendikten sonra bir kez
çalıştırılır.

## Cihaz üzerindeki yerel hesaplar (system login)

Sınırlar jn32-test (12.3R12.4) üzerinde `commit check` ile ölçüldü:

| Deneme | Cihazın cevabı |
| --- | --- |
| uid 1 / 65535 | `Value ... is not within range (100..64000)` |
| uid 200 (admin ile aynı) | KABUL — cihaz aynı uid'i ikinci hesaba veriyor |
| altı karakterden kısa parola | `minimum password length is 6` |
| TEK karakter sınıfı (yalnız küçük, yalnız büyük, yalnız rakam, yalnız noktalama) | `require change of case, digits or punctuation` |
| İKİ sınıfın her birleşimi (büyük+küçük, küçük+rakam, büyük+rakam, küçük+noktalama, rakam+noktalama) | KABUL |
| tanımsız sınıf (`yonetici`) | `Undefined class 'yonetici' for user ...` |
| ad rakamla başlıyor / nokta / 64+ karakter | KABUL |
| olmayan kullanıcıyı silmek | KABUL (sessiz) |

Panel bunlardan ikisini cihazdan DAHA sıkı tutar:

- **Aynı uid ikinci hesaba verilemez.** Cihaz izin verir ama iki hesap dosya
  sisteminde tek kimlik olur; kimin ne yaptığı ayrılamaz.
- **Parola en az 8 karakter.** Cihazın altısı yetiyor, panel bir tık yukarıda
  durur; cihazın kabul ettiği her parolayı yine kabul eder.

### Panelin bağlandığı hesap dokunulmazdır

`admin` (ya da NETCONF kimlik bilgisindeki kullanıcı adı) silinemez ve
yapılandırma yetkisi elinden alınamaz. **Cihaz ikisine de izin veriyor** —
`admin` silen belge `commit check`ten geçiyor. Geri alınabilir commit iki
dakika sonra kurtarır ama o iki dakika boyunca switch panelden yönetilemez.

Kontrol iki yerdedir ve ikisi de gerekir:

- `SwitchLoginUser::$is_panel_account` — kimlik bilgisiyle karşılaştırır.
- `LoginUserRequest` yetkiyi düşürmeyi, `SwitchLoginUserController::destroy`
  silmeyi reddeder; istek cihaza HİÇ gitmez.

### Parola cihazda kalır

`<plain-text-password-value>` ile gönderilir, cihaz kendi özetini üretir.
Panelde parola SAKLANMAZ; yalnızca `has_password` tutulur. Cihazın döndürdüğü
fark `encrypted-password "$1$..."` içerir ve `ConfigurationRedactor` onu
maskeler — kayda `••••` düşer.

Düzenlemede parola boş bırakılırsa `<authentication>` düğümü hiç yazılmaz;
merge yalnızca eklediği için cihazdaki parola olduğu gibi kalır. Tam ad boş
bırakılırsa `<full-name delete="delete"/>` ile SİLİNİR.

Ad cihazda kimliktir: değiştirmek silip yeniden yaratmak demektir, panel
düzenlemede sormaz.

## Şasi alarmları (chassis alarm)

Cihaz yalnızca `ignore`, `yellow`, `red` kabul eder; başkası `invalid value`
ile döner. Boş bırakmak satırı siler ve cihaz varsayılanına döner.

Alarmlar `<chassis>` altındadır ama cihaz ayarlarının geri kalanıyla **tek
belgede** gider: ikiye bölünürse cihaz bir an yarım ayarla kalır.

```xml
<configuration>
  <system>...</system>
  <chassis><alarm>
    <ethernet><link-down>red</link-down></ethernet>
    <management-ethernet><link-down>yellow</link-down></management-ethernet>
  </alarm></chassis>
</configuration>
```

## Kapsayıcı seviyesindeki satırlar (test1'de doğrulandı)

Panel firewall filtresini ve politikayı GÜNCELLERKEN silip yeniden yazıyor
(terim sırası anlamlı olduğu için). O yüzden kapsayıcının kendi altındaki her
satır, panel onu okumuyorsa kaybolur. Yönetilebilirlik bayrağı yalnızca
terimlerin içine bakarsa bu kaçar.

Üçü de cihazda geçerli; `commit check` ile doğrulandı (commit atılmadı):

| yapı | ne işe yarar | panel |
| --- | --- | --- |
| `policy-statement X { ... then reject; }` | politikanın VARSAYILAN eylemi | salt okunur sayar |
| `firewall filter X { interface-specific; }` | arayüz başına sayaç | salt okunur sayar |
| `prefix-list X { apply-path "..."; }` | listeyi dinamik doldurur | salt okunur sayar |

Birincisi en tehlikelisi: `term A { ... then accept; } then reject;` bütün BGP
süzgeçlerinin standart biçimi ve o son satır kaybolunca politika "gerisini
reddet"ten "gerisini de al"a döner.

**Kural:** yeni bir okuyucu yazarken yönetilebilirlik KAPSAYICININ ÇOCUKLARINA
da bakmalı, yalnızca terimlerin içine değil.

## Parola kuralı (test1, 12.3R12.4'te doğrulandı)

| parola | sonuç |
| --- | --- |
| `12345678` | RED — "require change of case, digits or punctuation" |
| `abcdefgh` | RED — aynı |
| `ABCDEFGH` | RED — aynı |
| `abcd1234` / `AbcdEfgh` / `abcdefg!` | KABUL |
| `Ab1!` | RED — "minimum password length is 6" |
| `abc123` | KABUL |

Yani: **en az 6 karakter ve en az İKİ karakter sınıfı.** "Rakam ya da sembol
var" YETMEZ — `12345678` tek sınıftır ve reddedilir. Kural tek yerde durur:
`PasswordGenerator::DEVICE_CLASS_PATTERN` ve `deviceRules()`.

## L3 arayüzü: vlan mı irb mi

ELS öncesinde `interfaces vlan unit N` ve `l3-interface vlan.N`; ELS'te
`irb`. ELS'te "interfaces vlan" diye bir arayüz YOKTUR. Ad şema profilinden
gelir (`JunosSchemaProfile::l3InterfaceName()`), kullanıcının yazdığından
değil. Doğrulama İKİ adı da tanır: cihazdan `irb.100` okunmuş bir VLAN, form
onu geri çevirirse hiç düzenlenemez hâle gelir.

VLAN silinirken L3 birimi de gider; birim bırakılırsa hiçbir VLAN'a bağlı
olmayan, üstünde hâlâ IP taşıyan öksüz bir arayüz kalır. Cihaz bu silmeyi
KABUL EDİYOR — kullanıcıyı koruyan tek şey panelin kendisi, o yüzden adresi
olan bir VLAN hiç silinmez (`DependencyGuard::forVlan`).

## family inet'i silmek

`<family><inet delete="delete"/></family>` yalnızca adresleri değil, o ailenin
altındaki HER ŞEYİ siler: `filter`, `mtu`, `sampling`... test1'in lo0.0'ında
`family inet` altında yalnızca `filter { input RE_FILTER; }` duruyor —
yönlendirme motorunun koruma filtresi. "Son adres → aileyi sil" kuralı onu da
götürürdü.

**Kural:** aile ancak "içinde bu adresten başka hiçbir şey yok" ise kaldırılır
ve bu CİHAZA sorulur (`IpAddressReader::familyContents`), envanterden değil.
Envanter bayattır: CLI'dan eklenmiş ikinci bir adres panelde görünmez.

## Aynı cihaz satırına giden iki yol

Aynı cihaz satırını iki ekran yazabilir; yalnızca biri korumayı bilirse
öteki yol açık kalır. Bilinen çiftler:

| satır | bir yol | öteki yol (aynı korumayı taşır) |
| --- | --- | --- |
| port üyeliği | port ekranı | VLAN ekranı |
| port `unit 0` | port + VLAN ekranı | IP adres ekranı |
| panel hesabının parolası | parola turu | cihaz hesapları ekranı |
| oturum yetkisi | panel sayfaları | uzak oturum proxy bekçisi |
| yönetici hesabı | rol alanı | kullanıcı düzenleme (parola) |

**Kural:** yeni bir ekran aynı cihaz satırına dokunuyorsa, o satırın DİĞER
yolundaki korumaları da taşımak zorunda.

## ethernet-switching-options (test1'de doğrulandı)

Yazılan her biçim commit check ile doğrulanır; doğrulanmamış biçim koda
girmez.

### Fırtına denetimi (storm-control)

| yapı | sonuç |
| --- | --- |
| `ethernet-switching-options storm-control interface all { level 80; }` | KABUL |
| `... interface all { bandwidth 10000; }` | KABUL |
| `... interface all { no-broadcast; no-multicast; }` | KABUL |
| `... storm-control { action-shutdown; }` | KABUL |
| `... interface ge-0/0/20.0` (anahtarlama ailesinde değil) | RED — "Interface not found" |
| **ELS**: `forwarding-options storm-control-profiles` | **RED — syntax error** |
| **ELS**: `protocols layer2-control` | **RED — syntax error** |

Son iki satır ELS bağının neden gerçek olduğunu kanıtlar: ELS biçimi ELS
öncesi cihazda ŞEMA olarak yok.

### Port aynalama (analyzer)

Cihazın kuralları — hepsi panelde önceden söyleniyor:

| kural | cihazın cümlesi |
| --- | --- |
| çıkış portu anahtarlama ailesinde olmalı | "should be configured with family ethernet-switching" |
| **ae arayüzü aynalamada kullanılamaz** | "Aggregated interface cannot be configured as Analyzer monitor interface" |
| **çıkış portu hiçbir VLAN'a üye olmamalı** | "output interface ... should not be added to any vlan" |
| kaynak, çıkış VLAN'ının üyesi olamaz | "cannot be configured in input stanza since it is a member of Analyzer output VLAN" |
| **oran aralığı 0..2047** | "Value 2048 is not within range (0..2047)" |

Şemanın kendisi de doğrulandı. İki test cihazında da VLAN'sız
anahtarlama portu olmadığı için ölçüm ŞÖYLE yapıldı: aynı özel adayda önce
çıkış portunun VLAN ÜYELİĞİ kaldırıldı (aile bırakıldı), sonra analyzer
eklendi ve commit check çalıştırıldı. Commit atılmadı, aday atıldı, cihazda
iz kalmadı.

| yapı | sonuç |
| --- | --- |
| `analyzer <ad> { input { ingress { interface X; } } output { interface Y; } }` | KABUL |
| `... ratio 2; loss-priority low;` | KABUL |
| `... input { ingress {...} egress {...} }` | KABUL |
| `... input { ingress { vlan <ad>; } }` | KABUL |
| `... ratio 2047` (üst sınır) | KABUL |

DİKKAT: aileyi tamamen kaldırmak işe yaramaz — o zaman cihaz "should be
configured with family ethernet-switching" der. Gereken şey "aile VAR, VLAN
üyeliği YOK".

### secure-access-port

`dhcp-trusted` ile `no-dhcp-trusted` İKİ AYRI SATIR DEĞİL, tek bir ifadenin
iki değeridir:

```
<dhcp-trusted/> + <no-dhcp-trusted delete="delete"/>   → syntax error
yalnız <dhcp-trusted/>  (replace ile)                  → KABUL
```

**Kural:** düğüm `replace="replace"` ile yeniden kuruluyorsa silme markörü
YAZILMAZ. Yazılmayan satır zaten düşer ve bazı ifadelerde silme markörü
cihazca reddedilir.

`allowed-mac` yinelenen düz metin elemanlarıyla yazılır
(`<allowed-mac>00:11:22:33:44:55</allowed-mac>`); `<mac-list>` diye bir
sarmalayıcı YOKTUR (denendi, syntax error).

### VoIP

`ethernet-switching-options voip interface <ad> { vlan <ad>; forwarding-class <sınıf>; }`
kabul ediliyor. Arayüz anahtarlama ailesinde olmalı; VLAN adı cihazda tanımlı
olmalı. ELS'te düğüm `switch-options voip` altındadır (ölçülmedi).

### VLAN üyeliğinin İKİ YOLU

Üyelik cihazda iki ayrı satırla bildirilebilir ve cihaz ikisini **tek üyelik**
sayar:

```
interfaces <port> unit 0 family ethernet-switching vlan members <ad>
vlans <ad> interface <port>.0
```

test1 (EX3200, 12.3R12.4) ölçümleri:

| Deneme | Sonuç |
| --- | --- |
| Aynı VLAN iki yoldan bildirilir | KABUL (yinelenme sorun değil) |
| Farklı VLAN iki yoldan, access port | **RED** — "Access interface `<ge-0/1/0.0>` has more than one vlan member" |
| Farklı VLAN iki yoldan, trunk port | KABUL (ikisine de üye) |
| `vlans <ad> interface <port>.0` silmek | KABUL |
| **Olmayan** bir `vlans … interface` satırını silmek | KABUL (hata değil) |

**Kural:** panel üyeliği arayüz tarafına yazar, ama dokunduğu portun vlans
tarafını da temizler. Son satır sayesinde temizlik listesi geniş tutulabilir;
fark çıktısında yalnızca gerçek silmeler görünür.

Kabin switchlerinde üyeliklerin çoğu **vlans tarafında** olabilir. Yalnızca
arayüz tarafı okunursa o portlar panelde üyeliksiz ve modsuz görünür.

### vlan-id ZORUNLU DEĞİL

`vlans <ad>` satırı vlan-id olmadan geçerlidir. test1'de kabul edilenler:
tek başına, `l3-interface` ile birlikte, ve port üyeliğiyle birlikte. Var olan
bir VLAN'dan `vlan-id`'yi silmek de kabul ediliyor.

Bir kabin switchinde üstünde müşteri IP'si taşıyan bir L3 arayüzü bulunan
etiketsiz VLAN'lar olabilir. Etiket şart koşulursa bu VLAN'lar envanterde hiç
görünmez, adresleri sahipsiz kalır.

**Kimlik ETİKET DEĞİL ADDIR.** Cihazda port üyelikleri ve `l3-interface` bağı
adla kurulur; eşitleme de ada göre yapılır.

### l3-interface birimi ≠ vlan-id

Birim numarasının etiketle ilgisi yoktur. Örnek bir kabin switchinde:

```
vlans vlan-a vlan-id 100   l3-interface vlan.3
vlans vlan-b vlan-id 101   l3-interface vlan.4
vlans vlan-c vlan-id 105   l3-interface vlan.20
```

**Kural:** arayüz adı VLAN kaydından okunur, etiketten TÜRETİLMEZ.
`vlan.<etiket>` kuralı bu cihazlarda adresi komşu VLAN'a — başka bir
müşteriye — yazar. Ad ayrıca şemaya bağlıdır (ELS'te `irb`), bu yüzden dizge
elle kurulmaz.

### "then" değerleri: düz mü, sarmalanmış mı?

Junos hepsini aynı biçimde yazmıyor. test1'de ölçülenler:

```
<count>sayac</count>                                        düz
<policer>udp-filtre-limit</policer>                         düz
<routing-instance>
  <routing-instance-name>tablo-a</routing-instance-name>
</routing-instance>                                         SARMALANMIŞ
```

Yazarken ikisi de kabul ediliyor — cihaz düz biçimi kendi sarmalanmış hâline
çeviriyor. **Okurken fark eder:** düz metin arayan okuyucu sarmalanmış değeri
NULL görür.

Sonucu sessiz ve ağırdır: firewall filtresi güncellemesi filtreyi SİLİP
yeniden yazar ve eleman "bilinen" sayıldığı için ham XML'de de korunmaz.
Müşteri trafiğini bir yönlendirme tablosuna (ör. `tablo-a`) gönderen
`then routing-instance` satırı, o terime dokunan ilk düzenlemede tamamen
kaybolurdu.

**Kural:** `then` altındaki bir değer okunurken çocuğu olup olmadığına
bakılır; çocuk varsa değer ondadır (`FirewallReader::actionValue`).

### deactivate: nesne durur ama çalışmaz

İşaret gövdede değil ÖZNİTELİKTEDİR ve `get-configuration` onu aynen döndürür:

```
deactivate protocols bgp group router
→ <group inactive="inactive"><name>router</name>…
```

| Deneme | Sonuç |
| --- | --- |
| merge yazma sırasında öznitelik yazılmazsa | işaret KORUNUR |
| `<group inactive="inactive">` | devre dışı bırakır |
| `<group active="active">` | geri açar (yeni nesnede de kabul) |
| `neighbor`, `policy-statement`, `filter`, `term` seviyeleri | hepsinde geçerli |

İşaret okunmadığı sürece panel devre dışı BGP gruplarını ETKİN gösterirdi.

**Tuzak:** `$request->boolean()` eksik girdiyi `false` yapar. Alanı taşımayan
bir istek devre dışı bir nesneyi sessizce AÇARDI; alan gönderilmediyse `null`
geçilir ve öznitelik hiç yazılmaz.

### firewall "from ttl"

`packet-length` ile yan yana duran normal bir koşuldur (`ttl 10-20`). Örneğin
istenmeyen UDP trafiğini paket boyuyla birlikte ayıklamakta kullanılabilir.

## ELS (EX2300, Junos 24.4R2-S4) — test4 üzerinde ölçüldü

ELS yolları çıkarımla değil ölçümle yazılır: çıkarımla beklenen biçimlerin bir
kısmı test4'te doğru, bir kısmı yanlış çıkar (aşağıda).

### Arayüz

| Yapı | ELS |
| --- | --- |
| `ether-options { speed { ethernet-1g; } }` | **syntax error** |
| `interfaces <ad> { speed 100m; }` | KABUL |
| `speed 1g` / `1000m` bir ge- portunda | **invalid value** (1G doğal hız; oraya `auto` ile inilir) |
| `speed auto` | KABUL |
| `ether-options auto-negotiation` | KABUL |
| `ether-options no-auto-negotiation` | KABUL ama hız şart: "Configure interfaces `<ad>` speed, as auto negotiation is disabled" |
| `ether-options link-mode`, `link-mode`, `mtu`, `description`, `disable`, `native-vlan-id` | KABUL |
| `interface-mode access` | KABUL |
| `gigether-options` | syntax error |

**En pahalı tuzak:** port formu hızı HER kayıtta gönderdiği için, hız elemanı
ELS'te yanlış yere yazılırsa **hiçbir port düzenlemesi** çalışmaz — yalnızca
açıklamayı değiştirmek bile yüklemede reddedilir.

### Doğru çıkan varsayımlar

| Yapı | Sonuç |
| --- | --- |
| `forwarding-options storm-control-profiles` | KABUL (ELS öncesi biçim aynı cihazda syntax error) |
| `switch-options voip` | KABUL (ELS öncesi biçim aynı cihazda syntax error) |
| `irb` birimi + `l3-interface irb.N` | KABUL |

### Yanlış çıkan varsayımlar

| Yapı | Gerçek |
| --- | --- |
| Aynalama çıkışı `<output><interface><name>X</name></interface></output>` | **syntax error.** ELS'te `<output><interface>X</interface></output>` — düz metin |
| DHCP snooping `forwarding-options dhcp-relay` | **syntax error.** ELS'te `vlans <ad> forwarding-options dhcp-security { option-82; arp-inspection; }` |
| Port güvenliği ELS'te "yok" sanılabilir | VAR: `switch-options interface <ad> { interface-mac-limit { limit N; packet-action shutdown; } }`. `dhcp-trusted` orada YOK (syntax error) |

Aynalama girişi ELS'te de `<input><ingress><interface><name>X</name></interface></ingress></input>` biçiminde kabul ediliyor; yalnızca ÇIKIŞ farklı.

### Ortak davranışlar (ELS öncesiyle aynı)

- Etiketsiz VLAN kabul ediliyor.
- Olmayan bir `vlans <ad> interface <port>.0` satırını SİLMEK hata değil —
  panelin üyelik temizliği ELS'te de güvenli.
- `interfaces vlan unit N` ELS'te de kabul ediliyor (ama L3 arayüzü `irb`dir).

### ELS'e özgü kısıt

`vlans <ad> interface <port>.0` ile üyelik, portta `interface-mode` varsa
REDDEDİLİYOR: "Interface with 'interface-mode' config is not allowed under
vlans". Yani ELS'te üyeliğin ikinci yolu yapılandırılmış anahtarlama
portlarında kullanılamaz.

## Yeni bir Junos ailesi eklemek

Şekil farkları servislere serpiştirilmez; `App\Services\Junos\JunosDialect`
türevlerinde durur. Servis şekli SORAR, cevabı lehçe verir.

Yeni bir aile eklemek üç adımdır ve **hiçbiri servislere dokunmaz**:

1. `JunosDialect`'ten türeyen bir sınıf yaz; yalnızca FARKLI olan cevapları
   ez. Varsayılanlar ELS öncesi davranıştır.
2. `config/junos.php` içindeki `dialects` listesine anahtarını ekle.
3. Kalıcı ayrım için `JunosSchemaProfile`'a aynı anahtarla bir case ekle.

`matches($model, $version)` yazılırsa model adından hızlı tahmin de mümkün
olur (`JunosSchemaProfile::guess`); kesin cevabı yine cihaza soran şema
sondası verir. Hiçbir lehçe sahiplenmezse `null` döner — uydurma yapılmaz.

Lehçeye yazılan her cevap CİHAZDA ÖLÇÜLMÜŞ olmalıdır. Ölçülmemiş şekil
yazılmaz; yazılırsa hangi cihazda ölçüldüğü docblock'a düşer.

`JunosDialectTest` bunu kilitler: her profilin bir lehçesi olmalı, şekil
soruları lehçeden gelmeli ve tanımsız bir lehçe sessizce geçmemeli.

### ELS güvenlik ekranı: üç ayrı hiyerarşi

Panel ELS'te de yazar. Eşleme (hepsi test4'te ölçüldü):

| Panel alanı | ELS'te yeri |
| --- | --- |
| VLAN: snooping | `vlans <v> forwarding-options dhcp-security` — **düğümün varlığı AÇAR**, kapatmak için `no-dhcp-snooping` |
| VLAN: arp-inspection | `… dhcp-security arp-inspection` |
| VLAN: ip-source-guard | `… dhcp-security ip-source-guard` |
| VLAN: mac-move-limit | `vlans <v> switch-options mac-move-limit` (EYLEM siblingi YOK) |
| Port: mac sınırı + eylem | `switch-options interface <if> interface-mac-limit { limit; packet-action }` |
| Port: dhcp-trusted | `vlans <v> … dhcp-security group <g> { overrides trusted; interface <if>; }` — **VLAN kapsamlı** |
| Port: allowed-mac | `vlans <v> switch-options interface <if> static-mac` — **VLAN kapsamlı** |

**Anlam tersliği:** ELS öncesinde `examine-dhcp` snooping'i AÇAR; ELS'te düğüm
varsa zaten açıktır ve `no-dhcp-snooping` ile kapatılır. Bunu görmeden yazılan
bir panel, "kapalı" derken açık bırakırdı.

Son iki satır panelin switch geneli modeline oturmuyor (VLAN'a bağlılar).
Uydurulmaz: `ElsDialect::unsupportedSecureAccessFields()` onları gerekçesiyle
kapatır, istek doğrulaması reddeder ve form alanları hiç çizilmez.

Eylem adı da farklı: ELS `packet-action`, ELS öncesi `action`.

### ELS: VLAN kapsamlı güvenlik alanları ve MAC sınırı eylemi

Panel bu ikisini de yazar; biçimler test4'te ölçüldü.

| Alan | ELS'te yeri |
| --- | --- |
| dhcp-trusted | `vlans <v> forwarding-options dhcp-security group <g> { overrides trusted; interface <if>; }` |
| allowed-mac | `vlans <v> switch-options interface <if> static-mac <mac>` |

Grup **arayüz başına** açılır ve adı arayüzden türetilir (`guven-ge-0-0-5-0`;
Junos grup adında `/` ve `.` kabul etmiyor). Ortak bir gruba yazılsaydı bir
portun güvenini kaldırmak, aynı gruptaki DİĞER portları da düşürürdü.

ELS'te "açıkça güvenilmez" işareti YOKTUR (`no-dhcp-trusted` sözdizimi hatası):
güven ya gruptadır ya değildir. Bu yüzden `false` ile boş aynı sonucu verir —
grup silinir.

Bir kuralı silmek ÜÇ yeri birden temizler: `switch-options` arayüzü, VLAN'daki
güven grubu ve VLAN'daki statik MAC listesi. Yalnızca ilki silinseydi panelde
görünmeyen ama cihazda duran bir güven satırı kalırdı.

**MAC sınırı eylemi ELS'te ZORUNLUDUR.** Cihaz eylemsiz sınırı reddediyor:
"Interface mac limit should be configured with drop/drop-and-log/shutdown
action". Geçerli eylemler de farklı:

| Eylem | ELS öncesi | ELS |
| --- | --- | --- |
| `drop` | var | var |
| `shutdown` | var | var |
| `log` | var | **RED** |
| `none` | var | **RED** |
| `drop-and-log` | — | var |

`log` → `drop-and-log` diye EŞLENMEZ: biri yalnızca kaydeder, diğeri düşürür.
Seçenek kümesini lehçe belirler (`JunosDialect::macLimitActions`) ve panel
eylemi kendisi seçmez — trafiği düşürmek ya da portu kapatmak operatörün
kararıdır.

## Yönlendirme tabloları (routing-instances) ve FBF

Filtre tabanlı yönlendirme örneği:

```
firewall … term tablo-a-musteri then routing-instance tablo-a
routing-instances tablo-a instance-type forwarding
routing-instances tablo-a routing-options static route 0.0.0.0/0
    qualified-next-hop 198.51.100.141 metric 150
```

Panel terimdeki ATFI, tablonun kendisini ve tablonun içindeki route'ları
birlikte okur ve yazar; yalnızca atfı bilen bir panel yarım resim gösterir.

### Ölçülen biçimler (test1)

| Yapı | Sonuç |
| --- | --- |
| `instance-type forwarding` / `virtual-router` | KABUL |
| `instance-type` olmadan | KABUL (panel yine de hep yazar) |
| Tablo içinde `routing-options static route … qualified-next-hop … metric` | KABUL |
| `rib-groups <ad> import-rib …` (bir ya da iki tablo) | KABUL |
| `interface-routes rib-group inet <ad>` | KABUL |
| Tabloyu silmek | KABUL |

### FBF üç parçadır

Üçü de olmadan çalışmaz:

1. `firewall … then routing-instance <ad>` — trafiği atar
2. `routing-instances <ad> instance-type forwarding` — tabloyu tanımlar
3. `routing-options rib-groups <grup> import-rib […]` +
   `interface-routes rib-group inet <grup>` — arayüz yollarını tabloya kopyalar

**Üçüncüsü olmadan tabloya giden trafik geri dönüş yolunu bulamaz.** Örnekte
rib grubunun adı `rib-grup-a`'dır.

`import-rib` yinelenen düz elemanlardır ve **sıraları anlamlıdır**; grup
`replace` ile yeniden kurulur, yoksa listeden çıkarılan tablo cihazda kalır.
`interface-routes rib-group` switch başına TEK işaretçidir, bu yüzden ayrı
tablo değil switch sütunudur.

### Kurallar

- Statik route ÜÇ yerde durabilir: `routing-options static`,
  `routing-options rib <ad> static` ve
  `routing-instances instance <ad> routing-options static`. Okuyucu üçünü de
  okur; yazıcı hangisinde olacağını `routing_instance` alanından bilir.
- Route'un TABLOSU değiştiyse eski satır aynı yüklemede silinir. Silinmezse
  route cihazda iki yerde birden durur ve hangisinin geçerli olduğu
  belirsizleşir.
- Tablonun gövdesi (içindeki route'lar dâhil) ham hâliyle korunur; yazarken
  geri basılmazsa tablonun kendi yolu silinirdi.
- `then routing-instance` CİHAZDA TANIMLI bir tabloya atıf yapmak
  zorunda. Olmayan bir ad commit check'te düşerdi ve kullanıcı hatayı ancak
  cihaz reddedince görürdü.
- Tabloyu silmek içindeki route'ları da siler ve ona atıf yapan firewall
  terimi varsa cihaz commit'i reddeder.

## "all" sözde-adı ELS öncesine aittir

ELS ÖNCESİ (12.3R12.4, test1/test2) `all` sanal adını iki yerde kabul eder:

```
ethernet-switching-options storm-control interface all
ethernet-switching-options secure-access-port vlan all
```

ELS (24.4R2-S4, test4) **ikisini de reddeder**:

| Yazılan | Cihazın cevabı |
| --- | --- |
| `interfaces interface all` | `invalid interface type in 'all'` |
| VLAN adı `all` | `Invalid vlan name specified. Reserved word cannot be used` |

Karar `JunosDialect::supportsAllKeyword()` içinde durur; ELS'te `false`.
Sonucu üç yerde birden kullanılır ve üçü de aynı cevabı **sorar**, ayrı ayrı
vermez:

- **Liste** — `all` seçeneği ekrana hiç konmaz (kullanıcı seçemeyeceği bir
  seçeneği seçmesin).
- **Doğrulama** — elle gönderilse bile istek reddedilir, cihaza gitmez.
- **İpucu metni** — ekranda "all bütün portları kapsar" yazmaz.

### "all" HER hiyerarşide aynı anlama gelmez

Aynı sözcük, aynı cihazda, komşu hiyerarşilerde farklı sonuç veriyor. Ölçüm
olmadan birinden diğerine genelleme yapılmaz:

| Hiyerarşi | ELS öncesi (test2) | ELS (test4) |
| --- | --- | --- |
| `storm-control interface all` | KABUL | RED |
| `secure-access-port vlan all` | KABUL | RED |
| `protocols rstp interface all` | KABUL | **KABUL** |
| `analyzer input ingress interface all` | ölçülemedi (VLAN'sız port yok) | **KABUL** |
| VoIP `interface all` | **RED** | **RED** |

RSTP ve analyzer `all`'ı ELS'te de kabul ediyor; oradaki seçenek KALIR.

### VoIP'te "all" geçerli değildir

Bu bir lehçe farkı değildir: cihaz bu adı iki lehçede de kabul etmez. Aynı
belge, tek fark arayüz adı:

```
test2 / 12.3  ge-0/0/0.0    → KABUL
test2 / 12.3  all           → RED  "Interface all not found or does not
                                     have ethernet-switching family"
test4 / 24.4  ge-0/0/10     → KABUL
test4 / 24.4  all           → RED  "ethernet-switching is not enabled on all!"
test2, test4  access-ports  → KABUL
```

Bu yüzden `SwitchVoipInterface::VIRTUAL_INTERFACES` yalnızca `access-ports`
taşır. `all` ekranda sunulmaz ve elle gönderilse de reddedilir.

Doğrulama senaryosu ekranın SUNDUĞU her seçeneği denemek zorundadır: yalnızca
çalışanı (`access-ports`) yazan bir senaryo, çalışmayanı gizler; yeşil rapor
kanıt değil, örtü olur.

### Ölçülmüş olan: LAG ELS'te sorunsuz

`aggregated-ether-options` ELS'te **kabul ediliyor**. Doğrulamada görülebilecek
şu kırmızı satırlar senaryo artefaktıdır:

- `lacp` tek başına → `Number of member links configured, i.e [0]` — ae'nin
  üyesi yoksa.
- `802.3ad` üyeliği → `logical unit is not allowed on aggregated links` —
  seçilen port zaten unit 0 taşıyorsa.

`chassis aggregated-devices ethernet device-count` + gerçek bir `ae0` ile
KABUL. Ayrıntı ve genel kural: `.ai/rules/netconf.md`.

## Değer kümeleri AİLEYE değil MODELE bağlı olabilir

Lehçe (`JunosDialect`) ailenin ŞEKLİNİ bilir: hangi düğüm nerede durur.
Bir alanın kabul ettiği DEĞERLER ise modele göre de değişiyor ve bunu
lehçeye yazmak, ölçülmemiş bir kısıtı bütün aileye dayatmak olur.

Ölçülen (test2 = EX3200 / 12.3, test4 = EX2300 / 24.4):

| Değer | EX3200 12.3 | EX2300 24.4 |
| --- | --- | --- |
| `speed` 1g | KABUL (`ether-options ethernet-1g`) | **RED** `invalid value` |
| `speed` 100m / 10m | KABUL | KABUL |
| `speed` 10g | — | KABUL |
| `speed` auto | KABUL | KABUL |
| `instance-type forwarding` | KABUL | **RED** `invalid value` |
| `instance-type virtual-router` | KABUL | KABUL |
| `instance-type no-forwarding` | KABUL | KABUL |
| `instance-type virtual-switch` | KABUL | **RED** `invalid value` |

İki sonuç:

1. **Kısıt CİHAZDA değil, PIC'te.** Aynı EX2300'de ölçüldü:

   | Arayüz | speed 1g |
   | --- | --- |
   | ge-0/0/0, ge-0/0/12, ge-0/0/23 | **RED** |
   | ge-0/1/1, ge-0/1/3, ge-0/2/0 | KABUL |
   | xe-0/1/0 | KABUL |
   | mge-0/0/1 | KABUL (ama `auto` RED) |

   Yerleşik 1000BASE-T bakır portlarda hız zorla ayarlanamaz; SFP
   yuvalarında ayarlanabilir. Ölçümü cihaz genelinde saklamak, uplink
   portlarında GEÇERLİ olan bir seçeneği gizler — yetenek kaydı bu yüzden
   PIC kapsamlıdır (`ge-0/0:1000` gibi).

   Sonda dört cihazda da çalıştırıldı:

   | Cihaz | Model | port.speeds |
   | --- | --- | --- |
   | test1 | EX4200-24T / 12.3 | 10, 100, 1000 |
   | test2 | EX3200-24T / 12.3 | 10, 100, 1000 |
   | test3 | EX2200-48T-4G / 12.3 | 10, 100, 1000 |
   | test4 | EX2300-24T / 24.4 | 10, 100 |

   Üç ayrı model 1g'yi zorla kabul ediyor; yalnızca EX2300 reddediyor.
   "1000BASE-T zorlanamaz" diye genellemek YANLIŞ olurdu — kural ölçümle
   değil varsayımla yazılsaydı üç cihazda çalışan bir seçenek kaybolurdu.
2. **FBF'nin `instance-type forwarding`'i EX2300'de YOK.** Filtre tabanlı
   yönlendirme o modelde `virtual-router` ile kurulmak zorunda. FBF ekranı
   yapılırken bu varsayılmaz, cihaza sorulur.

### Nasıl sorulur: LOAD aşaması yeter

İkisi de `load-configuration` adımında düşüyor, `commit check`'te değil
(test4'te ölçüldü). Yani değer kümesi, yavaş commit check'e girmeden
sorulabilir — `SchemaProbe` aile sorusunu tam olarak böyle soruyor.

Kural: **bir değer listesi ekrana konacaksa ya ölçülmüş olmalı ya da
cihaza sorulmalı.** Belgeden okunup varsayılan değer, doğrulanmamış değerdir.

### Kim neyi bilir

| Soru | Cevabı veren | Nerede saklanır |
| --- | --- | --- |
| Hangi düğüm nerede durur (ŞEKİL) | `JunosDialect` | kod (`config/junos.php`) |
| Bu alan hangi DEĞERLERİ kabul eder | cihaz | `switches.device_capabilities` |

Değer sorusu lehçeye YAZILMAZ: EX2300 ile EX4300 aynı ailededir ve aynı
değer kümesini kabul etmeyebilirler. Yazılsaydı, ölçülmemiş bir kısıt bütün
aileye dayatılırdı.

Sonda (`CapabilityProbe`) özel bir adaya yalnızca LOAD dener; commit check
bile yapmaz ve adayı atar. Ölçüm, yapıldığı Junos sürümüyle birlikte
saklanır; sürüm değişince kendiliğinden yenilenir.

**BİLİNMEYEN = SERBEST.** Hiç ölçülmemiş cihazda seçenek kısıtlanmaz: sonda
çalışmadı diye çalışan bir özelliğin kaybolması, yanlış bir değeri
denemekten kötüdür. Cihaz zaten reddederse hata görünür ve kayda geçer;
sessiz bir kayıp görünmez.

Yeni yetenek eklemek: `DeviceCapability` uygulayan bir sınıf ve
`config/capabilities.php`'de bir satır. Çıkarmak: aynı iki yerden silmek.

## "Eşik aşılınca portu kapat" ELS'te profilin içindedir

ELS ÖNCESİ switch genelinde tek satır:

```
ethernet-switching-options storm-control action-shutdown
```

ELS'te böyle bir satır YOK; ayar HER PROFİLİN içinde yaşar (test4'te ölçüldü):

```
forwarding-options storm-control-profiles <p> {
    all { bandwidth-percentage 80; }
    action-shutdown;          <- burada
}
```

Reddedilenler: `ethernet-switching-options ... action-shutdown` → syntax
error; `all` düğümünün İÇİNE yazmak → syntax error; arayüzün altına
`storm-control action-shutdown` → syntax error.

İki sonuç:

1. `applyShutdown()` lehçeyi SORMAK zorunda: bütün fırtına yazmaları
   `document()` üzerinden dallanır; bu yol doğrudan ELS öncesi biçimi yazarsa
   ELS'te düşer. **Aynı satıra iki yol çıkıyorsa ikisi de aynı soruyu
   sormalı** — tekrarlayan bir hata sınıfı budur.
2. Profil `replace="replace"` ile yazıldığı için kapatma bayrağı her arayüz
   yazımında TEKRAR emit edilmeli; yoksa bir port düzenlemesi onu sessizce
   silerdi.

## Filtre tabanlı yönlendirme (FBF) ÜÇ parçadır

Müşteri trafiğini ayrı bir tabloya atmak Junos'ta üç ayrı yerde kurulur ve
**üçü olmadan çalışmaz**:

```
1. routing-instances <ad> { instance-type forwarding; }   tablo
2. firewall ... term ... then routing-instance <ad>;      trafiği atan kural
3. rib-groups <grup> { import-rib [ inet.0 <ad>.inet.0 ]; }
   routing-options interface-routes rib-group inet <grup>;   yol kopyalama
```

**Üçüncüsü en çok atlanan parçadır ve sessizdir.** Arayüz yolları
(connected) yalnızca `inet.0`'dadır; kopyalanmazsa hedef tablo kendi
arayüzlerine bile ulaşamaz ve trafik düşer — **cihaz hata VERMEZ**, commit
temiz geçer. Panel bu yüzden üçünü tek ekranda gösterir ve eksik parçayı
açıkça söyler.

`import-rib` listesinde **SIRA anlamlıdır**: Junos ilk tabloyu birincil sayar
ve o, grubun bağlandığı tablo olmalıdır.

### Tür cihaza göre değişir

EX2300 `instance-type forwarding`'i reddediyor ("invalid value"); orada FBF
`virtual-router` ile kurulmak zorunda. EX3200 dördünü de kabul ediyor.
Ayrıntı ve ölçüm: "Değer kümeleri AİLEYE değil MODELE bağlı olabilir".

Ekran türleri yetenek kaydından süzer; cihazın kabul etmediği tür sunulmaz
ve elle gönderilse de reddedilir.

## Protokol anahtarları: lldp, lldp-med, igmp-snooping

Kabin switchlerinde sık tanımlı üç satır; panel onları da gösterir ve yönetir:

```
set protocols lldp interface all
set protocols lldp-med interface all
set protocols igmp-snooping vlan all
```

Cihazda duran ama panelde olmayan her satır, "panelden yönetiyoruz"
iddiasını zayıflatır: bir sonraki kişi CLI'a düşer ve panel dışında yapılan
değişiklik denetim kaydına girmez.

İkisi de KABUL, iki lehçede de (test2 ve test4'te ölçüldü) — `all` dahil.
Bu üçü için lehçe dallanması GEREKMEZ; `all`ın hiyerarşiye bağlı olduğu
kuralı burada geçerli değil çünkü ölçüldü.

**`dcbx` MODELLENMEDİ.** Ölçülen cihazların hiçbirinde kullanılmıyor. Kullanılmayan bir ayarı
yönetmek, doğrulanmamış bir yol açmaktır — ilk kullanan kişi onu ölçmeden
kullanır.

### İki tuzak

- **Kapatmak SİLER.** Boş bir düğüm bırakmak protokolü "tanımlı ama
  kapsamsız" hâlde bırakır ve o AYRI bir durumdur. Panel `delete="delete"`
  yazar.
- **Kapsam değişiminde `replace` şart.** Junos merge yalnızca ekler; bir
  arayüzü listeden çıkarmak replace ister. Merge kullanılsaydı kaldırılan
  arayüz cihazda kalır ve panel onu kaldırdığını sanırdı.

Envanterde NULL ile boş liste FARKLIDIR: null "cihazda tanımlı değil", boş
liste "tanımlı ama kapsamı yok". İkisini karıştırmak açık bir protokolü
kapalı göstermek olurdu.
