---
paths:
  - 'app/Services/Announcement/**'
  - 'app/Http/Controllers/AnnouncementController.php'
  - 'config/announcements.php'
---

# Prefix anonsu — yetki denetimi

Anons mailinde RIPE (ROA) ve IRR (route nesnesi) kontrolü yapılır. Bir blok
için hem ROA hem route nesnesi uyuşmuyorsa gönderime izin verilmez.

Ölçüt budur: bir blok **en az bir kaynak** onaylıyorsa gönderilir, gönderimi
durduran şey **hiçbir kaynağın onaylamamasıdır**. Ölçüt
`PrefixAuthorizationPolicy` enum'unda ve `announcements.authorization.policy`
ile değişir (`any` varsayılan, `all` katı kip).

Neden "en az bir": bir kurumun bloklarının bir kısmında yalnız ROA, bir
kısmında yalnız IRR kaydı bulunabilir; birleşimde her bloğun en az bir kanıtı
vardır. "İkisi birden" şartı, gerçekten kuruma ait blokları sırf bir kayıt
eksik diye durdururdu. Gerçekten başkasına ait blok ikisinde birden düşer:
ör. `203.0.113.0/24` hem ROA'da hem IRR'de AS64510'ü gösterir.

## Kanıtlanmamış yetki, yetki değildir

Kaynak başına dört durum vardır ve yalnızca biri o kaynağın onayıdır:

| Durum | Anlamı |
| --- | --- |
| Onaylı | Kayıt var ve bu ASN'yi gösteriyor |
| Onaysız | Kayıt var ama BAŞKASINI gösteriyor |
| Kayıt yok | Bu prefix için kayıt yok |
| Sorgulanamadı | Kaynak cevap vermedi |

"Sorgulanamadı" onay sayılmaz: dış servis düştüğünde "herhâlde onaylıdır"
demek, kontrolü hiç koymamakla aynı şeydir. Bir kaynağın kesintisi gönderimi
kilitlemez (ötekinin onayı yeter) ama İKİSİ birden sorgulanamıyorsa talep
durur.

Aynı sebeple prefix sayısı sınırı aşıldığında denetim atlanmaz, talep
reddedilir — `PrefixAuthorizationReport` bu yüzden `disabled()` (izin verir)
ile `refused()` (izin vermez) ayrımını taşır.

## Eksik kaynak gizlenmez

Onaylanan bir blokta bile eksik kaynak olabilir ve ekranda YAZILIR. Karşı
taraf prefix filtresini çoğunlukla IRR'den üretir; eksik IRR kaydı, talebin
cevapsız kalmasının sebebidir. Gönderimi durdurmaz, operatöre söylenir
(`PrefixAudit::gaps()`, `PrefixAuthorizationReport::incomplete()`).

## `source: RPKI` sayılmaz — en kritik ayrıntı

IRRd, ROA'lardan **otomatik route nesneleri üretip** aynı whois akışında
sunar. Bunlar IRR kanıtı sayılsaydı ikinci kontrol birincinin kopyası olurdu
ve ekran "IRR kaydınız var" derdi — oysa yok.

Örnek — `198.51.100.0/24`:

```
route: 198.51.100.0/24  origin: AS64496  source: RPKI   ← türetilmiş, sayılmaz
route: 198.51.100.0/24  origin: AS64497  source: RIPE   ← gerçek kayıt
```

AS64496 bu blokta yalnızca türetilmiş nesnede görünür. `any` kipinde blok ROA
sayesinde zaten gönderilebilir; elemenin değeri kararı değil **ekranı**
doğru tutmasıdır — eksik IRR kaydı gösterilmezse kimse onu açdırmaz. Eleme
`config/announcements.php` → `irr.ignored_sources` ile yapılır ve
`AnnouncementPermissionTest::test_rpki_turevi_irr_nesnesi_kanit_sayilmaz` kilitler.

## Sorgu TAM EŞLEŞMEDİR

