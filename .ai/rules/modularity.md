---
paths:
  - '**'
---

# Modülerlik

Her şey kolaylıkla eklenip çıkarılabilmelidir — yeni özellik, yeni Junos
sürümü, yeni tasarım.

Bu bir slogan değil, **ölçülebilir** bir kısıt: bir şeyi eklemek kaç dosyaya
dokunmayı gerektiriyor? Cevap büyüyorsa yapı bozulmuş demektir.

## Kural

**Bir eksende değişiklik, o eksenin dışına yayılmamalı.**

Yeni bir Junos ailesi eklemek servislere; yeni bir alan eklemek her ekrana;
yeni bir ölçü eklemek her CSS dosyasına dokunmayı gerektirmemeli.

Bunun yolu tektir: **servis şekli SORAR, cevabı tek bir yer verir.** Servisin
içine "eğer ELS ise" yazmak, o soruyu kodun her yerine dağıtmaktır.

## Genişleme noktaları

| Ne ekleniyor | Dokunulan yer | Kilit |
| --- | --- | --- |
| Junos ailesi (sürüm/model) | 1 lehçe sınıfı + `config/junos.php`'de 1 satır + enum'da 1 case | `JunosDialectTest` |
| Bir alanın basit/gelişmiş modu | Alanın kendisinde 1 öznitelik (`advanced`) | `PanelModeTest` |
| Yetki kaynağı | `config/permissions.php`'de 1 giriş | `PermissionMatrixTest` |
| Tasarım ölçüsü | `base.css` değişkeni — yenisi İCAT EDİLMEZ | `design.spec.js` |
| Cihaz doğrulama senaryosu | `DeviceWriteScenarios`'ta 1 kapanış | `network:verify-writes` |
| Cihaz yeteneği (değer kümesi) | 1 `DeviceCapability` sınıfı + `config/capabilities.php`'de 1 satır | `DeviceCapabilitiesTest` |
| Cihaza yazan yeni bir uç | `queueWrite(...)` çağrısı — iş sınıfı geneldir | `DeviceWriteQueueTest` |
| Anons yetki kaynağı (ROA, IRR, …) | 1 `PrefixAuthority` sınıfı + `config/announcements.php`'de 1 satır | `AnnouncementPermissionTest` |
| Anons onay ölçütü (kaç kaynak yeter) | `announcements.authorization.policy` — 1 değer | `AnnouncementPermissionTest` |
| "Listeden seç ya da elle gir" alanı | `x-form.picker` çağrısı — mekanizma ortaktır | `appearance.spec.js` |
| Örnek konfigürasyon yapısı (regresyon) | `sample-corpus*.set`'e 1 satır + üretimi çalıştır | `SampleConfigurationTest` |

Lehçe için varsayılanlar ELS öncesi davranıştır: yeni bir aile yalnızca
AYRILDIĞI yeri yazar. `JunosDialectTest::test_varsayilanlar_els_oncesidir`
bunu kilitler — varsayılanlar kayarsa test düşer.

## Henüz tek dokunuş OLMAYAN yer

Dürüst olmak gerekirse **yeni bir cihaz nesnesi türü** (VLAN, port, firewall
gibi) hâlâ ~10 dosya ister: model, göç, okuyucu, yazıcı, eşitleyici, istek,
denetleyici, ekran, test, cihaz senaryosu. Bunun için iskele yok.

Bu bilinçli bir borç, gizlenmiş bir eksik değil: her nesnenin okuma/yazma
biçimi cihazda ölçülerek bulunuyor ve otomatik üretilecek bir kalıbı yok.
Yeni nesne eklenirken bu liste kontrol listesi olarak kullanılır.

## Bir şey eklerken sorulacak sorular

1. Bu değişiklik kaç dosyaya dokunuyor? Beklenenden fazlaysa yanlış yerden
   ekleniyor demektir.
2. Aynı soruyu ikinci bir yerde cevaplıyor muyum? Cevap ikileniyorsa biri
   er ya da geç çürür (bkz. `.ai/rules/permission.md` — config'te sütun adı çürümesi).
3. Eklediğim şeyi ÇIKARMAK kaç dosyaya dokunmayı gerektirir? Eklemesi kolay
   çıkarması imkânsız olan şey modüler değildir.
4. Genişleme noktasının kilidi var mı? Yoksa bir sonraki kişi eski yoldan
   ekler ve yapı sessizce dağılır.

## Çıkarmak da kolay olmalı

Örnek: bir arayüz öğesi (kırıntı çubuğu gibi) kaldırılırken işaretleme, layout
prop'u, görünümlerdeki aktarım, CSS kuralları ve JS odak listesi birlikte gider.
Geriye "ayarlıyorum ama görünmüyor" diyecek ölü bir bağlantı bırakılmaz.
