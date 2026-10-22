# Cihaz erişim parolaları

Panelin switchlere bağlandığı hesabın parolası panelden değiştirilir. Her
cihaza AYRI ve rastgele bir parola verilir; tek bir parolanın bütün sahayı
açması diye bir durum kalmaz.

Ekran: `/settings/passwords`. Yalnızca yönetici — bu ekran panelin bütün
cihazlara erişimini tek tuşla değiştirir ve yanlış giderse saha yönetilemez
hâle gelir. "Cihaza yaz" yetkisi bunu KAPSAMAZ.

## Sıra güvenliğin kendisidir

`SwitchPasswordRotator::rotate()` adım adım yürür ve her adım kayda geçer:

1. Cihazda tam yetkili bir **kurtarma hesabı** açılır.
2. O hesapla gerçekten bağlanılabildiği **kanıtlanır**.
3. Panel hesabının parolası **kurtarma hesabıyla** değiştirilir.
4. Yeni parola **denenir** (yeni oturum).
5. Panelin kimlik kaydı **ancak bundan sonra** güncellenir.
6. Kurtarma hesabı **yeni parolayla** silinir.

### Parolayı kendi oturumunla değiştiremezsin

Geri alınabilir commit'in onayı YENİ bir oturumda verilir (bkz.
`ConfigurationWriter`). Parolasını değiştirdiğin hesapla bağlandıysan o
oturum artık eski parolayı kabul etmez: onay verilemez, cihaz commit'i geri
alır ve tur hiçbir şey yapmadan biter. Bu yüzden 3. adım kurtarma
hesabıyla atılır — parolası değişmediği için hem commit hem onay sorunsuz
geçer.

`ConfigurationWriter::applyOn()` tam bunun için var: kayıtlı kimlikle değil,
VERİLEN kimlikle bağlanır.

### Yeni parola commit'ten ÖNCE kayda yazılır

Yazma ile doğrulama arasında süreç ölürse parola yalnızca bellekte kalmış
olurdu ve cihaz, parolasını bilmediğimiz bir cihaza dönerdi. Bu yüzden
`switch_password_rotations.new_password` commit'ten önce doldurulur ve tur
başarıyla bitince silinir.

### Takılırsa kurtarma hesabı BIRAKILIR

Tek istisna: kurtarma hesabı açıldığı hâlde onunla bağlanılamıyorsa hesap
silinir. Çalışmayan kurtarma hesabı güvence değil, açıkta duran fazladan bir
yetkidir ve panel hesabı hâlâ geçerli olduğu için temizlenebilir.

Diğer her durumda hesap cihazda kalır, parolası panelde şifreli durur ve
ekranda uyarı olarak görünür. Kapatan `repair()`; hangi kapının açık
olduğunu bilmediği için üçünü sırayla dener: kayıtlı parola, turun
`new_password`'ü, kurtarma hesabı. Üçü de kapalıysa cihaza konsoldan
girilmesi gerekir ve ekran bunu söyler.

Temizlenmemiş kurtarma hesabı varken **yeni tur başlamaz**: yenisi eskisinin
parolasını ezer ve cihaza dönüş kapısı kaybolurdu.

## Parola CİHAZDAN OKUNMAZ

Cihazda parolanın yalnızca ÖZETİ var (`$1$...`, geri çevrilemez). Eşitleme
onu okuyup kimlik kaydının üstüne yazsaydı ekranda okunamayan bir değer
görünür ve panel bir daha bağlanamazdı.

`DeviceSettingsSynchronizer` cihazın `<authentication>` düğümünden yalnızca
`has_password` boolean'ını alır; `switch_credentials.password`'a HİÇ dokunmaz.
Bu, testle kilitlidir (`ManualPasswordTest::test_esitleme_paneldeki_parolayi_ezmez`).

Paneldeki parola yalnızca iki yoldan yazılır ve ikisi de düz metin bırakır:
turun ürettiği rastgele parola, ya da elle verilen parola.

## Elle verilen parola

Rastgele üretilen 24 karakter göze özet gibi göründüğü için kullanıcının
kendi parolasını verebilmesi gerekir. `rotate()` isteğe bağlı bir parola
alır; verilmezse üretir.

Elle verilen parola rastgele turla **birebir aynı** güvenli sıradan geçer
(kurtarma hesabı → doğrula → değiştir → dene → kaydet → temizle). Elle
seçilmiş olması onu daha az tehlikeli yapmaz. Kuyruğa alınır: üç commit ve
dört oturum sürüyor.

**"Yalnızca panele kaydet" diye bir işlem YOKTUR.** Panelin bildiği ile
cihazdaki ayrı ayrı düzenlenebilseydi iki doğruluk kaynağı olur ve
hangisinin geçerli olduğu ancak bağlanmaya çalışınca anlaşılırdı. Panelin
kaydı, panelin cihaza YAZDIĞI şeydir.

Parola panele iki yoldan girer, ikisi de kullanıcının bildiği bir değerdir:

1. Switch envantere eklenirken formda yazılan parola (cihaz zaten o parolayı
   taşıyor; panel bağlanabilmek için onu bilmek zorunda).
2. Parola turunun ürettiği ya da elle verilen parola.

Elle verilen parola da aynı sınırlara tabidir (en az 8 karakter, rakam ya da
noktalama, yoksa büyük+küçük harf birlikte) — cihazın kabul etmeyeceği bir
parola panelden hiç çıkmaz.

## Cihazın sınırları (jn32-test, 12.3R12.4)

| Deneme | Cihazın cevabı |
| --- | --- |
| 28 ve 128 karakterlik harf+rakam parola | KABUL |
| var olan hesabın parolası, `class` yazmadan | KABUL |
| YENİ hesap, `class` yazmadan | `Missing mandatory statement: 'class'` |
| yanlış parolayla oturum | `NETCONF kimlik doğrulaması başarısız` |

