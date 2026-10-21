# Neler destekleniyor

Panelin **ne yapabildiği** ve **ne ölçüde** yapabildiği.

## Cihazlar — Juniper EX (JunOS)

Panel iki JunOS lehçesi tanır ve hangisinin geçerli olduğunu **cihaza sorarak**
öğrenir: özel bir aday yapılandırmaya deneme yükü basılır, sözdizimi hatası
verirse ELS, geçerse pre-ELS. Sonda `commit` atmaz.

| Aile | Lehçe | Ne ölçüde |
| --- | --- | --- |
| EX4200 | pre-ELS | Cihazda ölçüldü (omurga senaryoları) |
| EX3200 | pre-ELS | Cihazda ölçüldü (erişim senaryoları) |
| EX2200 | pre-ELS | Cihazda ölçüldü |
| EX2300 | **ELS** | Cihazda ölçüldü; panelin ELS yolları yalnızca burada sınanabiliyor |

**Tanımadığı modeli uydurmaz:** ekranda "Bilinmiyor — ELS öncesi varsayılıyor"
yazar. Panelin modellemediği yapılandırma satırları **korunur**; okunamayan
kısım `residual_xml` olarak saklanır ve geri yazılır. Panel bilmediği bir şeyi
silmez.

Listedeki aileler dışındaki JunOS cihazları çalışabilir ama **ölçülmemiştir**.
Yeni bir aileyi üretime almadan önce okuma tarafıyla başlayın; yazma
denemelerini canlı cihazda yapmayın.

## Cihaza yazma — NETCONF

Yazma yolu `commit confirmed` üzerinedir ve **geri alınabilir**: ikinci oturum
onaylamazsa cihaz kendini eski hâline döndürür.

```
lock → compare rollback 0 (BOŞ olmalı) → load → compare → commit confirmed N dk → unlock
                                                          ↓
                                              ikinci oturum: lock; commit; unlock
```

- Fark boşsa **commit hiç atılmaz.**
- Kilit; yükleme, commit ve onayın tamamını kapsar — Junos'ta bekleyen bir
  `commit confirmed` varken başkasının commit'i onu da onaylar.
- Aynı kayda ikinci bir yazma isteği **reddedilir.**
- Cihaza yazan aksiyonlar ayrıca NETCONF kimliği ister; bu koşulu yönetici
  bile atlayamaz.
- Durumu değiştiren her istek **gerekçe** ister ve gerekçe denetim zincirine
  girdiği için sonradan değiştirilemez.

Ayrı `commit check` atılmaz: ELS'te on saniyeyi aşabilir ve `confirmed` zaten
aynı doğrulamayı yapar.

**Uzun işler ekranda görünür.** Cihaza yazma ELS'te bir dakikaya yaklaşabilir,
parola turu ise dört oturum ve üç commit sürer; toplu turda onlarca cihaz aynı anda
sırada bekliyor. Cihaz parolaları ekranında her satır kendi **ilerleme
çubuğunu** gösterir (kaçıncı adımda, ne yapılıyor) ve o satırın düğmeleri
gizlenir — ikinci bir iş, işin tekilliğine takılıp sessizce düşerdi. Çubuk
sunucudaki aşamayı okur, tahmin etmez: takılan bir tur dolmaya devam etmez.

İş bitince sayfa **yerinde tazelenir**, tarayıcı yenilemesi yapılmaz: açık
paneller, kaydırma konumu ve az önce basılan bildirim kaybolmaz.

## Ölçüm kaynakları

| Kaynak | Ne verir | Ne ölçüde |
| --- | --- | --- |
| **NETCONF** | Arayüz listesi ve yapılandırma | Asıl gerçek budur |
| **Zabbix** | Hız, dupleks, anlık trafik, CPU/bellek/sıcaklık, geçmiş | **YALNIZCA OKUNUR** — oraya hiçbir şey yazılmaz |
| **SNMP** | IP adresleri, commit geçmişi | Yedek kaynak |

- **Devre kesici:** hata veren kaynak bir süre denenmez; olmasaydı kesinti
  anında her sayfa zaman aşımı kadar donardı.
- **"Bilinmiyor" ≠ "çevrimdışı":** hiçbir kaynak yanıt veremezse durum
  bilinmiyor olur ve kayıtlı ölçümler silinmez.
- **Bir HTTP isteği ölçüm için cihaza bağlanmaz.**

