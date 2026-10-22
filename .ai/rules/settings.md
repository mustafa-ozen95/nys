---
paths:
  - 'app/Services/Settings/**'
  - 'app/Services/Measurement/**'
  - 'config/settings.php'
  - 'config/measurement.php'
  - 'config/sync.php'
  - 'routes/console.php'
---

# Ayarlar ve ölçüm kaynağı

## Ayarın anahtarı, config yolunun kendisidir

`settings.key` = `"zabbix.url"`. `SettingsServiceProvider` boot'ta bunları
`config()` üzerine bindirir. Böylece `config('zabbix.url')` diyen çağrı yerleri
değişmez ve bir ayar satırı silindiğinde uygulama config
dosyasındaki varsayılana kendiliğinden döner.

Bindirme `register()` içinde YAPILMAZ: o aşamada önbellek ve veritabanı
bağlantıları henüz kurulmamıştır ve onları çözmeye çalışmak uygulamayı ayağa
kaldırmaz. `boot()` yeterince erkendir çünkü servisler config'i çalışma anında
okur.

`config:cache` sırasında bindirme ATLANIR (`runningConsoleCommand`). Atlanmazsa
veritabanındaki değerler dosyaya gömülür ve bir ayar silindiğinde varsayılana
dönmez.

Değerler Redis'te süresiz önbelleklidir; Redis erişilemezse doğrudan
veritabanından okunur. Redis'in düşmesi panelin Zabbix adresini ya da commit
penceresini kaybetmesine yol açmamalı.

## Yeni ayar eklemek

`config/settings.php` içindeki `items` dizisine bir satır. Ekran, doğrulama
kuralı ve tip çevrimi aynı tanımdan üretilir; Blade, Form Request ve servis
sağlayıcıda ayrıca iş yapılmaz.

Form alan adında nokta yerine ÇİFT ALT ÇİZGİ kullanılır (`zabbix__url`): HTML
form adındaki nokta PHP tarafında dizi indeksi gibi ayrıştırılır ve
"zabbix.url" adlı alan hiçbir zaman doğrulanamazdı.

Yalnızca YÜRÜRLÜKTEKİ değerden farklı olanlar veritabanına yazılır.
Karşılaştırma kayıtlı satırla değil `config()` ile yapılır — kullanıcı formu
olduğu gibi kaydettiğinde dokunulmamış ayarların hepsi düşerse config
dosyasındaki varsayılan bir daha asla geçerli olmaz.

Gizli alanda (`type: secret`) boş gönderim "değiştirme" demektir. Değer
şifreli saklanır, ekranda hiç basılmaz. APP_KEY değişip çözülemezse ayar
yokmuş gibi davranılır; uygulama durmaz.

## SMTP veritabanından yönetilir

E-posta ayarları (`mail.*`) yalnızca `.env`'de dursaydı `MAIL_MAILER=log`
kalmış bir kurulumda prefix anons talepleri kimseye gitmez, panelde
görülebilir hiçbir işaret olmazdı. Bu yüzden `/settings/email`
bölümünden yönetilir; parola şifreli saklanır.

Kilit test `EmailSettingTest::test_kaydedilen_smtp_ayari_gonderime_geciyor`
config'e bindiğini görmekle YETİNMEZ, mailer'ın Symfony transport'unu okuyup
host ve portu doğrular. Ayarın yazdığı yer ile Mail'in okuduğu yer ayrışsaydı
ekran çalışıyor görünüp hiçbir şeyi değiştirmezdi.

"Gönderme, günlüğe yaz" kipi SESSİZDİR: mail gitmiş görünür, `storage/logs`
içine düşer. Rozeti bu yüzden sarıdır.

## Sütun listesi verilen ilişkide YABANCI ANAHTAR unutulmaz

`with('editor:id,name')` gibi sütun listeli bir yüklemede `User::$with = ['role']`
her sorguda rolü de çektiği için `role_id` gerekir; seçilmezse katı kip
"The attribute [role_id] ... was not retrieved" ile düşer. Üretimde katı kip
kapalı olduğundan hata GÖRÜNMEZ, yalnızca boş bir sorgu daha atılır — yani
ancak testte ortaya çıkan bir hatadır.

Gereken tek şey adsa ilişki hiç açılmaz (`without('role')`).

## Ölçüm kaynağı üçtür ve yedeğe düşer

