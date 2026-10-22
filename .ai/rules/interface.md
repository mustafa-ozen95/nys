---
paths:
  - 'resources/views/**'
  - 'resources/css/**'
  - 'resources/js/**'
---

# Arayüz kuralları

Bu dosya "bir yerde bozuksa her yerde bozuktur" türünden hatalara karşı
kurallardır. Yeni bir ekran ya da satır türü eklerken buradaki kurallar
uygulanır; yenisi icat edilmez.

## Sayfa geçişleri tarayıcıyı yenilemez

Bağlantılar ve GET formları `resources/js/components/navigation.js` tarafından
devralınır: sunucu yine tam sayfa üretir, tarayıcı yalnızca `<body>`'yi yerinde
değiştirir. `<head>` durur, tema ve betikler yeniden yüklenmez.

Bunun bedeli şudur: **bileşenlerin kurulumu her geçişte yeniden koşar.**

- Delegasyon (`lib/dom.js` → `on`) TEKRARA DAYANIKLIDIR: aynı olay + seçici +
  işleyici kaynağı ikinci kez kurulmaz. Olmasaydı tek tık iki kez işlenirdi —
  tema düğmesinde bu "hiçbir şey olmadı" demek olurdu (koyu → açık → koyu).
- Düğüme DOĞRUDAN bağlanan ya da zamanlayıcı kuran bileşen kendini ayrıca
  korur (`bildirim`, `chart`, `keyboard`, `remote-session`). Yeni bir bileşen
  yazarken tercih delegasyondur; `mouseleave` gibi KABARMAYAN olaylarda
  doğrudan bağlanır ve düğüm üstünde işaretlenir.
- `<head>` değişmediği için CSRF simgesi elle tazelenir: oturum yenilenirse
  eski simge DOM'da kalır ve AJAX istekleri 419 alırdı.

Devralınmayanlar: POST formları (onay penceresinden geçiyor), `target` taşıyan
ve `download` bağlantıları, dış köken, `data-tam-yenile` işaretli bağlantılar.
İstek düşerse ya da HTML dönmezse tarayıcının kendi gezinmesine düşülür — en
kötü durumda eski davranış.

Regresyon: `navigation.spec.js`. Ölçüm ÖZNEL DEĞİLDİR: pencereye bir işaret
bırakılır, sayfa gerçekten yenilenirse JavaScript bağlamı sıfırlanıp işaret
kaybolur.

## Bileşen icat etme, olanı kullan

Satırlar `grid-row` + o satır türünün kendi ızgara sınıfıdır
(`switch-row`, `port-row`, `term-row`...). Üç sınıfı üst üste yığmak —
`row row--between bgp-row term-row` gibi — çalışıyormuş gibi görünür ama
çalışmaz: `.row` `display:flex` verir, ızgara şablonu hiç uygulanmaz ve
geriye `space-between` kalır. Sütunlar her satırda kendi metin uzunluğuna
göre kayar.

Aynı işi yapan iki ekran (firewall terimi ve politika terimi) AYNI bileşeni
paylaşır. İkisi için iki düzen yazılmaz.

## Her yatay kayan şerit aynı kurala tabidir

`.topbar__nav`, `.crumbs`, `.tabs`, `.pagination` ve `.columns`:
- kaydırma çubuğu çizilmez (`layout.css`),
- seçili öğe görünür duruma getirilir (`columns.js` → `seciliyeOdaklan`).

Yeni bir kayan şerit eklenirse İKİ listeye de eklenir. Biri unutulursa aynı
arayüzde kimi şeritte çubuk çıkar kimisinde çıkmaz.

Sabit genişlikte bir şerit belgeyi genişletir ve TÜM sayfa yatay kayar.

Şerit DARALABİLMELİDİR: flex öğesi `min-width: 0` almadan içeriğinden dar
olamaz, `flex-shrink: 0` alırsa hiç daralmaz. İkisi birden gerekir —
`min-width: 0` + `overflow-x: auto` şeritte, `flex-shrink: 0` yalnızca
ÖĞELERDE.