Zabbix `ZABBIX_URL` **ve** `ZABBIX_API_TOKEN` birlikte verilmemişse kaynak
kendini kapalı sayar; panel hata vermez, o kaynağı kullanmaz.

## Uzak oturum — tarayıcıdan

| Protokol | Nasıl | Koşul |
| --- | --- | --- |
| **SSH** | `ttyd` | Her zaman çalışır |
| **RDP** | Guacamole (guacd + Tomcat, json-auth) | Yalnızca `GUACAMOLE_SECRET` verilmişse |

İki proxy de nginx `auth_request` ile korunur. `GUACAMOLE_SECRET` verilmezse
200 MB'lık JVM hiç açılmaz.

### RDP açılmıyorsa: sertifikanın imzası

Panelde **"The remote desktop server is currently unreachable"**, guacd
günlüğünde **"SSL/TLS connection failed (untrusted/self-signed
certificate?)"** görülüyorsa sebep büyük olasılıkla GÜVEN DEĞİL, İMZA
ALGORİTMASIDIR.

OpenSSL 3, SHA-1 imzalı sertifikaları güvenlik düzeyi 1 ve üstünde
reddediyor. FreeRDP bu düzeyi kendi içinde sabitliyor, yani panel tarafında
bir ayarla aşılamıyor — `OPENSSL_CONF` ile denendi ve `SSL_CTX_set_security_level`
çağrısı yapılandırmayı eziyor.

Windows 7 / Server 2008 R2 kendi ürettiği RDP sertifikasını **her zaman**
SHA-1 imzalar; sertifikayı silip yeniden ürettirmek işe yaramaz. Çözüm
makinede SHA-256 imzalı bir sertifika üretip RDP'ye bağlamaktır
(`certreq`, her Windows sürümünde var). Sertifika yine kendi imzalıdır —
panel zaten güveni doğrulamıyor — değişen yalnızca imza algoritmasıdır.

Ölçmek için: makinenin 3389'una TLS ile bağlanıp sertifikanın
`Signature Algorithm` alanına bakılır. Windows 8.1 / Server 2012 R2 ve
sonrası zaten SHA-256 üretiyor.

## Adresleme — ne kullanılıyor, ne boşta

Amaç **çakışmayı önlemek**: aynı aralığın ikinci kez verilmesi ancak sahada,
trafik bölünürken fark ediliyor.

**Blok satırına tıklayınca yerinde açılır.** İçinde yalnızca tanımlı alt ağlar
değil, **aralarındaki boşluklar** da yazar. Boşluk düzgün bir CIDR'a denk
gelmeyebilir — 192.0.2.16'dan 255'e kadar olan yer `/28 + /27 + /26 + /25`
demektir — ve satırda hangi maskelerin sığdığı görünür, çünkü kullanıcı oraya
alt ağ açacaktır.

Bölünmemiş bir blok seçilince **tek tek adresler** ve her birini kimin tuttuğu
listelenir. Kaynak tek tablo değil: adres switch arayüzünde, sunucuda ya da
BGP komşusunda duruyor olabilir.

### Atanmış blok tükenir

Bir blok bir switchin VLAN'ına, portuna ya da route'una bağlandığı anda o
aralık **orada kullanılıyor** demektir. Bundan sonra:

* satırda **"Kullanımda · atanamaz"** yazar,
* başka bir cihazın ağ listesinde **görünmez** — yalnızca sahibininkinde
  kalır, orada düzenlenebilmeli,
* elle yazılsa bile **ikinci bir cihazdan route edilemez.** Listeden çıkarmak
  tek başına yetmiyor: alan elle giriş de kabul ediyor (varsayılan route ve
  sağlayıcı özet ağları envanterde durmaz), asıl kural doğrulamada.

Envanterde karşılığı **olmayan** ağ serbesttir; panel bilmediği bir ağ
hakkında konuşmaz.

## Yanında gelenler

- **Sunucu envanteri**
- **IP adres yönetimi (IPAM)**
- **Prefix anons talepleri** — yetki denetimi RPKI ROA ve IRR route
  kayıtlarından yapılır; konteynerin 443 (RIPEstat) ve 43 (RADB whois)
  portlarına dışa çıkışı olmalıdır.
- **Denetim kaydı** — her satır bir öncekinin özetini taşır.

## Sunucu parolası değiştirme

**NYS'nin sunucuları kendine aittir.** Parolaları başka bir panelden çekilmez;
burada değiştirilir. Eşitleme yoktur: aynı sunucunun parolasının iki yerde
birden durması hangisinin doğru olduğunu bilinmez kılardı.

