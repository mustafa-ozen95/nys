---
paths:
  - 'tests/**'
  - 'app/**'
---

# Testler

Bu dosya test YAZMA kurallarını değil, bu projede **testin yalan söylediği
yerleri** anlatır.

## Yeşil test, çalışan özellik demek değildir

Tipik biçimleri:

| Ne yeşil kalır | Ne çalışmaz |
| --- | --- |
| Bir düğmenin testi | Düğme hiç çalışmaz — `RequireActionReason` isteği keser |
| Bir ekranın senaryoları | Ekran `all` sunar, senaryo yalnız `access-ports` yazar |
| Yazma testleri | Panel hiçbir cihaza commit atamaz, çünkü test commit dizisini kapsamaz |

Ortak neden: **test, üretimin geçtiği yoldan geçmez.**

- `tests/TestCase.php` bazı ara katmanları devre dışı bırakıyor. Bir ara
  katman davranışı test ediliyorsa o test onu AÇIK bırakmalı.
- Doğrulama komutu `commit check`'te durursa commit dizisini kapsamaz.
  **Kapsanmayan aşama, doğrulanmamış aşamadır.**
- Senaryo ekranın SUNDUĞU her seçeneği denemeli; yalnızca çalışanı denemek
  raporu örtüye çevirir.

## Sahte cihaz GERÇEĞİ modellemeli

`FakeJunosDevice` bir yanıt kuyruğu değil, cihazın davranışının modelidir.
Model gerçekten ayrılırsa test olmayan bir dünyayı doğrular:

- `compare rollback 0` yükleme yapılmadan **BOŞ** döner. Sahte her zaman fark
  döndürürse "adayda başkasının değişikliği var mı" koruması testte hep
  tetiklenir.
- `loadRejects` ile YALNIZCA belirli değerler reddedilebilir: yetenek sondası
  tam olarak bunu ölçer (aynı alanın bir değeri kabul, öteki red).

## Sorgu sayısı kilitleri

`SwitchPortsTest::test_the_screen_does_not_query_per_port` gibi testler N+1'i
yakalar. Sınırı yükseltmek serbesttir ama
**gerekçesi yazılır**: eklenen sorgu ekran başına mı, port başına mı? İkincisi
kabul edilmez.

## Yetki tuzakları

- **Cihaza YAZAN yetenekler NETCONF kimliği ister**
  (`DeviceObjectAbilities::WRITE_ACTIONS`). Kimliksiz switchte port güncelleme
  ucu 403 döner ve test "hata bekliyordum, başarı geldi" diye düşer —
  sebebi yetki değil, kimlik eksikliğidir.
- `visibleTo($user)` kaynak yetkisine bakar. `switches: ['view']` verilen
  kullanıcı BÜTÜN switchleri görür; "göremesin" testi için yetkiyi hiç
  vermemek gerekir.
- Cihaz nesnelerinin (port, VLAN, route) yetkisi switch yetkisinden AYRIDIR;
  `grantDeviceWrite()` onları verir.

## Kuyruk ve iş sınıfları

- **İş sınıfında `$job` adı KULLANILAMAZ.** `InteractsWithQueue`'nun kendi
  `$job` özelliği var; aynı adı kullanmak sınıfı derlenemez hâle getirir ve
  PHPUnit bunu **mesajsız** "Premature end of PHP process" diye gösterir.
  Yığın izi yok, hata metni yok.
- Testte kuyruk `sync`'tir: iş İSTEK İÇİNDE çalışır. Bu iyidir (cihaz yolu
  hâlâ sınanır) ama "kuyruğa alındı, cihaza gitmedi" testi için
  `Queue::fake()` şart.

## Blade bileşeni tuzakları

- Tanımsız bir prop `$attributes`'a düşer ve HTML olarak BASILIR. Dizi
  geçilirse `trim(): array given` diye patlar ve hata bileşenin içinde değil,
  çerçevenin derinliğinde görünür. Prop adları bileşenden doğrulanır.
- Gelişmiş modda çizilen alan (`advanced`) basit modda YOKTUR; o alanı
  sınayan test kullanıcıyı gelişmiş moda almalı.

## Ekran testinde zaman