## Üretilen parolanın biçimi AYARLANIR

Uzunluk ve karakter sınıfları `Ayarlar > Cihaz parolası` ekranından seçilir
(`config/credentials.php` → `rotation.*`). Seçim veritabanına yazılır,
config'in üstüne biner ve sonraki her tur onu kullanır.

Üretilen parola **açık olan her sınıftan** en az bir karakter taşır. Taşımasa
"rakam açık" demek "rakam olabilir" olurdu ve parolanın cihaz kuralını geçip
geçmeyeceği şansa kalırdı.

### Cihazın kuralı: EN AZ İKİ karakter sınıfı

jn32-test'te doğrulandı. Junos **tek karakter sınıfından**
oluşan her parolayı reddeder — yalnız küçük, yalnız büyük, **yalnız rakam**
ve **yalnız noktalama** dahil; hepsi aynı hatayı verir:
`require change of case, digits or punctuation`. İki sınıfın her birleşimi
kabul edilir.

Kural tek bir örnekten genellenmez: "yalnız küçük harf reddedilir"den
"büyük+küçük ya da rakam ya da noktalama yeter" sonucu çıkmaz; yalnız-rakam
parola da reddedilir.

### Entropi tabanı: 64 bit

Cihaz iki sınıfla sekiz karakteri kabul eder ama `23456789` gibi bir dizi
24 bittir. Junos parolayı MD5-crypt (`$1$`) olarak tutuyor ve **yapılandırma
yedeği her commit'te cihazdan çıkıyor** (bkz. `ArchivalWriter`), yani özet
çevrimdışı denenebilir. Uzunluk bu yüzden kullanıcının kararına bırakılmaz:
az sınıf seçilirse gereken uzunluk artar (küçük+rakam için 13, üç sınıf için
11 karakter).

### İkisi de üreteçte uygulanır

`PasswordGenerator::problemWith()` hem ayar doğrulamasında (kullanıcı
kaydetmeden uyarılsın) hem de üreteçte (config elle ya da `.env` üzerinden de
bozulabilir) çalışır. Son söz üreteçtedir.

Geçersiz birleşim üreteci `InvalidArgumentException` ile durdurur ve bu
`guard()` içinde, yani **kurtarma hesabı açılmadan ÖNCE** olur — cihazda iz
bırakmaz.

### Ayar doğrulaması İSTEĞİ değil, YÜRÜRLÜĞE GİRECEK durumu sınar

Gönderilmeyen alan için mevcut ayar kullanılır. Aksi hâlde tek alanlık bir
PUT kontrolü tamamen atlar ve kaydedilmiş değerlerle birleşince cihazın
reddedeceği bir ayar kalıcı olarak yazılabilirdi; sonrasında her parola turu
düşerdi.

### Elle verilen parola kuyrukta ŞİFRELİ taşınır

`RotateSwitchPasswordJob implements ShouldBeEncrypted`. Kuyruk sürücüsü
`database`; şifrelenmeseydi parola `jobs` tablosunda düz metin durur ve iş
çerçeve seviyesinde düşerse `failed_jobs` satırında kalıcı olurdu.

### Varsayılanlar ve gerekçeleri

- **Noktalama kapalı.** Kabuk, beklenti dosyası ve URL geçişlerinde
  kaçırılmak zorunda ve saha araçlarında sessizce bozuluyor. Açılırsa havuz
  yalnızca kabukta sorun çıkarmayanları taşır (tırnak, ters bölü, `$`,
  backtick, boşluk havuzda YOK).
- **Karışan karakterler eleniyor** (`0/O`, `1/l/I`): parola arıza anında
  konsoldan elle de yazılıyor.
- **En az 8 karakter.** Cihaz altısını kabul ediyor; panel bir tık yukarıda
  durur.

## Aynı hesabın bütün kimlik satırları güncellenir

Cihazdaki hesap TEKTİR; `switch_credentials`'taki SSH ve NETCONF satırları
onun iki yüzüdür. Biri güncellenip diğeri kalırsa uzak oturum (ttyd,
Guacamole) açılamaz olur.

## Parola kayda yazılmaz — ekranda ise AYRI BİR YETKİYLE gösterilir

Ne etkinlik kaydına, ne commit açıklamasına. Cihazın döndürdüğü farktaki
`encrypted-password` `ConfigurationRedactor` ile maskelenir; aynı maskeleme
geri alma ve config yükleme ÖNİZLEMELERİNE de uygulanır — Junos'un `$9$`
biçimi geri çevrilebilir ve düz metin kadar hassastır.

Panelin sakladığı cihaz parolası ise `secret` yetkisi olana **düz metin
gösterilir** (bkz. `.ai/rules/permission-api.md`). Bu bilinçli bir istisnadır:
sahada konsoldan bağlanmak gerektiğinde parola bir yerden okunmak zorunda ve
o yer, kimin ne zaman okuduğunu kaydeden bir yer olmalı.

Üretilen parola rastgele harf+rakam olduğu için ÖZET GİBİ GÖRÜNÜR ama düz
metindir; ekranlardaki metinler bunu açıkça söyler (aksi hâlde "parolam
hashlenmiş" diye okunuyor).
`switch_password_rotations` tablosundaki iki sır (`recovery_password`,
`new_password`) `encrypted` cast'lidir, `$hidden` içindedir ve tur bitince
silinir.

## İş tekrar DENENMEZ

`RotateSwitchPasswordJob::$tries = 1`. Yarıda kalmış bir tur körlemesine
yeniden başlatılırsa cihazda duran kurtarma hesabının parolası ezilir.
Takılan tur ekranda durur; kapatmayı kullanıcı başlatır.