Tur şu sırayla yürür ve **güvenliği veren şey sıranın kendisidir:**

1. Makinede tam yetkili bir **kurtarma hesabı** açılır.
2. O hesapla gerçekten bağlanılabildiği **kanıtlanır**. Kanıtlanamazsa hesap
   silinir ve tur biter — çalışmayan bir kurtarma hesabı güvence değil,
   açıkta duran fazladan bir yetkidir.
3. Asıl hesabın parolası **kurtarma hesabıyla** değiştirilir.
4. Yeni parola **denenir**.
5. Panel kaydı **ancak bundan sonra** güncellenir.
6. Kurtarma hesabı **yeni parolayla** silinir.

Herhangi bir adımda takılırsa kurtarma hesabı makinede bırakılır ve parolası
panelde şifreli durur; ekran uyarı gösterir, "Kurtarma hesabını temizle"
kapatır.

### Hangi yoldan

| İşletim sistemi | Taşıma | Port | Kurtarma hesabı |
| --- | --- | --- | --- |
| **Linux** | SSH (phpseclib) | kayıttaki port | Açılır |
| **Windows** | **WinRM** (pywinrm) | **5985 — kayıttan okunmaz** | Varsayılan **kapalı** |

Sürücü **işletim sisteminden** seçilir, kayıttaki bağlantı protokolünden
değil: RDP komut çalıştırmayan bir ekran protokolüdür. Aynı sebeple WinRM
portu da kayıttan okunmaz — oradaki port RDP'ninkidir (3389).

Windows'ta kurtarma hesabı varsayılan olarak kapalıdır: Windows Server'da yeni
açılan yerel yönetici hesabı **uzaktan** yönetici yetkisi alamaz
(`LocalAccountTokenFilterPolicy` tanımsızken) ve tur boşuna iptal olur. O kaydı 1 yapan kurulumlarda `SUNUCU_WINRM_KURTARMA=true`.

**WinRM açık değilse tur başlamadan düşer** ("makineye ulaşılamadı"); makinede
`Enable-PSRemoting` ve 5985'e güvenlik duvarı izni gerekir. Panel bunu kendisi
açmaz.

Turlar kuyrukta ve **sırayla** koşar: aynı anda koşan turlar işçilerin
tamamını meşgul edip hepsi birden zaman aşımına girer.

Yetki ayrıdır (`rotate`): "düzenle" bunu kapsamaz — paneldeki kaydı
değiştirmekle uzaktaki makinenin parolasını değiştirmek aynı şey değildir.

## Bağlantı bilgilerinin Excel çıktısı

Sunucuların ve switchlerin **bağlantı bilgileri** (protokol, adres, port,
kullanıcı, parola) bir `.xlsx` dosyasına yazılır: dosyayı eline alan biri aynı
bağlantıyı kurabilmeli. Bir switchin **her kimliği ayrı satırdır** — NETCONF
ayrı, SNMP ayrı ve her biri ayrı bir yol.

Parolayı panelin **dışına** çıkaran tek uç budur ve kuralları ona göredir:

* Ayrı bir yetki ister (`export`). "Görüntüle" ve switchlerdeki "parolayı gör"
  bunu **kapsamaz:** ekranda görülen tek bir parola orada kalır, dosyaya
  yazılan liste panelin dışına çıkar.
* Satır başına yetki ayrıca sorulur; göremediği kayıt dosyaya girmez.
* Gerekçe zorunludur ve **her kayıt için ayrı bir denetim satırı** yazılır.
* Dakikada üç istek.

## Kimlik sağlayıcı ile bağlantı — zorunlu değil

- **Kimlik Yönetim Merkezi:** açıksa tek oturumla giriş. Kapalıyken panelin kendi
  giriş formu çalışır — **merkez zorunlu değildir.**

## Tarayıcıya gönderilen güvenlik başlıkları

