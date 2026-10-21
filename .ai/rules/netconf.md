---
paths:
  - 'app/Services/Netconf/**'
  - 'app/Jobs/**'
  - 'config/netconf.php'
---

# NETCONF

## Oturum pahalıdır, RPC ucuzdur
EX4200 / JunOS 12.3R12.4 üzerinde yaklaşık değerler:

| İşlem | Süre |
| --- | --- |
| Oturum açıp tek RPC | ~2.0 sn |
| Aynı oturumda üç RPC | ~2.2 sn |
| Ayrı ayrı üç oturum | ~6.7 sn |
| SNMP get | ~0.005 sn |

Her oturum RE'de bir `mgd` süreci fork'lar; art arda açılan birkaç oturum RE'nin
boşta oranını yarıya kadar düşürebilir. Bu yüzden:

- Bir işte TEK oturum açılır, bütün RPC'ler onun içinde çalışır
  (`NetconfClient::run()` bunu zorunlu kılar; oturum finally ile kapanır).
- Oturum açık bırakılmaz, havuzlanmaz.
- Switch başına aynı anda tek iş çalışır (`ShouldBeUnique`).
- Ekranlar cihaza gitmez, veritabanını okur.

## Hangi veri hangi kaynaktan
Aynı alanı iki kaynağın yazması karışıklık üretir; sahiplik nettir:

| Veri | Sahip |
| --- | --- |
| Model, seri no, JunOS sürümü | NETCONF |
| Port envanteri, admin/oper durumu, açıklama, hız ayarı, PoE, port modu | NETCONF |
| VLAN'lar ve port üyelikleri | NETCONF |
| Route tablosu, prefix list'ler, firewall filtreleri | NETCONF |
| Commit geçmişi | NETCONF |
| Anlık trafik, ölçülen hız, duplex, cpu/bellek/sıcaklık | Zabbix |
| IP adresleri | SNMP |

SNMP'nin VLAN ve commit işleri NETCONF kimliği tanımlı cihazlarda kendiliğinden
atlanır (`SyncSwitchVlansJob`, `SyncSwitchCommitsJob`); yalnızca NETCONF'suz
cihazlar için yedek yoldur.

Zabbix port işi `admin_status` YAZMAZ. Şablon ifAdminStatus toplamıyor ve sabit
"up" yazmak, panelden kapatılmış portu açık gösterirdi.

