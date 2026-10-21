# Roller, ayar yetkileri ve API

## Rol bir KAYITTIR

`UserRole` enum'u kaldırıldı; roller `roles` tablosundadır ve panelden
açılır (`/ayarlar/roller`). `users.role_id` yabancı anahtardır, `$user->role`
bir `Role` modelidir.

- `is_admin` bütün yetki kontrollerini geçer. **En az bir yönetici rolü
  kalmak zorunda**; sonuncusunun yetkisini almak doğrulamada durur, çünkü
  geri almak için de yönetici gerekir.
- `is_system` roller (`admin`, `network`, `teknik`) silinemez ve slug'ı
  değişmez: seeder, test ve şablon dosyaları onlara adıyla atıfta bulunuyor.
- `is_default` yeni kullanıcının rolüdür ve TEKTİR. Yönetici rolü varsayılan
  OLAMAZ — "kullanıcı ekle" yetkisi, yönetici üretme yetkisi değildir.

`isAdmin()` tek karar noktasıdır ve `role?->is_admin` okur. Rol `User`
modelinde eager yüklenir (`$with = ['role']`): her yetki kontrolünün ilk
sorusu odur, satır başına sorgu atmamalı.

### Rolü değiştirmek kullanıcıların yetkisini DEĞİŞTİRMEZ

Şablon kullanıcıya yalnızca açık istekle uygulanır (yetki ekranındaki "Rol
şablonunu uygula"). Kendiliğinden uygulansaydı bir role dokunmak, o roldeki
herkesin elle ayarlanmış istisnalarını sessizce silerdi.

Şablon veriden geldiği için tanımsız kaynak/aksiyon satırları okunurken
atılır (`RoleTemplate::clean`): bir kaynak config'ten kaldırıldığında eski
şablon satırı kayıtta kalabilir.

## Ayar yetkisi BÖLÜM bazındadır

Ayarların tamamı tek bir anahtar değildir: ölçüm kaynağını değiştirmek ile
geri alınabilir commit süresini değiştirmek aynı ağırlıkta değil.

`settings` bir yetki kaynağıdır ve **kayıtları veritabanından gelmez**:
bölümler config'te tanımlı ve kimlikleri `config/settings.php` içindeki
`permission_id` sayılarıdır. Bu sayı ASLA değişmez — kullanıcı izinleri ona
bağlı saklanıyor. Yeni bölüme sıradaki sayı verilir, silinen bölümünki
yeniden kullanılmaz.

Kayıt listesini `PermissionRecords` arayüzü verir (`SettingGroupRecords`);
`PermissionMatrixService` kaynak tanımında `records` varsa modeli hiç
sorgulamaz.

İki sınır birden geçerlidir:

1. Bölümü görme/yazma yetkisi (`SettingsAccess`).
2. İstek gövdesi o bölümün alanlarıyla SINIRLIDIR (`SettingsRequest::section`).
   Sınır olmasaydı "bakım" formuna izleme sistemi jetonu iliştirilebilir ve
   bölüm bazlı yetki hiçbir şey ifade etmezdi.

Varsayılan kapalıdır: hiçbir rol şablonunda `settings` yoktur.

## Cihaz parolası: "secret" ve "rotate"

`switches` kaynağına iki aksiyon eklendi ve ikisi de **varsayılan olarak
kapalıdır** (hiçbir rol şablonunda yok, yalnızca yönetici geçer):

- `secret` — parolayı EKRANDA görme.
- `rotate` — parolayı değiştirme.

Yetki switch başınadır: "şu switchin parolasını görsün, diğerlerininkini
görmesin" böyle söylenir.

Panelin genel kuralı parolayı hiçbir ekranda basmamaktır; burası bilinçli bir
istisnadır ve üç şeyle dengelenir:

- Parola sayfa kaynağında HİÇ bulunmaz; göz simgesine basıldığında ayrı bir
  istekle gelir (`POST switchler/{switch}/parola`).
- Her gösterim kayda geçer (kim, ne zaman, hangi switch) ve kayıt silinemez.
- Yanıt önbelleğe alınmaz; gösterilen değer yarım dakika sonra kendiliğinden
  gizlenir.

Uç POST'tur ama bir şeyi DEĞİŞTİRMEZ: POST olmasının tek sebebi sırrın adres
çubuğuna, tarayıcı geçmişine ve ön yüklemeye takılmamasıdır. Bu yüzden
`RequireActionReason` listesinde muaftır — gerekçe penceresi tek tıkla
görmeyi imkânsız kılardı ve denetim izini uç zaten kendisi yazıyor.

## API

`/api/v1/*`, kimliğini YALNIZCA `Authorization: Bearer ...` jetonundan alır.
Oturum çerezi kabul edilmez: tarayıcıdan gelen bir isteğin çerezle API'ye
geçmesi, CSRF korumasının dışına çıkmak olurdu.

### Jeton sahibini AŞAMAZ

Yetki, jetonun kendi listesi ile SAHİBİNİN panel yetkilerinin kesişimidir.
`TokenAbilityGate` bir `Gate::before` kancasıdır ve **yönetici geçişinden
ÖNCE** çalışır. Sıra yanlış olsaydı yöneticiye ait bir jeton bütün
kontrolleri geçerdi.

Yeteneği kaynağa çeviremediği her durumda jeton REDDEDİLİR: "ne olduğunu
bilmiyorum" ile "izinli" aynı şey değildir.

### Jetonun kendisi saklanmaz

Yalnızca `sha256` özeti tutulur. Sır bir kez gösterilir; kaybolursa geri
gösterilemez, yenisi üretilir (`rotate`) ve eskisi anında geçersiz olur.
İptal (`revoke`) silmez: kimin hangi jetonla ne yaptığı kayıtta durur.

Sahibi sonradan DEĞİŞTİRİLEMEZ — aynı sır başka birinin yetkilerine açılan
bir kapı olurdu.

### IP sınırı ve hız sınırı

`allowed_ips` tek adres ya da CIDR taşır; boş bırakılırsa her yerden kabul
edilir ve ekran bunu uyarı olarak söyler. Hız sınırı JETON başınadır, IP
başına değil: aynı IP'den gelen iki jeton birbirini yavaşlatmamalı.

### Testte guard önbelleği

`RequestGuard` çözdüğü kullanıcıyı önbelleğe alır ve testte uygulama örneği
istekler arasında paylaşılır. Aynı testte ikinci bir API isteği atarken
`$this->app['auth']->forgetGuards()` çağrılmazsa jeton yeniden doğrulanmaz
(iptal/rotate testleri sessizce geçerdi). Üretimde her istek kendi
uygulamasını kurar.
