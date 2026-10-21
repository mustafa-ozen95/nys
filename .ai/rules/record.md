---
paths:
  - 'app/Services/Activity/**'
  - 'app/Services/ActivityRecorder.php'
  - 'app/Http/Middleware/RecordUserActivity.php'
  - 'app/Models/ActivityLog.php'
  - 'config/activity.php'
---

# Etkinlik kaydı

## Kayıt değiştirilemez olmalıdır

`activity_logs` satırları bir hash zinciridir: her satır kendinden öncekinin
özetini (`previous_hash`) taşır ve kendi özeti (`hash`) bir sonrakinin içine
girer. Bir satır sonradan değiştirilirse ya da silinirse ondan sonraki bütün
özetler tutmaz.

Özet düz SHA DEĞİL, `hash_hmac('sha256', ..., config('activity.chain_key'))`
ile üretilir. Düz özet kullanılsaydı veritabanına yazabilen biri satırı
değiştirip zinciri baştan hesaplayabilirdi. Anahtar öntanımlı olarak
APP_KEY'e düşer; daha güçlü bir kurulumda `ACTIVITY_CHAIN_KEY` uygulama
kullanıcısının okuyamayacağı bir yerden gelmelidir.

Yazma TEK yerden yapılır: `ActivityChain::append()`. `hash` ve
`previous_hash` bilerek `$fillable` DEĞİLDİR — bir controller kendi özetini
uydurabilseydi zincirin anlamı kalmazdı.

Sıra kritiktir: iki kayıt aynı anda yazılırsa ikisi de aynı "önceki"yi okuyup
zinciri çatallandırır. Okuma ve yazma tek Redis kilidinin içindedir.

## Zincirden önceki satırlar

Özeti olmayan satırlar (zincir kurulmadan önce yazılanlar) doğrulanmaz ve
doğrulanmış gibi de GÖSTERİLMEZ; sayıları ayrıca bildirilir. Onları sonradan
özetlemek dürüst olmaz: o satırların değişmediğine dair kanıt yok ve öyleymiş
gibi davranmak zincirin bütün anlamını ortadan kaldırır.

## Budama zinciri kırmaz

`network:prune` eski satırları silerken silinen SON satırın özetini çapa
olarak bırakır (`ActivityChain::anchorTo`). Doğrulama kalan ilk satırın oradan
devam ettiğini görür. Çapa olmadan her budama kaydı "kırık" gösterirdi.

## Doğrulama

`network:log-verify` zinciri baştan yürür, kırılan ilk satırı bildirir ve
sonucu önbelleğe yazar; kayıtlar ekranındaki bütünlük rozeti oradan okunur.
Ekran doğrulamayı kendisi çalıştırmaz — her açılışta yüz bin satırı yeniden
özetlemek olurdu. Komut kırılma bulursa sıfırdan farklı çıkış kodu döner.

Regresyon: `tests/Feature/Activity/ActivityChainTest.php` — değiştirilen satır,
silinen satır ve budama senaryolarının üçü de kilitli.

## Her hareket kaydedilir

`RecordUserActivity` ara katmanı kimliği doğrulanmış HER isteği `access`
kanalına yazar: yöntem, yol, rota adı, durum kodu. Controller'lardaki
ayrıntılı kayıtlar (hangi switche ne yazıldı) yerinde kalır; ara katman
aradaki boşluğu doldurur — denetim sorusu "kim değiştirdi" kadar "kim neye
baktı"dır da.

Yazma `terminate()` aşamasındadır: yanıt gönderildikten sonra çalışır,
sayfayı bekletmez. Maliyeti istek başına sabit iki sorgudur (zincirin ucunu
oku, satır yaz); `NetworkSwitchCrudTest`'teki sorgu bütçesi bunu hesaba katar.

`ajax.remote.guard` ve `ajax.remote.keep-alive` DIŞARIDADIR: ilki nginx'in
auth_request ile her proxy isteğinde sorduğu bekçi ucudur, bir terminal
oturumu boyunca yüzlerce kez çağrılır ve gerçek hareketleri görünmez ederdi.
Oturum açılışı ve kapanışı zaten ayrıca kaydediliyor.

## Gerekçesiz işlem yapılamaz

Durumu değiştiren her istek (POST/PUT/PATCH/DELETE) `reason` alanı ister;
`RequireActionReason` bunu sunucuda arar. Gerekçe kullanıcıdan onay
penceresinde alınır ve forma gizli alan olarak eklenir — pencereyi atlayıp
doğrudan istek göndermek işe yaramaz.

Kimliği belirsiz istekten gerekçe İSTENMEZ. Bu kontrol rota adına
GÜVENMEMELİDİR: giriş POST'unun adı yoktur (adlandırılan yalnızca GET olan
"login"dir) ve yalnızca ada bakan bir liste giriş ekranını kilitler.