Tuzak: şerit (`.topbar__nav`) öğeleriyle (`.topbar__link`) aynı seçici
listesine girerse oradan `flex-shrink: 0` alır. Tablet genişliğinde üst çubuk
satırı ekrandan taşar, üst çubuk sabit olduğu için taşma bütün belgeye geçer ve
**panelin HER sayfası yatay kayar**.

Bir kuralın YAZILI olması uygulandığı anlamına gelmez. Seçici listesine eklenen
her satır, o bloğun gövdesini de o seçiciye verir.

Regresyon: `design.spec.js` → "hiçbir sayfa yatay kaymaz". ÜÇ genişlikte de
çalışır. Genel bir çerçeve ölçüsü tek bir genişliğe hapsedilmez: yalnızca
telefon genişliğinde koşan bir ölçüm tablette çıkan taşmayı görmez.

## Bloklar birbirine yapışmaz

`.panel + .panel` ve `.page__inner > * + *` boşluk alır. Panelin kendi dış
boşluğu yoktur; iki kart yan yana geldiğinde aralarında yalnızca iki çizgi
kalır ve tek bir kutuymuş gibi görünür.

## Tablo hücreleri dar ekranda etiketsiz kalmaz

Dar ekranda tablo bloklara dönüşür ve sütun başlıkları kaybolur; etiket
`data-label` ile hücrenin önüne iner. Etiketsiz bırakılan hücre dar ekranda
adsız bir kutu olarak kalır (yetki ekranındaki "Devral" kutuları gibi).
Etiketler ortak bir
genişlikten başlar, aksi hâlde her satırda kutu farklı yerden başlar.

Regresyon: `mobile.spec.js` → "tablo hücreleri etiketli".

## Satır eylemleri yan yana durur

`.row-actions` flex'tir. Doğrudan çocuk hem düğme hem FORM olabilir (silme
kendi formundadır) ve form blok seviyesindedir; kapsayıcı flex değilse
"Düzenle" üstte, "Sil" altında kalır.

## Alan görünürlüğü seçime bağlıysa

Gizlenen alanın girdileri `disabled` yapılır: aksi hâlde form kullanmadığı
değerleri de gönderir ve sunucu çelişkili bir istek alır (hem envanterdeki
cihaz hem dış cihaz gibi).

`on()` yardımcısının imzası `(olay, seçici, işleyici, kapsam = document)`
şeklindedir — ilk argüman ELEMAN DEĞİLDİR. Yanlış imzayla çağrıldığında
sessizce hiçbir şey bağlanmaz ve ekran "çalışıyor gibi" görünür (ör. dış cihaz
seçilince envanter listesi gizlenmez).

**Görünürlük değişen her form için tarayıcı testi yazılır.** Sessizce
çalışmayan JS'i başka hiçbir şey yakalamaz.

## Grafik serileri etiketlere hizalanır

İzleme sistemi iki seri için farklı sayıda nokta döndürebilir. Değerler
sırayla dizilirse az noktalı seri etiketlerin başına sıkışır ve grafik
"eksik" görünür. Her değer kendi zaman damgasına yerleşir, ölçüm olmayan an
`null` kalır, çizgi boşlukların üzerinden geçer (`spanGaps`).

## Onay penceresi gerekçe ister

Durumu değiştiren her form `data-confirm` taşır; pencere gerekçe alır ve
forma `reason` alanı olarak ekler. Yalnızca okuyan işlemler
`data-confirm-reasonless` ile işaretlenir. Sunucu tarafı da arar
(`RequireActionReason`), yani pencereyi atlamak işe yaramaz. Ayrıntı:
`.ai/rules/record.md`.

## Tekrarlayan işaretleme bileşene alınır

Şu kalıplar bileşendir ve elle yazılmaz:

| Kalıp | Bileşen |
| --- | --- |
| Pencere altlığı (vazgeç + onay) | `x-ui.modal-actions` |
| Silme formu (csrf + DELETE + kırmızı düğme + onay) | `x-ui.delete-form` |
| Arama kutusu (büyüteç + giriş) | `x-ui.search` |
| Bildirim blokları (status / error / errors) | `x-ui.flash` |