YAPILANDIRMA daima NETCONF'tan gelir; ayar bunu ETKİLEMEZ. Ayar yalnızca
ÖLÇÜMÜ (çevrimiçilik, uptime, CPU, bellek, sıcaklık, port oper durumu, anlık
trafik) yönetir.

`MeasurementResolver` sırayla dener: birincil kaynak, sonra yedek açıksa
kalanlar. Kaynak `MeasurementUnavailable` fırlatırsa devre kesici açılır ve
sıradakine geçilir.

**Bilinmiyor ≠ çevrimdışı.** Hiçbir kaynak cevap veremezse durum
`SwitchStatus::Unknown` olur ve KAYITLI ÖLÇÜMLER SİLİNMEZ. İzleme sistemi
çökünce bütün filoyu "çevrimdışı" göstermek yanlış alarmdır ve o
sırada gerçekten düşen bir cihazı görünmez kılar. Aynı sebeple izleme
sisteminde hiç item'ı olmayan cihaz da "çevrimdışı" yazılmaz.

Devre kesici (`measurement.failure_ttl`, öntanımlı 60 sn) olmadan Zabbix
kesintisinde her istek zaman aşımı kadar donar: 15 sn × (1 + 2 deneme) = 45 sn.
Web yolunda ayrıca kısa zaman aşımı vardır (`zabbix.web_timeout`).

Zabbix hataları `HttpClientException` seviyesinde yakalanır: bağlantı hatası da
HTTP hata kodu da (500, 401) aynı şeyi söyler — bu kaynak şu an konuşamıyor.

## Geçmiş grafiği

Geçmiş yalnızca izleme sisteminde vardır. Ölçüm cihazdan geliyorsa panel kendi
örneklerini `switch_port_samples` tablosuna yazar ve grafik ondan çizilir —
ileriye doğru dolar. Zabbix kullanılırken bu tabloya HİÇ yazılmaz.

## Zamanlama ayarlardan gelir

`routes/console.php` her `schedule:run` çağrısında `config('sync.*')` okur;
ayardaki değişiklik bir dakika içinde yürürlüğe girer, süreç yeniden
başlatılmaz. Sıklıklar `App\Enums\SyncInterval` içindeki kapalı listeden gelir;
`off` o işi tamamen durdurur.

## Zabbix çağrıları

`item.get` yalnızca `config('zabbix.items')` içindeki önekleri ister
(`search` + `startSearch` + `searchByAny`). Hostun bütün item'larını çekmek,
çoğu atılacak yüzlerce satır demektir.

`ZabbixItemReader` TEKİLDİR ve okuduğunu İSTEK BOYUNCA hatırlar. `forHosts()`
birden çok hostu tek çağrıda okur: switch başına ayrı çağrı, switch sayısı
kadar gidiş dönüş demektir. Hatırlama paylaşılan bir önbellek DEĞİLDİR: durum işi 30 saniyede bir
çalışırken beş dakikalık bayat veriyle karar vermemeli.

## Bakım

`network:prune` YALNIZCA iki tabloyu temizler: `activity_logs` ve
`switch_port_samples`. Cihaz yapılandırmasına, `switch_commits`'e, Zabbix'e
dokunmaz. Saklama süresi 0 verilirse o tablo hiç temizlenmez; örnek saklama 0
yapılırsa birikmiş örnekler de silinir (ayarın anlamı "örnek tutma"dır).

## Ekran: bölüm başına bir form

Her ayar bölümü KENDİ formudur ve kendi Kaydet düğmesi vardır. Tek dev form
telefonda sayfanın en altına kadar kaydırmayı gerektirir ve dokunulmayan
bölümleri de gönderir.

Bunun zorunlu sonucu: `SettingsRequest` yalnızca İSTEKTE BULUNAN alanları
doğrular ve kaydeder. Bütün katalog üzerinden yürünseydi gönderilmeyen her
ayar "boşaltıldı" sayılır ve tek bir kaydetme bütün yapılandırmayı silerdi.
Regresyon: `test_bir_bolum_kaydedilince_digerleri_bozulmaz`.

Açık/kapalı kutusunun önünde gizli bir `value="0"` alanı vardır; işaretsiz kutu
da gönderilmiş sayılır, "kapatıldı" ile "dokunulmadı" karışmaz.

Bölümlere atlama şeridi `x-ui.tabs` ile sayfa içi çapalara bağlanır. Yatay
kayan her şerit gibi onun da kaydırma çubuğu gizlidir (`layout.css`); biri
eksik kalırsa aynı arayüzde kimi şeritte çubuk çıkar kimisinde çıkmaz.

