---
paths:
  - 'config/permissions.php'
  - 'app/Services/Authorization/**'
  - 'app/Policies/**'
---

# Yetkilendirme

## "Switchi okur-yazar" diye tek bir anahtar yoktur

Cihazın her nesnesi KENDİ yetki kaynağıdır ve kendi
görüntüle/oluştur/düzenle/sil yetkilerini taşır:

`switch_ports`, `switch_aggregation`, `switch_vlans`, `switch_ip_addresses`,
`switch_routes`, `switch_prefix_lists`, `switch_firewall`, `switch_policies`,
`switch_bgp`.

Bu kaynakların KAYITLARI switchlerdir. Böylece "A switchinin portlarına
dokunsun ama VLAN'larına dokunmasın, B switchine hiç girmesin" denebilir.
Nesnenin kendi satırları (tek tek portlar) yetki taşımaz — yüzlerce satırlık
bir matris kimsenin yönetemeyeceği bir şey olurdu.

`switches` kaynağındaki `configure` YALNIZCA cihazın kendi ayarlarıdır:
SNMP adı ve yapılandırma yedeği. Port ya da VLAN yazmayı KAPSAMAZ.

## Yetenek adları config'ten üretilir

`DeviceObjectAbilities` her kaynak-aksiyon çifti için bir ad türetir:
`switch_vlans` + `update` → `updateSwitchVlans`. Controller, Blade ve
gezinme aynı listeyi okur. Elle yazılsalardı bir ekranda `updateVlans`,
diğerinde `updateSwitchVlans` olur ve biri sessizce hiç kontrol edilmezdi.

Yazma yetenekleri NETCONF kimliği ister ve `Gate::before` tarafından BYPASS
EDİLMEZ: yazma yolu olmayan switchte yönetici de yazamaz, düğme de
gösterilmez.

## Görsel ve sunucu önlemi birlikte durur

Gezinme sütunu, kullanıcının göremediği nesneyi LİSTELEMEZ
(`SwitchNavigationService`); ekranın kendisi de yetkisiz isteği 403 ile
reddeder. Yalnızca biri yeterli değildir: menüyü gizlemek adres çubuğuna
yazmayı engellemez, yalnızca sunucuda durdurmak ise kullanıcıya
tıklayamayacağı bağlantılar gösterir.

Regresyon: `tests/Feature/Security/DeviceObjectPermissionTest.php`.

## Roller şablondur, yetki değil

Üç rol vardır: **admin**, **network** (genel yetkili), **teknik**
(kısıtlanmış). Yönetici bütün kontrolleri `Gate::before` ile geçer; diğer
ikisinin `config/permissions.php` içinde hazır şablonu vardır.

Şablon KENDİLİĞİNDEN uygulanmaz. Yeni kullanıcıda bir kez uygulanır,
sonrasında yalnızca yetki ekranındaki düğmeyle. Rol değişince sessizce
yeniden yazılsaydı, elle ayarlanmış kişiye özel istisnalar kaybolurdu ve
bunu kimse fark etmezdi.

Şablon yalnızca İÇİNDE GEÇEN kaynaklara dokunur; geçmeyen bir kaynakta elle
verilmiş yetki korunur.

**teknik** şablonu bilinçlidir: her şeyi görür, bağlanır, cihazda YALNIZCA
porta müdahale eder. Saha işi budur — kablo takılan portu açmak, açıklamasını
düzeltmek. VLAN, route, firewall ve BGP okunur ama yazılmaz.

## Firewall kendi kaynağıdır

Firewall yetkisi `switch_firewall` kaynağıdır ve switch bazında verilir —
kime verildiği görünür ve tek tek seçilir. Hiçbir şey onu ima etmez; "cihaza
yaz" (`configure`) yetkisi firewall'a geçmez. Kime verileceği bilinçli bir
karardır.

## Ekranın listesi süzülüyorsa GEÇMİŞİ de süzülmeli

Config yükleme ekranında cihaz listesi `Gate::allows('provision')` ile
süzülüp altındaki geçmiş tablosu süzülmeseydi, tek bir switche yükleme yetkisi
olan kullanıcı bütün cihazlara kimin ne zaman yazdığını — envanterde olmayan
cihazlar dahil — görebilirdi.

Kural: bir ekranda kayıt listeleyen HER sorgu `visibleTo($user)` ile daralır.
"Ekranı görebiliyor" yetmez; hangi kayıtları göreceği ayrıca sorulur.
Envanterde karşılığı olmayan kayıt (dış cihaz) yalnızca yöneticiye görünür,
çünkü o işlemi yalnızca yönetici yapabilir.