Elle yazılan kopyalar zamanla ayrışır. Silme formunda onay özniteliğini
unutmak, silmeyi sorusuz yapmak demektir — bileşen o adımı unutulamaz kılar.

Denetim: `resources/views` içinde `modal__footer`, `@method('DELETE')` ve
büyüteç svg'si yalnızca bileşen dosyalarında geçmelidir. İstisna: düğmesi
başka yerde duran silme formları (`form="..."` ile pencere altlığından
gönderilenler); onlar iç içe form yasağı yüzünden ayrı durur.

## Blade @props tuzağı: null varsayılana düşer

`@props(['submit' => 'Kaydet'])` tanımlı bir bileşene `:submit="null"`
geçmek varsayılanı GEÇERSİZ KILMAZ; Blade `$submit = $submit ?? 'Kaydet'`
derler. "Yok" anlamına gelen sentinel `false` olmalıdır.

Yoksa salt okunur bir pencereye "Kaydet" düğmesi düşer. Regresyon:
`appearance.spec.js` → "salt okunur pencerede kaydet düğmesi olmaz".

## Pencere telefonda kenara dayanmaz

Dar ekranda pencere alttan açılır ama ince bir boşluk bırakır
(`--space-2`), böylece köşelere denk gelmez ve yuvarlatma görünür kalır.
Boşluk bilinçli olarak küçüktür; büyütmek içeriğe yer bırakmaz.

## Yazılması istenen değer olduğu gibi gösterilir

Onay penceresinde "şunu yazın" denen değer `.field__label` içindedir ve o
etiketler `text-transform: uppercase` taşır. Değer de büyütülürse pencere
"TEST1 yazın" der ama karşılaştırma büyük/küçük harfe DUYARLI olduğu için
yazılan "TEST1" kabul edilmez: kullanıcıya yazamayacağı bir şey yazdırılır.

`[data-confirm-expected]` bu yüzden `text-transform: none` taşır. Kural
geneldir: kullanıcıdan birebir yazması istenen hiçbir metin CSS ile
dönüştürülmez.

Regresyon: `appearance.spec.js` → "onay penceresi yazılacak değeri olduğu gibi
gösterir".

## Onay penceresinin bölümleri ayrılır

Mesaj, gerekçe ve yazılı doğrulama çizgiyle ayrılır. Ayrılmazlarsa mesajın son
satırı ile alan etiketi birbirine yapışır ve etiket mesajın devamı gibi okunur.

## Dokunmatik cihazda alan odağı sayfayı yakınlaştırmaz

iOS Safari, yazı boyutu 16 pikselin altında olan bir alana odaklanıldığında
sayfayı kendiliğinden büyütür ve odak kaybolunca GERİ ALMAZ. Çözüm eşiği
geçmektir: dar ekranda ve dokunmatik cihazda form alanları `1rem`.

Viewport'a `user-scalable=no` YAZILMAZ: parmakla büyütmeyi de öldürür ve
iOS 10'dan beri zaten yok sayılır.

Yazı büyürken genişlik de büyümelidir — yoksa filtre çubuğunun `width: auto`
alanları kırpılır ("Tüm kanallar" yerine "Tüm k").

Regresyon: `mobile.spec.js` → "form alanları yakınlaştırma eşiğinin üstünde".

## Çerçeveyi tek öğe taşır

Arama kutusunun görsel çerçevesi (`.filter`) ya formda ya etikettedir, ikisinde
birden DEĞİL. İkisine birden verildiğinde iç içe iki çerçeve çizilir ve ekranda
iki arama kutusu varmış gibi görünür.

Regresyon: `appearance.spec.js` → "arama kutusu tek çerçeve çizer".

## Anonim bileşeni sınıf bileşenine çevirmek derlenmiş görünümleri bozar

Blade, `<x-form.select>` çağrısını DERLEME ANINDA çözer: bileşen anonimken
derlenen çağıran görünüm, sonradan aynı adla bir sınıf bileşeni eklendiğinde
hâlâ anonim yolu kullanır; tersi de olur ve sayfa
"Class App\View\Components\... not found" ile düşer. Çağıran görünüm
değişmediği için kendiliğinden yeniden derlenmez.