## Testler üretim önbelleğini görmez

`phpunit.xml` içinde `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`
var olmayan yollara ayarlıdır. Bunlar olmadan, üretimde `php artisan optimize`
çalıştırıldıktan sonra `php artisan test` çalıştırmak testlerin büyük kısmını
sebebi anlaşılmayan 419'larla düşürür: koşu üretimin config.php'sini okuyup
redis oturumu, Secure çerez ve MySQL ile çalışmaya kalkar.

## Kuyruk işçileri zamanlayıcıdan başlar (supervisor YOK)

İşçiler bir işletim sistemi servisiyle değil `routes/console.php` içinden
başlatılır. Kurulumun tek işletim sistemi bağımlılığı, zamanlayıcıyı çağıran
cron satırıdır.

Cron **site kullanıcısındadır** (php-fpm ile aynı kullanıcı), root'ta DEĞİL.
Root olarak çalışan işçiler `storage/logs` altındaki dosyaları root sahipliğine
düşürür ve php-fpm sonrasında log yazamaz hâle gelir.

İki tuzak:

1. **`--stop-when-empty` KULLANILMAZ.** İşçi kuyruk o anda boş diye hemen
   çıkar, saniyeler sonra kuyruğa giren iş bir sonraki dakika tikine kadar
   bekler: otuz saniyede bir çalışan ölçüm işi fiilen dakikada bire düşer
   (`last_sync_at` dakikada bir ilerler). İşçi `--max-time=55` ile dakikanın
   neredeyse tamamı boyunca ayakta kalır.

2. **`withoutOverlapping` kilidinin adı KOMUT METNİNDEN türetilir**,
   `->name()` ile verilen açıklamadan değil. Birden çok işçi aynı komutu
   çalıştırırsa hepsi aynı kilidi paylaşır ve yalnızca biri çalışır. Her
   işçiye `--name=worker-N` verilir.

İşçi sayısı `sync.workers` ayarındadır ve bir dakika içinde yürürlüğe girer.

## Cihaza ait ayar genel ayarlar ekranında durmaz

Yapılandırma yedeği (`set system archival ...`) bir PANEL ayarı değil, CİHAZ
ayarıdır: `switches.archival_enabled` ve `switches.archival_site`. Her switch
kendi yedek sunucusunu gösterebilir; adres girilmemişse `config('archival.site')`
(.env) varsayılanı kullanılır.

Adres kimlik taşır (`ftp://kullanici:parola@...`): `encrypted` cast ile
saklanır, modelde `$hidden`'dır, ekranda parola alanı gibi davranır (boş
bırakılırsa korunur, silmek ayrı bir kutu ister) ve commit açıklamasına
konmaz.

Genel kural: bir ayar cihazdan cihaza değişebiliyorsa `config/settings.php`
içinde yeri yoktur; cihazın kendi ayar ekranına (`switches.settings`) girer.

## İstek içinde CİHAZA GİDİLMEZ

Anlık trafik istek içinde ölçüm kaynağından okunursa, izleme sistemi o switch
için veri döndürmediğinde çözümleyici **cihaza geri düşer** ve her switch için
NETCONF oturumu açar (oturum kurmak tek başına birkaç saniye). Sayfa böylece
switch sayısı kadar oturum bekler ve büyük bir envanterde hiç açılmaz.

Ayarda "kaynak zabbix" yazması bunu önlemez: yavaşlatan şey ayarın kendisi
değil, **geri düşüştür**.

### Kural

**Bir HTTP isteği cihaza ölçüm için bağlanmaz.** Ölçümü zamanlanmış iş
toplar, istek yalnızca toplanmışı okur.

- Anlık trafik `switch_port_samples`'tan okunur; örnekleri
  `SyncSwitchPortsJob` yazıyor.
- **Tazelik sınırı** vardır (`measurement.sample_freshness`): eski bir örnek
  "anlık" değildir ve sayı olarak basılmaz, sütun boş kalır. Yanlış bir sayı,
  sayı olmamasından kötüdür.
- NETCONF kimliği OLMAYAN switchte kaynak doğrudan sorulur — orada cihaza
  gidilemez zaten ve izleme sistemi toplu okunduğu için ucuzdur.

### Aynı tuzağın diğer yüzü

Bir "kaynak seçimi" ayarı, o kaynağın **başarısız olduğunda ne yapacağını**
da söylemelidir. Geri düşüş sessizse ve pahalıysa, ayara bakan kişi neden
yavaş olduğunu anlayamaz.