`!r<prefix>` kullanılır. Kapsayan blokları da getiren bir sorguda
`192.0.2.0/24` için AS64498'in `192.0.0.0/21` nesnesi dönüyor;
başkasının toplayıcı kaydı bizim prefiximizin yetkisi sayılırdı.

Girdi de normalleştirilir: `192.0.2.77/24` ağ adresine indirilmeden
sorulursa her kaynak "kayıt yok" der.

## RADB "kaynak" değil, "sunucu"dur

`whois.radb.net` yirmi IRR'yi aynalar (RADB, RIPE, ARIN, APNIC, LACNIC,
AFRINIC, ALTDB, NTTCOM…). Route nesneleri **RIPE** kaydında duran bir kurumda
`source: RADB` şart koşulsaydı kurumun kendi bloklarının tamamı reddedilirdi.
Aranan şey nesnenin hangi aynada durduğu değil, **gerçek bir IRR kaydı olması
ve orijinin uyması**.

## Protokol: tek bağlantı, çok sorgu

IRRd kalıcı kip sunar: `!!` ile açılır, `!r…` komutları ard arda yazılır, `!q`
ile kapatılır; bütün prefixler TEK turda sorulur. Yanıt çerçevesi uzunluk
öneklidir (`A<n>\n<n bayt>C\n`, bulunamayan için `D\n`) ve **uzunluğa uyulur,
ayırıcı aranmaz**: RPSL gövdesi `country:` / `changed:` gibi "C" ile başlayan
satırlar taşır.

RIPEstat toplu uç sunmaz; prefixler `Http::pool()` ile AYNI ANDA sorulur.

## ASN SORULUR, dayatılmaz

Anonsun hangi ASN adına yapılacağı sorulur: anons yalnız kurumun kendi ASN'si
üzerinden değil, müşteri ASN'si adına da istenebilir.

`AsnDirectory::options()` switchlerin `routing-options autonomous-system`
değerini okur ve listeyi doldurur. Bu liste bir **KOLAYLIKTIR, kısıt
değildir**: müşterinin numarası hiçbir switchte yazmaz. Alan `x-form.picker`
ile çizilir, "Listede yok — elle gir" seçeneği taşır.

Kilitli bir liste müşteri talebini hiç kabul etmezdi; switchlerde yalnızca
özel ASN tanımlıysa da hiçbir blok onaylı çıkamazdı, çünkü özel ASN'lerin
ROA'sı olmaz.

### Geçersiz değer varsayılana ÇEVRİLMEZ

`prepareForValidation` boş ya da geçersiz ASN'yi `announcements.default_asn`
ile DOLDURMAZ: alan serbest olduğu için müşterinin numarasını yanlış yazan
kullanıcının talebi sessizce BİZİM ASN'miz adına giderdi. Düzeltilemeyen değer
olduğu gibi bırakılır ve doğrulama reddeder; boş alan da doldurulmaz.

## AS numarasının tek yazımı

`App\Support\Net\Asn` — "as64496", "64496" ve "AS64496" aynı numaradır,
panelde `AS64496` dolaşır. Aksi hâlde aynı ASN listede seçili görünmez,
önbellekte iki anahtar açar ve mailde her seferinde başka biçimde basılır.

Aralık da orada denetlenir: `\d{1,10}` gibi bir desen 32 bitlik aralığın
(0-4294967295) dışındaki numaraları da kabul eder.

Normalleştirme TEK yerdedir; `ltrim($asn, 'ASas')` gibi bir kısayol başka
sınıflarda tekrarlanmaz (bkz. `.ai/rules/modularity.md`, "aynı soruyu ikinci
bir yerde cevaplıyor muyum").

## Eksik kayıt engel değil, görev listesidir

Varsayılan kipte en az bir kanıtı olan blok durmaz; kaydı eksik bloklar ekranda
uyarı olarak görünür. Eksik olan kayıt açtırılır; kural onu engel değil, görev
listesi yapar.