Gerekçe istenmeyen uçlar: çıkış, bağlantı sınama, anons önizleme ve uzak
oturum uçları. Terminal her açılışında gerekçe istemek paneli kullanılamaz
hâle getirirdi; uzak oturumların kendi kaydı ayrıca tutuluyor.

Gerekçe hash zincirine girer, yani sonradan değiştirilemez; kayıtlar
ekranında "Gerekçe" sütununda okunur.

**Yeni bir yazma formu eklendiğinde `data-confirm` ZORUNLUDUR.** Onay
penceresi olmadan kullanıcı gerekçeyi giremez ve form sunucuda reddedilir.
Denetim: her `method="POST"` formunun `data-confirm` taşıdığı — auth
formları ve çıkış dışında.

Testlerde kural varsayılan olarak KAPALIDIR (`TestCase::$requiresActionReason`).
Her teste tek tek gerekçe eklemek onları okunmaz yapar ve hiçbirini
daha güvenilir kılmaz; kuralın kendisi `ActionReasonTest` içinde açıkça
açılarak sınanır.

## Cihaz farkındaki sırlar maskelenir

Cihaza yazan her uç, commit'in FARKINI etkinlik kaydına yazar: ne yapıldığını
panelin niyetiyle değil cihazın kabul ettiği değişiklikle anlatmak için
(`RespondsToDeviceWrite`). Fark bir sır taşıyabilir — BGP oturum anahtarı,
community, parola.

Kayıt silinemez ve dışa aktarılabilir; sır oraya HİÇ girmemeli. Bu yüzden fark
kayda geçmeden önce `App\Support\Netconf\ConfigurationRedactor` ile
maskelenir. Maskeleme iki yoldan çalışır:

1. Bilinen Junos anahtar kelimelerinden sonraki değer (`authentication-key`,
   `community`, `secret`, `key`, ...).
2. Nerede geçerse geçsin `$9$...` / `$1$...` biçimindeki Junos değerleri —
   `$9$` çözülebilir olduğu için düz metin kadar hassastır.

Yeni bir sır alanı eklendiğinde listeye de eklenir; `ConfigurationRedactorTest`
bunu kilitler. Maskeleme satırın geri kalanını bozmaz: hangi alanın değiştiği
görünmeye devam eder.

## Zincir İKİ TANEDİR: denetim ve erişim

`activity_logs.chain` satırın hangi zincire ait olduğunu söyler:

- `audit` — oturum açma, CRUD, cihaza yazma. Az satır, yüksek değer.
- `access` — sayfa açma ve form gönderme. Hacmin neredeyse tamamı.

Sebep saklama süresidir. Tek zincirde erişim kayıtları denetim kayıtlarının
ARASINA girer ve zincirin ortasından satır silmek zinciri kırar: kalan ilk
satırın "önceki özeti" ortada kalır. Ayrı zincirde erişim kayıtları
kendi BAŞINDAN budanır, çapası yenilenir, denetim zinciri hiç etkilenmez.

Erişim kayıtları zincir DIŞINDA değildir — kullanıcının her hareketi
kanıtlanabilir; yalnızca budaması bağımsızdır.

Kural: yeni bir kanal eklenirken `ActivityChannel::chain()` de güncellenir.
Çapa anahtarı zincir başınadır; denetim zincirininki eski adıyla kalır ki var
olan kurulumdaki çapa kaybolmasın.

## ÖLÇÜM, DÜZENLEME DEĞİLDİR

Çakışma koruması (`EnsureRecordIsUnchanged`) kaydın `updated_at` damgasını
okuyor: form açıldığındaki damga ile gönderim anındaki damga farklıysa "bu
kaydı biri değiştirdi" der ve işlemi reddeder.

Arka plandaki ölçüm turları o damgayı ilerletirse:

```
switch durumu   30 saniyede bir   (last_sync_at => now())
port ölçümü      5 dakikada bir   (last_polled_at => now())
```

kimse değiştirmediği hâlde kullanıcının açık formu reddedilir; cihaz ayarları
formu sayfa açıldıktan yarım dakika sonra çalışmaz hâle gelir. Hata mesajı "bu
kayıt siz formu açtıktan sonra değişti" der ve doğrudur, ama değiştiren
PANELİN KENDİSİDİR.

**Kural:** gözlem yazan her yol `saveObservation()` kullanır
(`App\Models\Concerns\RecordsObservations`). Gözlemin ne zaman yapıldığı
kendi alanında durur (`last_polled_at`, `last_sync_at`, `clock_checked_at`);
`updated_at` **insan düzenlemesini** gösterir.

Cihazın YAPILANDIRMASINDAN okunan değerler bunun dışındadır: orada kayıt
gerçekten değişmiştir ve açık formun reddedilmesi DOĞRUDUR.