## Config'teki sütun adları çürüyebilir

`config/permissions.php` sütun adlarını DİZGE olarak taşır (`order`, `search`).
Bir sütun göçle yeniden adlandırıldığında config sessizce çürür: kod derlenir,
testler geçer, ama o kaynağın yetki ekranı üretimde 500 verir. Örnek:
`address_start` → `range_start` gibi bir yeniden adlandırma, config'te eski ad
kalırsa "addressing" kaynağını kırar.

**Sorguyu çalıştırmak yakalamaz.** SQLite çift tırnaklı bilinmeyen bir
tanımlayıcıyı sütun değil STRING SABİTİ sayar; Eloquent `order by
"address_start"` ürettiği için sorgu test veritabanında sessizce geçer, aynı
sorgu MySQL'de "Unknown column" ile düşer. Bu, test takımının sütun çürümesine
YAPISAL olarak kör olduğu anlamına gelir.

Kilit bu yüzden şemaya bakar: `PermissionMatrixTest::
test_yetki_yapilandirmasindaki_sutunlar_gercekten_var` her kaynağın `order` ve
`search` sütunlarını `Schema::hasColumn` ile doğrular ve her sürücüde çalışır.

Config'e sütun adı yazan başka bir yer eklenirse aynı kontrole dahil edilir.

## Gerekçe ara katmanı düğmeleri sessizce öldürebilir

`RequireActionReason`, oturum açmış kullanıcının durum değiştiren HER
isteğinden gerekçe ister. Gerekçe toplamayan bir form yazılırsa istek
doğrulama hatasıyla geri döner ve kullanıcı yalnızca **"bir şey olmadı"**
görür — hata sayfası bile çıkmaz.

**Testler bunu kendiliğinden yakalamaz**, çünkü `TestCase` bu ara katmanı
varsayılan olarak KAPATIR (`$requiresActionReason = false`).

Durum değiştiren bir uç eklerken:

1. Ekran gerekçe topluyor mu? (`name="reason"`, onay penceresi, silme formu)
2. Toplamıyorsa uç gerçekten bir kaydı değiştiriyor mu? Değiştirmiyorsa
   (yalnızca okuyan POST/PUT uçları, kullanıcının kendi görünüm tercihi)
   `RequireActionReason::IGNORED` içine girer.
3. Kilit `PanelModeReasonTest` gibi `$requiresActionReason = true` olan bir
   sınıfta kurulur; ara katman kapalıyken yazılan test ucun çalıştığını
   SÖYLEMEZ.

`ActionReasonCoverageTest` bunu topluca denetler: her POST formu ya gerekçe
toplar ya da rotası muafiyet listesinde geçer. Üçüncü ihtimal yoktur.

## Kendi kendine parola sıfırlama ve kayıt YOKTUR

Bu bir ağ yönetim panelidir: hesabı **yönetici** açar, parolayı **yönetici**
verir. Şifre sıfırlama ve kayıt akışı yoktur; parolayı yalnız yönetici
değiştirir, üyeyi yalnız yönetici oluşturur.

Gerekçe teknik: sıfırlama akışı **e-posta kutusunu hesabın anahtarı** hâline
getirir. Posta kutusuna erişen kişi, switchlere yazma yetkisi olan bir hesabı
ele geçirir — kimlik doğrulamanın gücü, panelin değil posta sunucusunun
gücüne iner.

Olmayanlar: `password.request`, `password.email`, `password.reset`,
`password.update` rotaları; onların denetleyicileri, istekleri, ekranları ve
giriş sayfasındaki "parolamı unuttum" bağlantısı.

**Oturumsuz erişilebilen tek uç giriştir.** `NoSelfResetTest`
bunu kilitler: bu rota adları tanımlı olmamalı, `guest` ara katmanı
yalnızca `giris` üzerinde olmalı ve giriş ekranında ölü bağlantı kalmamalı.

Parolayı yönetici kullanıcı ekranından değiştirir (`UserRequest`); boş
bırakılan parola alanı mevcut parolayı korur. Kimse kilitli kalmaz.

`password_reset_tokens` tablosu Laravel'in varsayılanı olarak duruyor ve
kullanılmıyor; akış geri getirilecek olursa bu kuralın da geri alınması
gerekir.