Port listesinin kaynağı DONANIMDIR, yapılandırma değil: bu switchlerde
yapılandırmada ge-0/1/* ve xe-0/1/* uplink modülü tanımlı ama modül takılı
değil. Config üzerinden okunsaydı olmayan portlar envanterde görünürdü.

## NETCONF poll edilmez, tetiklenir
Sık değişen her şey (durum, cpu, bellek, sıcaklık, uptime, port trafiği)
Zabbix'ten gelir. NETCONF yalnızca yavaş değişen yapılandırma için kullanılır.

Config ancak commit olduğunda değişir. Ucuz SNMP sorgusu (jnxCmCfgChgEventTable,
`CommitReader`) "commit oldu mu" der; olduysa tek NETCONF oturumu açılır.
Takvimle sabit aralıklı NETCONF çekme eklenmez.

## Sürüm ayrımı çerçeveleme katmanındadır
`Framing\MessageFraming` arayüzü çerçevelemeyi soyutlar; `FramingNegotiator`
cihazın hello yeteneklerine bakıp sürümü seçer. 12.3R12.4 cihazlar
yalnızca base:1.0 (`]]>]]>`) bildirir, `ChunkedFraming` (base:1.1) hazır durur
ve 1.1 bildiren bir cihaz eklendiğinde kendiliğinden devreye girer.

Yeni bir sürüm için: `MessageFraming` uygulayan sınıf yazılır,
`FramingNegotiator::SUPPORTED` başına eklenir, `config/netconf.php`'deki
yetenek listesine kimliği yazılır. Oturum kodu değişmez.

Hello mesajı sürümden bağımsız DAİMA base:1.0 biçiminde gönderilir
(RFC 6242, bölüm 4.1) — hangi sürümde anlaşıldığı ancak hello'dan sonra bilinir.

## Yazma yalnızca ConfigurationWriter üzerinden
Cihaza commit atan tek yol `Netconf\ConfigurationWriter::apply()`. Başka
hiçbir yerden `commit-configuration` çağrılmaz. Akış ve her adımın gerekçesi:

1. `open-configuration private` — kendi kopyamız. Paylaşılan aday
   yapılandırmada başkasının bekleyen değişikliği varsa devralınmaz.
2. `load-configuration action="merge" format="xml"`.
3. `compare rollback 0` — fark okunur ve kayda geçer. **Fark boşsa commit hiç
   atılmaz**; işlem etkisizdir.
4. `commit-configuration check`.
5. `commit-configuration confirmed` + `confirm-timeout`
   (`config/netconf.php` → `confirm_minutes`, varsayılan 2).
6. YENİ oturumda `commit-configuration` ile onay. Yeni oturum açılabiliyorsa
   değişiklik yönetim erişimini bozmamış demektir; açılamazsa onay verilmez ve
   cihaz kendini geri alır.

Junos uyarıları `rpc-error` olarak döner ve severity `warning` taşır; bunlar
işi durdurmaz, `NetconfSession::warnings()` ile toplanır ve ActivityLog'a
yazılır. `configure private`a her girişte gelen "uncommitted changes will be
discarded on exit" bunlardan biridir ve bekleyen değişiklik olduğu anlamına
GELMEZ.

Yazma kuyruktadır ama KÖR TEKRAR YOKTUR (bkz. "Cihaza yazma KUYRUKTA
yapılır"): cihaza iki kez yazılamaz. Commit on saniyeleri aşabilir; yazma
işçisinin zaman aşımı bu yüzden eşitleme işçilerininkinden çok uzundur.

Yetki `configure`'dur ve envanteri düzenleyen `update`ten ayrıdır; Gate::before
yönetici bypass'ı bu yeteneği bilinçli olarak atlamaz (kimlik yoksa düğme
gösterilmemeli).

Risk sınıflaması — otomasyona yalnızca birinci sınıf girer:
- Düşük: `snmp`, `system`, `syslog`, port `description`. PFE'ye dokunmaz.
- Yüksek: `interfaces`, `vlans`, `firewall`, `rstp`, `poe`. eswd/PFE yeniden
  programlanır; EX3200/4200'de link flap ve çökme bu yolda olur. Bakım
  penceresi ve konsolda biri olmadan yapılmaz.

## Junos XML tuzakları (hepsi cihazda doğrulandı)
- Silme: `delete="delete"` özniteliği. Olmayan bir düğümü silmek `warning`
  döndürür, hata değil; commit bozulmaz.
- Liste değiştirme `replace="replace"` ile ÇALIŞMAZ; merge üstüne ekler. VLAN
  üyeliğinde önce `<vlan delete="delete"/>` yüklenir, sonra yeni üyeler ikinci
  bir `load-configuration` ile gelir. İkisi aynı oturumda, TEK commit'te
  (`ConfigurationWriter::apply()` liste kabul eder).
- Hız ve auto-negotiation aynı `ether-options/speed` düğümüdür; ikisi birden
  yazılamaz. `speed replace="replace"` burada çalışır.
- PoE varsayılan olarak açıktır; açmak `<interface delete="delete">` demektir.
- Commit yorumu `<log>` alanındadır; `<comment>` Junos'un kendi notudur
  ("commit confirmed, rollback in 2mins").
- Commit yorumuna ASCII dışı karakter gönderilmez (bkz. `CommitLog`).
- Bir VLAN'a `l3-interface vlan.N` verilecekse `interfaces vlan unit N` AYNI
  yüklemede tanımlanmalı; yoksa "Interface must already be defined under
  [edit interfaces]" ile reddedilir.
- Access portta tek VLAN üyesi olabilir. VLAN ekranından porta VLAN atamak,
  portun üyeliğini DEĞİŞTİRMEKTİR; eklemek "Access interface has more than one
  vlan member" hatası verir. Trunk portta liste büyür.
- Bir arayüzde hem `ethernet-switching` hem `inet` ailesi olamaz. Porta IP
  vermek onu ROUTED yapmak ve VLAN üyeliğini düşürmek demektir; normal kullanım
  adresi VLAN'ın L3 arayüzüne (vlan.N) vermektir.
- Bir arayüzün son adresi silinirken `family inet` de kaldırılmalı; yalnızca
  adres silinirse cihazda boş `family inet;` satırı kalır.

## Testler sıra bağımlı olmamalı
`Tests\Support\FakeJunosDevice` gelen RPC'ye bakıp cevap verir. Sırayla yanıt
kuyruğu tutan sahte taşıma (`FakeNetconfTransport`) yalnızca oturum/çerçeveleme
mekaniğini sınayan testlerde kalır; üst seviye testler cihaza NE yazıldığına
bakar, kaç RPC gittiğine değil.

## Panelde ne nereye yazar
Maske alanları her yerde dropdown'dır, elle yazılmaz
(`AddressAllocationService::maskOptions()`); yanlış maske sessizce
kaydedilmemeli.

Yazma yolları laboratuvar cihazlarında (test1/test2) denendi: SNMP adı, route
ekleme/silme, port ayarlarının üç risk sınıfı (açıklama+disable, hız+PoE, port
modu+VLAN üyeliği), VLAN ekleme/silme, prefix list girdisi ve VLAN L3 arayüzüne
IP verme; hepsinde cihaz ilk hâline döndürüldü.


## Arayüz listesinin sahibi NETCONF'tur

İki iş de switch_ports'a yazar. Zabbix kendi bilmediği her portu silseydi,
keşif kuralı yeni bir arayüzü (özellikle yeni kurulan bir `ae`'yi) görene
kadar bir saat geçebileceği için o arada silinen ae ile birlikte ÜYELİKLERİ de
kaybolurdu; iki iş birbirinin kaydını silip yazmaya başlardı.

Ayrım bu yüzden nettir:

| Veri | Sahibi |
| --- | --- |
| Hangi arayüzler var | NETCONF (donanımdan: `get-interface-information`) |
| Yapılandırma (açıklama, mod, VLAN, PoE, hız ayarı, ae) | NETCONF |
| `admin_status` | NETCONF |
| Anlık hız (`speed_mbps`), duplex, trafik | Zabbix |
| `oper_status` | ikisi de OKUR; aynı gerçek, çelişmez |

NETCONF kimliği olan switchte Zabbix satır **yaratmaz ve silmez**; yalnızca
ölçüm alanlarını tazeler. NETCONF kimliği olmayan cihazda tek
kaynak Zabbix'tir ve liste ondan kurulur.

`oper_status` bilinçli olarak iki yerden yazılır: ikisi de aynı cihazdan aynı
gerçeği okur, ama Zabbix beş dakikada bir, NETCONF on beş dakikada bir
tazeler. Link durumunun hızlı görünmesi Zabbix'e bağlıdır.

`switch_ports` SNMP ifType SAKLAMAZ: hiçbir yerde okunmaz ve arayüz listesinin
sahibi NETCONF olduğu için bayatlardı. ifType yalnızca elemek için okunur
(`config/zabbix.php` → `ignored_interface_types`).

Arayüz türü ADDAN çıkarılır (`ae\d+` → toplama). Zabbix bir ae'yi bizden önce
keşfederse varsayılan "physical" ile yazılır ve toplama ekranında hiç
görünmezdi.

## Zamanlama

`network:sync-netconf` on beş dakikada bir çalışır. Zamanlanmasaydı portlar
Zabbix'ten geldiği için fark edilmez ama BGP, firewall, politika, prefix list,
ae ve commit geçmişi elle çalıştırılmadıkça bayatlardı.

On beş dakika bilinçli bir orta yol: oturum kurmak cihazda mgd fork'lar
(~2 sn) ve bu veriler dakikalık değişmez. Panelden yapılan yazmalar zaten
commit sonrası geri okuduğu için ekran anında güncellenir; zamanlanmış iş,
CLI'dan yapılan değişikliklerin görünmesi içindir.

## Yazma kilidi (güvenlik)

Bir switche aynı anda tek yazma yapılır: `SwitchWriteLock`, `ConfigurationWriter`
içinde yükleme + commit + ONAYIN tamamını kapsar.

Sebep bir hız kaygısı değildir. Junos'ta bekleyen bir `commit confirmed` varken
BAŞKASININ attığı commit bekleyeni de onaylar. Kilit olmadan A kullanıcısının
doğrulanmamış değişikliği, B kullanıcısının commit'iyle kalıcılaşır ve cihazın
kendini geri döndürme mekanizması TAM O ANDA devre dışı kalır — güvenlik ağımız
delinir. Ters yönü de aynı: A'nın onay adımı B'nin yüklediğini onaylayabilir.

Kilit süresi `confirm_minutes * 60 + 60` saniyedir; süreç kaybolursa kilit
sonsuza kadar kalmasın diye üst sınır vardır. Bekleme süresi 5 saniyedir,
aşılırsa kullanıcıya "başka bir değişiklik sürüyor" denir.

`SyncSwitchNetconfJob` aynı kilide BLOKLAMADAN bakar: yazma sürerken okunan
yapılandırma yarım bir durumu gösterir ve envantere o yazılırdı. Kilidi
alamazsa o turu atlar ve `last_sync_at`'e dokunmaz.

Regresyon: `tests/Feature/Netconf/SwitchWriteLockTest.php`.

## Değişim algısı

`network:sync-netconf` her turda her şeyi okumaz. Önce tek RPC ile commit
geçmişi okunur; sıra numarası 0 (en son commit) öncekiyle aynıysa tur biter.
Junos'ta yapılandırma ancak bir commit ile değişir ve CLI'dan yapılan değişiklik
de commit ürettiği için atlamak tazelikten ödün vermez. Cihaza binen yük on beş
RPC'den bir RPC'ye iner.

İki durumda yine tam okuma yapılır: ayar (`netconf.change_detection`) kapalıysa
ve son tam okumanın üstünden 6 saatten fazla geçtiyse. İkincisi güvenlik ağıdır:
panelin verisi cihazdan bağımsız bir sebeple bozulmuş olabilir.

Commit satırları `upsert` ile TEK sorguda yazılır. `updateOrCreate` döngüsü
switch başına commit sayısı kadar sorgu demektir. `upsert` model olaylarını atlar; `uuid`
elle üretilmelidir (HasUuid çalışmaz).

## Ölçüm RPC'leri (EX4200, 12.3R12.4'te doğrulandı)

`get-route-engine-information` tek çağrıda beş şey verir: `cpu-idle`
(kullanım = 100 - idle; cihaz kullanım yüzdesi VERMEZ), `memory-buffer-utilization`,
`model`, `serial-number`, `up-time` (junos:seconds niteliğinde).

`get-environment-information` bütün sıcaklık sensörlerini verir ve en yükseği
alınır: RE ile PFE sıcaklığı belirgin biçimde ayrışabilir. İzleme sisteminin gösterdiği değerle
aynı olsun diye tek sensöre bakılmaz.

Arayüz ölçümü için `get-interface-information` + `<media/>` kullanılır,
`<extensive/>` DEĞİL. Extensive daha büyük bir yanıt üretir ama anlaşılan hızı
vermez; media verir. Media tek başına yeterlidir: `snmp-index`, `oper-status`,
`speed`/`duplex`, `ethernet-autonegotiation/link-partner-speed` ve
`traffic-statistics/input-bps` + `output-bps`.

Otomatik anlaşmadaki portta `<speed>` "Auto" gelir; gerçek değer
`link-partner-speed` içindedir ("1000 Mbps"). Elle sabitlenmiş portta `<speed>`
doğrudan değeri taşır ("1000mbps"). İkisi de karşılanmalıdır.

Sayısal değerler metinde değil `junos:` ön ekli NİTELİKTEDİR
(`<temperature junos:celsius="39">39 degrees C / 102 degrees F</temperature>`).
Ön ekin URI'si JunOS sürümünü içerdiği için sabit değildir; bütün ad alanları
taranarak okunur.

## base:1.1 çerçevelemesi: sınırı UZUNLUK belirler

RFC 6242 §4.2'de parçalı çerçevelemede mesaj sınırı uzunluk önekiyle
belirlenir. "`\n##\n` görene kadar oku" YANLIŞTIR: parça verisi o dört baytı
kendi içinde taşıyabilir ve mesaj tam ortasından kesilir.

Bu durum gerçekten oluşur: Junos yapılandırma metni `## Last changed: ...`
satırıyla başlıyor ve panel `applyText`/`previewText` ile kullanıcının
yapıştırdığı config metnini gönderip aynı metni `compare rollback 0` farkında
geri okuyor. ELS bir cihaz (ya da `rfc-compliant` açık bir Junos) 1.1
bildirdiği anda bu yol kırılır; en kötüsü de `commit confirmed` atılmış ama
onay oturumu hiç açılamamış olur.

Bu yüzden **okuma çerçevelemenin işidir**: `MessageFraming::read()` başlık
satırını okur, uzunluğu ayrıştırır ve TAM o kadar bayt tüketir. Taşıma iki
ilkel sunar — `readUntil()` ve `readExactly()` — ve ikisi AYNI tampondan
beslenir. Ayrı tamponlar biri diğerinin baytlarını yutardı; tampon bu yüzden
phpseclib'de değil taşımadadır.

## phpseclib istisnaları çevrilmek zorunda

`TimeoutException` ve `ConnectionClosedException` `\RuntimeException`'dan
türer, `NetconfException`'dan DEĞİL. Kod tabanındaki bütün hata yönetimi
yalnızca `NetconfException` yakalıyor; çevrilmezlerse kullanıcı hata mesajı
yerine 500 sayfası görür ve kuyruk işi anlamlı kaydını yazamadan düşer.
`SshSubsystemTransport` bunu `open()`, `write()` ve okuma için birden yapar.

## Kararı CİHAZA sormak: yazma oturumunun içinde

`ConfigurationWriter::apply()` belge listesi yerine KAPANIŞ da kabul eder;
kapanış kilit alınmış ve aday kopya açıkken çalışır. "Bu arayüzün son adresi
mi" gibi kararlar bayat envantere değil cihaza sorulabilsin diye.

`applyRollback()` de indeksi kapanışla alır. Junos'ta rollback numarası her
yeni commit'te kayıyor; ayrı bir oturumda okunduğunda iki oturum arasında
pencere kalıyor ve o pencerede CLI'dan atılan tek bir commit numarayı
kaydırmaya yetiyor — kullanıcının önizlediğinden BAŞKA bir yapılandırma
yüklenirdi.

## Düşmanca ve bozuk girdiye karşı ölçülen davranış

Cihazdan gelen XML GÜVENİLMEZ veridir: cihaz ele geçirilmiş, taklit edilmiş
ya da araya girilmiş olabilir; bir sürüm yükseltmesi beklenmedik yapı
döndürebilir.

### Çözümleyici

| Deneme | Ölçülen davranış |
| --- | --- |
| XXE (`file:///etc/passwd`) | Dış varlık yüklenmez — sızıntı yok |
| İç varlık genişletmesi | Genişletilir; libxml ancak kendi sınırında durdurur |
| Kesik XML, XML olmayan, boş bayt, binlerce kat iç içe | Temiz reddedilir |
| Tanımadığı eleman | Kabul edilir (yeni sürüm uyumu için gerekli) |

**Kural:** `DOCTYPE` içeren yanıt tümden reddedilir. NETCONF yanıtında doküman
türü bildirimi hiç bulunmaz; kabul etmek karşılığında hiçbir şey kazanmadan
varlık genişletmesine kapı açar. `LIBXML_NONET` ile ağ erişimi de kapalıdır.

### Junos'a bozuk RPC

test1 üzerinde ölçüldü (hiçbiri commit atmadan):

| Gönderilen | Junos |
| --- | --- |
| Bilinmeyen RPC, kapanmamış etiket, iç içe yükleme | `syntax error` — oturum SAĞLAM kalır |
| Aday açmadan `commit check` | KABUL (committed yapılandırmayı denetler) |
| Aday açmadan `close-configuration` | "Configuration database is not open" |
| 5000 karakterlik arayüz adı (okuma süzgecinde) | KABUL |
| **`get-configuration database="olmayan"`** | **OTURUMU DÜŞÜRÜR** |

Son satır önemli: geçersiz bir öznitelik değeri oturumu anında kapatır.
Sonrasında aynı oturumdaki her istek SSH katmanında patlar. phpseclib'in iç
dili ("Unable to request pty") operatöre gösterilmez, çevrilir: "Cihaz NETCONF
oturumunu düşürdü; işlem yarıda kaldı."

**Toparlanma:** panel her işlem için yeni oturum açtığı için sonraki işlem
sorunsuz çalışır.

### Kimlik adları

Junos kimlik adında neredeyse her şeyi kabul ediyor (boşluk, tırnak, noktalı
virgül, süslü parantez); yalnızca 300 karakteri aşanı ve boşu reddediyor.
Panelin 255 sınırı cihazdan daha sıkıdır. XML kaçışı taşımayı koruduğu için
enjeksiyon yolu yoktur.

### İleride uyumsuz Junos gelirse

`JunosSchemaProfile::guess()` tanımadığı modeli UYDURMAZ, `null` döner
(QFX, EX9200, MX, SRX denendi). Kesin cevabı cihaza soran şema sondası verir.

Şema bilinmiyorsa panel ELS ÖNCESİ varsayar — yanlış ailede bu, cihazın
yazmayı reddetmesi demektir. Bu yüzden şema ailesi switch ekranında
görünür ve bilinmiyorsa "Bilinmiyor — ELS öncesi varsayılıyor" yazar; operatör
hatanın sebebini görebilsin.

Kilitler: `IncompatibleJunosTest`, `HostileResponseTest`, `FaultyInputTest`.

### Sürümler arası fark: oturumu düşüren öznitelik

Bozuk RPC denemeleri dört lab cihazında da bir satır dışında birebir aynı
sonucu verir:

| Deneme | 12.3R12.4 (test1/2/3) | 24.4R2-S4 (test4) |
| --- | --- | --- |
| Bilinmeyen RPC, olmayan stanza, kapanmamış etiket, iç içe yükleme, RPC sonrası fazla gövde | `syntax error`, oturum sağlam | aynı |
| Yanlış ad alanı, aday açmadan `commit check`, 5000 karakterlik arayüz adı | kabul | aynı |
| Aday açmadan `close-configuration` | "Configuration database is not open" | aynı |
| **`get-configuration database="olmayan"`** | **OTURUMU DÜŞÜRÜR** | temiz `invalid database` hatası, **oturum sağlam** |

Yani oturumu düşüren davranış 12.3'e özgüdür; Juniper 24.4'e gelene kadar
düzeltmiş. Panel ikisini de kaldırıyor: hata çevriliyor ve her işlem yeni
oturum açtığı için sonraki işlem etkilenmiyor.

Zamanlama farkı da var: 24.4 belirgin şekilde yavaş (aday açmadan
`commit check` 24.4'te 12.3'tekinin birkaç katı sürer). Uzun doğrulama turlarında
zaman aşımı buna göre seçilmeli.

Kimlik adı sınırları İKİ SÜRÜMDE DE aynı: boşluk/tırnak/noktalı virgül/süslü
parantez kabul, 300 karakter üstü "key value is too long", boş ad syntax
error.

## Doğrulama raporu: SENARYO artefaktı ile PANEL hatası ayrı sayılır

`network:verify-writes` iki farklı şeyi aynı kırmızı satırla gösterebilir:

1. **Panel hatası** — panelin ürettiği Junos'u cihaz reddediyor. Gerçek bulgu.
2. **Senaryo artefaktı** — senaryo, cihazda olmayan bir duruma yazıyor.
   Panelde hata yok; cihaz haklı olarak reddediyor.

Örnekler:

| Satır | Görünen | Gerçek |
| --- | --- | --- |
| `toplama · ae ayarları` → `syntax error` | panel hatası sanılır | ae0 tanımlı değilse senaryo FİZİKSEL porta düşer |
| `ae: üyelik 802.3ad` → `logical unit is not allowed` | panel hatası sanılır | seçilen port zaten unit 0 taşıyorsa |
| `ae: lacp` → `Number of member links configured, i.e [0]` | panel hatası sanılır | ae'nin üyesi yoksa |
| `fırtına: interface all` → `invalid interface type in 'all'` | artefakt sanılır | GERÇEK bulgudur: ELS "all" kabul etmez |

Son satır kuralın özü: **ayrımı tahminle değil ÖN KOŞULLA yapılır.** Bir
senaryonun kırmızısı ancak ön koşulları sağlandığında bulgu sayılır.

### Kural

- Ön koşulu tutmayan senaryo cihaza **gönderilmez**;
  `ScenarioNotApplicable` fırlatır ve raporda `ATLANDI (ön koşul): <gerekçe>`
  olarak görünür.
- Senaryo, ön koşul yokken **benzer bir nesneye düşmez**. `?? $port` gibi
  yedeğe düşmek artefakt üretir (ae yerine fiziksel port yazmak gibi).
- Lehçeye göre değişen ad ve biçimler senaryoda da **lehçeden** sorulur
  (`supportsAllKeyword()` gibi). Senaryo sabit yazarsa ELS'te sekiz satır
  birden kırmızı yanar ve panelde hata yokken varmış gibi görünür.
- `CheckOnlyWriter::$sonuncu` her senaryodan **önce sıfırlanır**. Sıfırlanmazsa
  cihaza gitmeyen senaryo, bir öncekinin sonucunu kendi sonucu gibi bildirir.
- Komut kırmızı satır varsa **başarısız döner**. Yeşil çıktının içinde
  kaybolan bulgu olmasın.

Rapor sonunda `GECTI n · RED n · ATLANDI n` sayımı basılır. **RED sıfır
değilse panel incelenir**; ATLANDI incelenmez, cihazda o ön koşul yoktur.

### Senaryo, ekranın SUNDUĞU her seçeneği denemeli

Yeşil rapor kanıt değil, örtü olabilir. Örnek: VoIP ekranının sunduğu iki
sanal addan (`access-ports` ve `all`) yalnızca `access-ports`'u yazan senaryo
yeşil rapor verir, oysa `all` cihazda geçerli değildir
(bkz. `.ai/rules/junos.md`).

Bir seçenek listesi ekleniyorsa senaryo o listenin **her** üyesini —
hiç değilse her SINIFINI (sanal ad, gerçek port, sınır değer) — dener.
Denenmeyen seçenek, doğrulanmamış seçenektir.

### Yeni senaryo eklerken

1. Ön koşulu nedir? (ae var mı, VLAN var mı, port anahtarlama modunda mı)
2. Ön koşul yoksa ne olur? Yedeğe düşülmez — `ScenarioNotApplicable`.
3. Lehçeye göre değişen bir ad/biçim var mı? Varsa lehçeye sorulur.
4. Ekran burada bir seçenek listesi sunuyor mu? Sunuyorsa hepsi denenir.

## Yazma yolu: private aday DEĞİL, kilit

Junos özel aday (`open-configuration private`) içinde `commit confirmed`
KABUL ETMİYOR:

```
"commit confirmed" is not supported for private configuration
```

Yazma bu yüzden private adayda yapılmaz: yapılsaydı her cihazda commit
aşamasında düşer ve bunu `network:verify-writes` göremezdi, çünkü bilerek
`commit check`'te durur — belgelerin kabul edildiğini kanıtlar, commit
dizisinin çalıştığını değil.

**Kural:** doğrulama komutunun kapsamadığı bir aşama, doğrulanmamış aşamadır.
Yeşil rapor "yazma çalışıyor" demek değildir.

Akış:

```
lock-configuration
compare rollback 0     -> BOŞ olmalı; değilse yazma reddedilir
load-configuration
compare rollback 0     -> fark; boşsa commit hiç atılmaz
commit check
commit confirmed
unlock-configuration
--- yeni oturum ---
lock-configuration
commit                 -> onay
unlock-configuration
```

`private` adayın bedava verdiği koruma kilitli akışta elle kurulur: paylaşılan
adayda başkasının bekleyen değişikliği devralınmamalı. Kilit yeni değişiklik
girmesini engeller ama ZATEN duranı kaldırmaz — koruma bu yüzden AÇIKÇA
yazılıdır (`guardPendingChanges`). Aday kirliyse panel yazmayı
reddeder ve gerekçesini söyler.

Salt okuma yolları (`previewText`, `previewRollback`) ve sondalar
(`SchemaProbe`, `CapabilityProbe`) `private` kullanmaya DEVAM eder: commit
atmazlar, kilit almaları da gereksiz yere başkasını bloke ederdi.

### Gerçek cihazda doğrulandı

test4 (EX2300 / 24.4R2-S4), kapalı bir porta tek satır yazıp geri alarak:

```
1) yaz    committed=true   + description panel-dogrulama;
2) oku    cihazda: panel-dogrulama
3) geri al committed=true  - description panel-dogrulama;
4) oku    cihazda: (yok)
```

Cihazın kendi commit geçmişinde DÖRT satır görünür — her işlem için geri
alınabilir commit ve onu onaylayan commit. Sıra tam çalışıyor.

**Bu doğrulama tekrarlanmalı**: `network:verify-writes` commit aşamasına
girmiyor, dolayısıyla bu yolu kapsamıyor. Commit dizisine dokunan her
değişiklikten sonra gerçek cihazda böyle bir yaz-oku-geri al turu yapılır.

## Cihaza yazma KUYRUKTA yapılır

Bir yazma ELS switchte bir dakikaya yaklaşabilir; istek içinde yapılsaydı HTTP
isteğini o kadar tutardı. Süre yapısal, kısaltılamaz (yaklaşık değerler):

```
oturum aç × 2     ~6 sn    ikinci oturum yönetim erişiminin KANITI
commit confirmed ~14 sn    geri alma güvencesi
commit (onay)    ~14 sn
geri okuma        ~7 sn    cihazdan doğrulama
```

Bunları kesmek güvenliği kesmek olurdu; çözüm hızlandırmak değil beklememek.

### Akış

```
istek  ->  doğrula (senkron)  ->  SwitchWriteJob kaydı  ->  kuyruk  ->  dön
ekran  ->  durum ucunu yokla  ->  bitince toast + tazeleme
işçi   ->  ApplySwitchWriteJob -> configurator -> ConfigurationWriter
```

### Kurallar

- **Doğrulama SENKRON kalır.** Form Request hatası anında görünür; kuyruğa
  yalnızca cihaza gerçekten gidecek iş alınır.
- **Kör tekrar YOK** (`$tries = 1`). Yarıda kalmış bir değişikliğe ikinci kez
  yazmak, panelin bilmediği bir duruma yazmaktır. İşçi de `--tries=1` olmalı;
  iş sınıfıyla işçi ayarı ayrışırsa iş sınıfı kazanır ama ikisi de yazılmalı.
- **Argümanlar DB'ye yazılmaz.** Parola, community ve anahtar taşıyabiliyorlar;
  yalnızca kuyruktaki iş (Redis, geçici) taşır. Durum kaydı sonucu tutar.
- **İsteğin kimliği işe taşınır.** Etkinlik kaydı "kim, neden, nereden"i
  istekten okuyor ve kuyrukta istek yok; kullanıcı, gerekçe ve IP iş kaydında
  bekler. Kayıt cihazın kabul ettiği FARKLA yazılır — panelin niyetiyle değil.
- **Başarısızlık sessiz kalmaz.** Kullanıcı sayfadan ayrılmış olabilir; düşen
  iş sebebiyle kayda geçer (`failed()`).
- **Aynı switche iki yazma paralel çalışmaz** (`WithoutOverlapping`).
  SwitchWriteLock cihaz tarafını korur; bu kuyruk tarafını.

### Tuzak: `$job` adı kullanılamaz

`InteractsWithQueue`'nun kendi `$job` özelliği var. İş sınıfında aynı adı
kullanmak sınıfı DERLENEMEZ hâle getiriyor ve PHPUnit bunu "Premature end of
PHP process" diye gösteriyor — hata mesajı yok, yığın izi yok. Durum kaydının
adı bu yüzden `$record`.

### İşçi kurulmazsa

Yazma işleri kuyrukta bekler ve ekranda "cihaza yazılıyor" hiç değişmez.
Kurulum: `deploy/README.md`.

## Süren yazma kaynağı KİLİTLER

Yazma kuyruğa alınınca ekran hemen döner ve kullanıcı aynı kayda ikinci bir
değişiklik gönderebilir. Kuyruk ikisini sırayla çalıştırırdı ama
**ikincisi birincinin sonucunu görmeden hazırlanmış** olur: kullanıcı henüz
gerçekleşmemiş bir duruma bakarak karar verir ve "gönderilmeyen alan silinir"
kuralı yüzünden birincinin etkisi sessizce geri alınabilir.

`switch_write_jobs` işin HEDEFİNİ taşır (`subject_type` + `subject_id`).
Hedef, çağrı argümanlarındaki ilk kayıttan TÜRETİLİR — her çağrıya ayrıca
hedef yazdırmaya gerek yok ve eklenen her yeni uç
kendiliğinden doğru davranır. Yeni kayıt oluşturan uçlarda hedef yoktur;
henüz var olmayan bir şey kilitlenemez.

- **Sunucu** ikinci isteği reddeder (`guardPendingWrite`).
- **Ekran** kilidi gösterir: port penceresinde alanlar yerine "bu portta bir
  işlem sürüyor" uyarısı çıkar ve "cihaza yaz" düğmesi sunulmaz.

Kilit sorgusu ekran başına TEKTİR; port başına sorulsaydı N+1 olurdu
(`SwitchPortsTest::test_the_screen_does_not_query_per_port`).

## Bildirim akışı

İki kaynak birleşir ve ikisi de zaten kayıtlıdır:
tamamlanan yazma işleri ve cihazın reddettiği/uyardığı işlemler
(eşitleme, ölçüm, sonda).

**Herkese görünür.** Cihazda olan bir şey, onu başlatan kişinin değil AĞIN
olayıdır: bir cihazın eşitlemesi saatlerce düşebilir ve hata yalnızca kimsenin
bakmadığı bir listede durursa fark edilmez. "Okundu" ise kişiye özeldir.

Yazma işlerinin başarısızları iki kaynakta da görünürdü; akış yazma DIŞI
işlemleri alır — aynı olay iki satır olmaz.

## Kuyruğunu seçmeyen iş sessizce beklemede kalır

`app/Jobs` altındaki her `ShouldQueue` sınıfı kuyruğunu AÇIKÇA seçer
(`$this->onQueue(config('...'))`). Seçmeyen iş `default` kuyruğuna düşer ve
**panelin asıl işçileri o kuyruğu dinlemez**: yazma işçisi `nms-switch-write`'ı,
zamanlayıcının açtığı işçiler `nms-switch-sync`'i dinler. Konteynerdeki
`default` işçisi yalnızca bir emniyet ağıdır; konteynersiz kurulumda o kuyruğu
dinleyen hiç kimse yoktur.

Sonuç sessizdir: ekran "kuyruğa alındı" der, iş (ör. prefix anons talebi) hiç
işlenmez ve panelde tek bir işaret çıkmaz.

`nms-switch-sync` bu projenin genel işçi kuyruğudur; zabbix, snmp, netconf ve
parola döndürme de oraya yazar.

Kilit: `QueueScopeTest`. İki şey ölçer — her işin kuyruk seçtiği, VE
seçilen kuyruğun bir dinleyicisi olduğu. Adı doğru yazmak yetmez; kimsenin
dinlemediği bir kuyruk, kuyruk değil çöp kutusudur.

Test kaynağı YORUMLARI AYIKLAYARAK okur. Düz metin araması yapsaydı
`onQueue(` çağrısını yorum satırına almak testi geçirirdi — kural görünüşte
durur, gerçekte olmazdı.