Yaygın bir bileşenin türünü değiştirmek zorundaysan `php artisan view:clear`
şarttır ve bu canlıda kısa bir yeniden derleme demektir. Bu yüzden yaygın
bileşenler ANONİM kalır; hesap gerektiren yeni bir bileşen sınıf olarak
EKLENİR (bkz. `x-form.ip-picker`), var olan dönüştürülmez.

## Liste satırı: .term-row sürüklenebilir listeler içindir

`.term-row` ızgarasının İLK sütunu 20 piksellik sürükleme tutamağıdır
(`20px 220px minmax(0,1fr) auto`). Tutamağı olmayan bir listede kullanılırsa
ilk hücre o dar sütuna sıkışır ve yanındaki rozetin altına girer (ör. SNMP
community ve spanning tree listeleri).

Sıralanmayan dört sütunlu listeler `.setting-row` kullanır
(`200px 190px minmax(0,1fr) auto`): ad, durum rozeti, özet, eylem.
Dar ekranda ikisi de tek sütuna iner.

Özet metni Blade'de üç alan birleştirilerek kurulmaz; model bir accessor ile
hazır verir (bkz. `SwitchLoginUser::$summary`).

## Tasarım standart ölçüler üzerinden kurulur

Tasarım standart ölçüler, yerleşimler ve yapılar üzerinden kurulur; bir
sayfada ölçünün, kartın ya da yerleşimin farklı olması kabul edilmez.

**Kural:** ölçü, boşluk, kart yerleşimi ve satır yapısı EKRANA GÖRE
DEĞİŞMEZ. Aynı işi yapan iki şey aynı görünür.

Bunun pratik karşılığı:

- Yeni bir ölçü **icat edilmez**. Boşluk, yazı boyu, köşe yarıçapı ve
  yükseklik `base.css` içindeki değişkenlerden gelir. Bir yere `padding: 13px`
  yazmak, o sayfayı bütün panelden ayırır.
- Kartlar (`.panel`) aynı sütunda **aynı genişlikte** durur. Tek bir ekranda
  "bu kart biraz dar olsun" kabul edilmez.
- Satırlar `grid-row` + kendi ızgara sınıfıdır; sütun şablonu satır türünde
  tanımlanır, ekranda değil. (Bkz. yukarıdaki "Bileşen icat etme" bölümü.)
- Aynı işi yapan iki ekran AYNI bileşeni paylaşır. İkisi için iki düzen
  yazılmaz.
- Düğmeler `btn` + boy sınıfıdır (`btn--sm`, `btn--icon`); serbest yükseklik
  verilmez.

**Denetim:** `tests/Browser/specs/design.spec.js` bunu hem masaüstünde hem
mobilde ölçer — kart genişlikleri, düğme yükseklikleri ve kullanılan boşluk
değerleri sayılır. Yeni bir ekran eklendiğinde bu testler de çalıştırılır;
görsel testler atlanmaz.

## Basit / gelişmiş mod: GİZLEMEK SİLMEK DEĞİLDİR

Panel iki ayrıntı düzeyi taşır (`App\Enums\PanelMode`). Basit modda seyrek
kullanılan alanlar çizilmez.

**Buradaki asıl mesele görünürlük değil, veri kaybıdır.** Bu ekranlarda
birçok alanda "gönderilmedi" cihazdaki satırın SİLİNMESİ demektir:

```
'description' => ... ?: null   boş → <description delete="delete"/>
'speed'       => $speed        boş → port otomatik anlaşmaya döner
'mtu'         => ... : null    boş → MTU satırı silinir
'poe'         => boolean(...)  gelmezse → PoE kapanır
```

Alan yalnızca gizlenseydi, kullanıcının **görmediği** bir ayar her kayıtta
cihazdan silinirdi.

### Kurallar

1. Gelişmiş alan `<x-form.field advanced ...>` ile işaretlenir. Karar tek
   yerdedir: `App\View\Components\Form\Field::shouldRender()`.
2. Gizleme **CSS ile yapılmaz**. Alan DOM'a hiç girmemeli, yoksa form onu
   yine gönderir ve kural bozulur.