Panelin her yanıtı tam bir `Content-Security-Policy` ile birlikte
`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`X-Permitted-Cross-Domain-Policies`, `Strict-Transport-Security`,
`Permissions-Policy` ve `Cross-Origin-*` başlıklarını taşır. Değerler
`config/security.php` içindedir; hangi kısıtın neden konduğu orada yazar.

Başlıklar önde duran bir web sunucusuna bırakılmaz: o katman değişir ya da
kalkarsa başlıklar sessizce kaybolur. Kural başlıklardan geneldir:
**bir katmanın varlığı yorumla değil ölçümle bilinir.**

Bir yanıt kendi başlığını yazdıysa ezilmez. Başlıklar nginx'te değil
uygulamada durur ve genel ara katman yığınındadır, böylece giriş yönlendirmesi
ve hata sayfaları da çıplak kalmaz.

## Girişte hesap kilidi yok — bilerek

Art arda yanlış paroladan sonra kilitleme varsayılan olarak **kapalıdır**:
yerel ağda çalışan bir panelde hatalı denemeler meşru kullanıcıyı kilitleyip
erişimi kesmesin. Ayar: `LOGIN_MAX_ATTEMPTS`, varsayılan 0.

**Sıfır "kilit yok" demektir ve bu kendiliğinden olmaz:**
`tooManyAttempts($k, 0)` sayacı sıfıra eşit olduğu anda dolu sayar, yani ilk
yanlış paroladan sonra kilitlerdi. Sıfır ayrıca kontrol edilir.

Kapalıyken parola denemesinin önünde bu panelde fren kalmaz; koruma dışarıdan
gelmelidir. Dışarı açılacaksa bir sayı verin (ör. 5).

## API: sunucular ve adresleme de okunabilir

Jeton ekranında yetki verilebilen bir kaynağın ucu yoksa **API tutmadığı bir
söz verir**: bir jetona "sunucuları görebilsin" demek hiçbir şey açmaz. Bu
yüzden switchlerin yanında sunucular ve adresleme de okunur.

Uçlar (hepsi salt okunur, `GET`):

| Uç | Yetki | Ne verir |
|---|---|---|
| `/api/v1/servers` | `servers:view` | Sunucu envanteri, sayfalı |
| `/api/v1/servers/{id}` | `servers:view` | Tek sunucu, son parola turunun durumu |
| `/api/v1/addressing/blocks` | `addressing:view` | Tanımlı ağlar ve **atanabilir mi** |
| `/api/v1/addressing/blocks/{id}` | `addressing:view` | Tek blok |
| `/api/v1/addressing/blocks/{id}/hosts` | `addressing:view` | Hangi adres kimde, hangisi boşta |
| `/api/v1/switches/{id}/routes` | `switch_routes:view` | Statik route tablosu |
| `/api/v1/switches/{id}/bgp-neighbors` | `switch_bgp:view` | Komşular ve oturum durumları |

**Sır dönmez.** Sunucunun bağlantı parolası **ve kullanıcı adı** yok: ikisi
birlikte sunucuya giriş demek. Parola okumak isteyen kasanın kendi ucunu
kullanır — orada ayrı yetki ve her okuma için denetim kaydı var. BGP'nin MD5
oturum anahtarı da dönmez; kaynak sınıfları alanları tek tek yazar, "hepsini
dök" demezler.

**`assignable` alanı önemli.** Bir erişim switchine route edilmiş blok
tükenmiştir: panelde artık hiçbir listede seçilemez. Dışarıdan bakan bir
istemci bunu bilmeden adres seçerse, adreslemenin önlemeye çalıştığı çakışma
dışarıda üretilir.

**Adres dökümü ayrı uç.** Liste ucunda dönseydi her blok için adres adres
tarama yapılır, yüzlerce bloklu bir kurulumda tek istek dakikalar sürerdi.
Çok büyük bloklarda liste hiç verilmez (`"limited": true`).

Belge uç listesini rotalardan üretir; belgesiz bir uç eklenirse test kırılır.

### Hâlâ API'de olmayanlar

Jetona yetki verilebilen ama ucu olmayan kaynaklar: `switch_aggregation`,
`switch_rstp`, `switch_voip`, `switch_mirroring`, `switch_secure_access`,
`switch_forwarding`, `switch_prefix_lists`, `switch_firewall`,
`switch_policies`, `announcements`.

Bunlar eklenmedi çünkü her biri kendi JSON biçimine karar vermeyi gerektiriyor
(firewall terimleri ve politika ifadeleri iç içe yapılar) ve karar, o veriyi
gerçekten okuyacak bir istemci ortaya çıkmadan verilirse yanlış verilir.
İsteyen olursa aynı desenle eklenir.

`users`, `settings` ve `logs` ise **bilerek** yok: kullanıcı listesini,
ayarları ve denetim kaydını dışarı açmak ayrı bir karardır.