Saklama UTC, gösterim yereldir. `assertSee('14:47')` yazan bir test yerel
saate göre yazılmalıdır; ikisi karışırsa test yaz saatinde kendiliğinden
kırılır (bkz. `.ai/rules/interface.md`).

## Ölçüm, düzenleme değildir

Arka plan turları `updated_at`'e dokunmaz. Bir teste "kaydı güncelledim"
demek için `saveObservation()` DEĞİL normal `save()` kullanılır; ölçüm yolu
sürüm damgasını bilerek atlar (bkz. `.ai/rules/record.md`).

## Performans ölçerken

İlk istek ısınma maliyetini taşır (autoload, config, görünüm derleme).
Ölçüm ikinci istekten itibaren yapılır: ilk tur sonrakilerden kat kat yavaştır
ve sonucu yanıltır.

## Tarayıcı testleri ekranla birlikte GÜNCELLENİR

Görsel testler çalışan bir siteye (`E2E_BASE_URL`) bağlanır ve ekran değişince
sessizce değil, gürültüyle düşerler — ama yalnızca çalıştırılırlarsa. Koşturulmayan
testler eski dünyayı ölçmeye devam eder: ör. sunucu formu pencereye taşınmış,
kendi kendine parola sıfırlama kaldırılmış, kayıt ekranlarının adı ve adresi
değişmiş olabilir.

Daha ince bir örnek: `form[data-confirm]` içinden İLKİNİ seçen bir test, ekrana
yazılı doğrulaması olmayan başka bir form eklenince ürünü değil kendi sıra
varsayımını ölçer. Seçici, aranan DAVRANIŞI tarif etmeli:
`form[data-confirm-verify]`.

**Ekranı değiştiren her iş, tarayıcı takımını da koşturur.** Yalnızca yeni
eklenen testi koşturmak, kırılanı bir sonraki kişiye bırakır.

## Dışarı çıkan her yol kapatılır, HTTP olmayanlar dahil

`Http::preventStrayRequests()` yalnızca HTTP istemcisini görür. IRR sorgusu
43. porta ham sokettir; `TestCase` bu yüzden `IrrdTransport` bağını sahte bir
taşımayla değiştirir.

Sahte taşıma hazırlanmamış bir prefix sorulduğunda **patlar**, sessizce "kayıt
yok" dönmez: dönseydi hazırlamayı unutan test, engellemenin doğru çalıştığını
sanırdı. Aynı mantık `preventStrayRequests` ile birebir aynıdır.

## Gerekçe ara katmanı düğmeleri sessizce öldürebilir

Kalıp: `RequireActionReason` durum değiştiren bir ucu (ayrıntı düzeyi düğmesi,
commit farkı düğmesi, bildirimleri okundu işaretleme gibi) 422 ile reddeder,
kullanıcı yalnızca "bir şey olmadı" görür. İki yerden gizlenir:

- **PHP testleri göremez**: `TestCase` ara katmanı varsayılan olarak kapatır.
  Muafiyet gerektiren her uç, ara katmanı AÇIK bırakan ayrı bir sınıfta
  kilitlenir (`PanelModeReasonTest`, `NotificationReadReasonTest`).
- **Form taraması `fetch()`'i görmeli**: `ActionReasonCoverageTest` Blade'deki
  POST formlarının yanında `resources/js` içindeki POST fetch'lerini de tarar.

Ayrıca **JS hatayı yutmamalı**: `fetch` 4xx'te throw ETMEZ. Yanıt kontrol
edilmeden `try/catch` ile sarmak, sunucunun reddini sessizliğe çevirir.
`okunduIsaretle()` bu yüzden `yanit.ok`'a bakar ve düşerse kırmızı bildirim
gösterir.

## Yokluk iddiası yarışı kaybeder

Tarayıcı testinde "şu öğe YOK" demek, beklemez: öğe henüz oluşmadığı için
iddia anında geçer. "Tıkla, sonra kırmızı bildirim yok" diyen bir test muafiyet
kaldırılmışken bile GEÇER.

Asenkron bir işin sonucunu ölçen test, önce işi bekler:

```js
const istek = page.waitForResponse((y) => y.url().includes('/bildirimler/okundu'));
await dugme.click();
expect((await istek).status()).toBe(200);
```

Kural: yokluk iddiasından önce beklenecek somut bir olay bulunur.