3. Ayarları kuran taraf anahtarı **YALNIZCA ALAN GELDİYSE** koyar
   (`$request->has(...)`). Koşulsuz konursa basit mod ayar siler.
4. Fark şudur: gelişmiş modda alan görünür ve BOŞ gönderilir → silme
   bilinçlidir. Basit modda alan hiç gelmez → cihazdaki değere dokunulmaz.

`PanelModeTest` dördünü de kilitler: boş MTU/duplex yalnızca GÖNDERİLDİĞİNDE
silinir.

### Mevcut kullanıcılar

Mod göçü var olan kullanıcıları GELİŞMİŞ modda bırakır. Çalışan bir panelden alanları
habersiz gizlemek, kullanıcının göremediği bir alanı "değiştirmedim"
sanmasına yol açardı; moda geçiş bilinçli olmalı.

## Zaman: saklama UTC, gösterim yerel

UTC gösterilen bir saat, yerel saatle çalışan kullanıcıya olayları olduğundan
saatlerce önce olmuş gibi gösterir.

**Saklama UTC'dir ve öyle KALIR.** `app.timezone` değiştirilmez: geçmiş
kayıtlar yeniden yorumlanırdı ve hash zincirine giren denetim kayıtlarının
anlamı değişirdi — bir saati yazan satır bir anda başka bir saati
gösterirdi. Veriyi düzeltmek için her datetime sütununu dönüştürmek
gerekirdi; o da denetim kaydını elle değiştirmek demektir.

**Gösterim yereldir** (`app.display_timezone`, varsayılan Europe/Istanbul).

Mutlak zaman basan her yer `<x-ui.time :at="..." />` kullanır. Bileşen ham
zamanı `datetime` özniteliğinde taşır ve ipucunda dilimi gösterir. Tek
yerden çevrilir: her ekranın kendi `->format()` çağrısı olsaydı biri
unutulur ve iki farklı saat yan yana görünürdü.

Göreli zaman (`diffForHumans`) dilimden bağımsızdır; çevrilmez.

`DisplayTimeTest` ikisini de kilitler: saklama UTC kalmalı, ekran yerel
göstermeli.

## Katmanlar: üst çubuk içeriğin ÜSTÜNDEDİR

Üst çubuktan açılan bir panel (bildirim) yapışkan tablo başlığıyla aynı
`--z-sticky` değerini kullanırsa sayfa içeriğinin **altında** kalır: eşit
değerde DOM'da sonra gelen kazanır.

Kural iki parçalıdır:

- Açılır katman `--z-dropdown: 40`'tır: `--z-sticky` (20) içerik katmanı,
  `--z-scrim` (60) pencere zemini, açılır katman ikisinin arasında. Yeni bir
  katman değişkeni ancak var olanların gerçekten yetmediği gösterilerek eklenir.
- **Üst çubuk kendi yığılma bağlamını kurar** (`position: relative` +
  `z-index`). İçinden açılan her şey böylece içeriğin üstünde kalır; tek tek
  panellere z-index vermek gerekmez.

### Bu hatayı yakalayan test veriye BAĞLI OLMAMALI

"O noktada hangi öğe var" diye soran bir test **düzeltme olmadan da geçer**:
panelin yüksekliği içeriğine bağlıdır ve bildirimi olmayan bir kullanıcıda
panel içeriğe hiç değmez.

Ölçülen şey bu yüzden KATMANIN KENDİSİDİR: üst çubuk bir yığılma bağlamı
kurmalı ve değeri yapışkan içeriğinkinden büyük olmalı. Böyle yazılan test
düzeltme kaldırıldığında düşer, konulduğunda geçer.

**Genel kural:** bir görsel testin hatayı gerçekten yakaladığı, düzeltme
GERİ ALINARAK kanıtlanır. Yalnızca "geçiyor" görmek yetmez.

## "await"ten sonra olay hedefi kaybolur

Bir bağlantının varsayılan davranışı durdurulup `await` sonrası
`olay.currentTarget.href` okunursa bağlantı **hiçbir yere gitmez**: tarayıcı
işleyici dönünce `currentTarget`'ı sıfırlar.

Adres (ya da gereken her şey) `await`ten ÖNCE alınır.
