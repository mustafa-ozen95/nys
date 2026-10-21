# nys — konteyner kurulumu

Kurulum deponun kökündeki `docker-compose.yml` ile yapılır. Dosya Compose
standardındadır (compose-spec.io): aynı dosya `docker compose` ile de, NineDeploy,
Coolify, Dokploy, Portainer gibi bir panelin "Git deposundan compose" kurulumuyla da
çalışır. Ayarların hepsi ortamdan gelir; liste ve açıklamalar `.env.example`'da.

    cp .env.example .env          # en az APP_URL ve DB_PASSWORD; chmod 600 .env
    docker compose up -d --build

Bir platformda: depoyu "compose" türünde ekleyin (dosya `docker-compose.yml`), ana
servis `panel`, port 80, sağlık yolu `/up`; `.env.example`'daki adları platformun
değişken ekranına girin.

**HTTP portu dışarı açılmaz.** Alan adını ve TLS'i önündeki vekil verir (platformun
kendisi ya da Caddy/nginx); panel isteğin HTTPS olduğunu `X-Forwarded-Proto`'dan bilir.
Vekilsiz deneme: `cp docker-compose.override.example.yml docker-compose.override.yml`
(düz HTTP, port 8080).


## Servisler

| Servis | Ne | Not |
|---|---|---|
| `panel` | nginx + php-fpm + kuyruk işçileri + zamanlayıcı + uzak oturumlar (ttyd, guacd, Tomcat), supervisord altında | yalnız 80 |
| `db` | MariaDB 11.4 | `VT_KOPYA=0` ile kalkmaz (dışarıdaki veritabanı) |
| `redis` | önbellek, oturum, kuyruk | kalıcılık kapalı: yeniden başlatmada oturumlar düşer, veri düşmez |

`AG_YALITIMI=true` konteynerleri switchlerden, Zabbix'ten, kimlik merkezinden (KYM) ve dışarıdan yalıtır
(taşıma ya da deneme kurulumu canlı cihazlara yazmasın diye).

### Panelin içinde

| Program | Ne yapar |
| --- | --- |
| `nginx` | Panel + `/ssh-proxy` + `/rdp-proxy`, `auth_request` dahil |
| `php-fpm` | Panel (unix soketi) |
| `isci-yazma` | `nms-switch-write` — `--tries=1`, `--timeout=300` |
| `isci-genel` | `default` — e-posta ve bildirimler |
| `zamanlayici` | `schedule:work`; eşitleme işçilerini bu açar |
| `restart` | Panelin istediği yeniden başlatma (veritabanı taşıması); root, tek iş |
| `guacd` | Guacamole proxy arka planı, 127.0.0.1:4822 |
| `tomcat` | Guacamole web uygulaması, 127.0.0.1:8090 |

`guacd` ve `tomcat` YALNIZCA `GUACAMOLE_SECRET` verilmişse başlar: RDP
kullanmayan bir kurulumda 200 MB'lık bir JVM boşuna dönmesin.

`isci-genel` olmazsa anons mailleri `default` kuyruğuna alınır ve hiç
işlenmez.

### Uzak oturumlar neden panelin konteynerinde

Uygulama onların localhost'ta olduğunu **varsayıyor**:

- `TtydManager` `proc_open` ile `127.0.0.1:7700-7900` aralığında ttyd süreci
  açar ve `pgrep` ile izler,
- nginx `auth_request` ile yine localhost'taki php-fpm'e sorar,
- Tomcat (Guacamole) 127.0.0.1:8090'da, nginx'in `/rdp-proxy/`'si oraya bakar.

Bu portlar dışarı açılmaz; hepsine nginx'in 80'i üzerinden, panelin bekçisinden
geçerek gelinir. Ayrı servislere bölmek `proxy_pass` hedeflerini, ttyd'nin süreç
izlemesini ve sürüm bağlamasını (guacd ↔ guacamole ↔ json-auth eklentisi birbirine
uymak zorunda) yeniden düşünmeyi gerektirir; karşılığında kazanç yok. Yatay ölçekleme
de istenmiyor: cihaza yazma işleri switch başına zaten `WithoutOverlapping` ile sıraya
sokulmuş.


## Hacimler

Adlar `ONEK` ile başlar (varsayılan `nys`); aynı sunucuda ikinci kurulum onu değiştirir.

| Hacim | İçerik |
|---|---|
| `nys_storage` | `storage/app`: APP_KEY (verilmediyse), ilk parola dosyası, Ayarlar → Veritabanı'nın bağlantı dosyası (`database/`) |
| `nys_database` | db servisinin veri dizini |
| `nys_logs` | `storage/logs` |


## ⚠ APP_KEY'i yedekleyin

Switch parolaları, SNMP community'leri ve sunucu kimlikleri veritabanında bu
anahtarla şifrelenir (sekiz modelde `encrypted` cast). Anahtar kaybolursa
veritabanı duruyor olsa bile **hiçbiri okunamaz ve geri getirilemez**.

Boş bırakılırsa ilk açılışta üretilir ve `storage/app/app-key.txt` içinde (0600)
saklanır; değerin kendisi günlüğe **yazılmaz** (günlüğü okuyabilen, onu arayüzde
gösteren platformlar dâhil, anahtarı da okurdu), günlükte yalnız dosyanın yeri durur.
Okuyup ortama yazın:

    docker compose exec panel cat /uygulama/storage/app/app-key.txt

Hacimde saklandığı için yeniden başlatmada
korunur. Saklanmasaydı her açılış farklı bir anahtar üretirdi — aynı veritabanına iki
farklı anahtar, yani sessiz veri kaybı.

Yedeklenmesi gerekenler: `veritabani` hacmi (ya da dışarıdaki veritabanının dökümü),
`storage` hacmi ve `.env`.


## Veritabanı

Panel veritabanını ortamdan alır (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`; ad ve kullanıcı varsayılanı `nsm`). Varsayılan compose'taki `db` servisi;
parolası `DB_PASSWORD`, ilk açılışta kullanıcı ve veritabanı bununla kurulur. Açılışta
panel veritabanını en çok üç dakika bekler, gelmezse açılmaz (konteyner yeniden dener).

Dışarıdaki ya da platformun yönettiği bir MariaDB/MySQL: `DB_*`'ı ona çevirin ve
`VT_KOPYA=0` deyin.

Oturumun saat dilimi konteynerin saatidir (`DB_TIMEZONE`, varsayılan `+03:00`): UTC'de
çalışan bir sunucuda TIMESTAMP sütunları 3 saat kaymış okunmasın.

### Panelden taşıma (Ayarlar → Veritabanı)

Yalnız yönetici. Panel hedefi sınar: bağlantı, sürüm, hedefin boş ve şu anki
veritabanından farklı olması, göçlerin ihtiyaç duyduğu yetkiler (tablo, dizin, yabancı
anahtar, değişiklik, yazma, silme). Sınama geçerse **Taşı** konteyneri yeniden başlatır.
Açılışta panel kapalıyken şu anki veritabanı hedefe kopyalanır ve her tablonun satır
sayısı iki tarafta karşılaştırılır. Bir adım tutmazsa panel şu anki veritabanıyla açılır
ve sebep sekmede görünür.

Panelden tanımlanan bağlantı `storage/app/database/connection.json` dosyasında APP_KEY ile
şifreli durur ve ortamdaki `DB_*` değerlerinin yerine geçer. `state.json` taşımanın
aşamasını tutar ve sır içermez. Yeniden başlatmayı konteyner içindeki `dc-restart`
yapar; bunun için yeniden başlatma politikası **`unless-stopped`** olmalı (compose'ta öyle).

Taşıma bittikten sonra eski veritabanına **kendiliğinden dönülmez**; taşıma anındaki
hâliyle durur. Bilerek dönmek için: uzak veritabanının dökümünü eskisine yükleyin,
sonra `storage/app/database` altındaki `connection.json` ve `state.json`'u silip
konteyneri yeniden başlatın. Dökümü yüklemezseniz taşımadan sonraki her şey kaybolur.

APP_KEY değişirse bağlantı dosyası çözülemez ve panel açılmaz.


## İlk açılışta ne olur

Kullanıcı tablosu boşsa başlatıcı bir yönetici açar (`ADMIN_EMAIL`, boşsa
`admin@nys.local`). `ADMIN_PASSWORD` boşsa parola **rastgele üretilir** ve
`storage/app/initial-password.txt` içine (0600; 2. satır) yazılır. Parolanın kendisi günlüğe
yazılmaz; günlükte yalnız dosyanın yeri durur. Sabit bir varsayılan parola konulmaz:
imajı kuran herkeste aynı giriş bulunurdu.

    docker compose exec panel cat /uygulama/storage/app/initial-password.txt

Giriş yapıp parolayı değiştirdikten sonra o dosyayı silin. Hesabı sonradan açmak için
(parola sorulur, komut satırına yazılmaz):

    docker compose exec panel php artisan ag:yonetici-ekle

### Laboratuvar envanteri yazılmaz

Başlatıcı `db:seed`'i tümüyle çağırmaz; yalnızca `AdminUserSeeder` ve yalnızca
kullanıcı tablosu boşken. `NetworkSeeder` **bilerek** çalıştırılmaz — yeni bir
kuruluma test cihazlarını yazmak yanlış olurdu.


## Durdurma — varsayılan süre YETMEZ

    docker compose down            # süreler compose'da tanımlı (panel 1 sa, db 2 dk)

Kapanış **ne kadar sürüyorsa o kadar** beklenmelidir; verilen sayı bir hedef
değil, asla devreye girmemesi gereken bir tavandır. Konteyner işi bitince
hemen çıkar.

`docker stop` varsayılan olarak **10 saniye** bekler ve süre dolunca SIGKILL
gönderir (çıkış kodu 137). Veritabanının nazik kapanışı InnoDB boşaltması
yüzünden boştayken bile **bu süreyi aşabilir**. Cihaza yazan bir iş sürerken panelin kapanışı dakikalara
çıkabilir; kesilmiş bir yazma, panelin bilmediği bir cihaz durumu demektir.

Komut çıktı vermeden bekliyorsa sorun yok — `docker stop` adı ancak iş bitince
basar.


## Ağ

Cihazlara (NETCONF 830, SNMP 161, SSH 22), Zabbix'e, SMTP'ye, RIPEstat'a (443)
ve RADB'ye (43) giden bağlantıların **hepsi dışa doğrudur**. Compose'un NAT'lı
köprü ağı yeter; `network_mode: host` ya da macvlan gerekmez. Konteynerin
yönetim ağına L3 erişimi olmalı.

`AG_YALITIMI=true` iken bu bağlantıların hiçbiri kurulamaz: deneme ve taşıma
kurulumu içindir, gerçek kurulumda kapalı kalır.


## Önde Caddy

Platform değil de düz `docker compose` ile kuruluyorsa önde TLS bitiren bir vekil
gerekir. Bu panelin Caddy parçası `docker/proxy.caddy`'dedir (SSH/RDP WebSocket'leri
için okuma/yazma süresi bir saat). Vekil konteyneri panelin ağında olmalı:

    docker network connect <proje>_default dc-vekil

Panel X-Forwarded-* başlıklarına güvenir (`bootstrap/app.php` → `trustProxies`,
varsayılan özel ağlar; `TRUSTED_PROXIES` ile daraltılır).


## Sertifika sekmesi

Ayarlar → Sertifika, sunucudaki sertifika ajanının durumunu gösterir ve ona tur isteği
bırakır (`/etc/dc/sertifika-durum.json`, `/etc/dc/sertifika-panel`). Standart kurulumda
TLS'i vekil yönettiği için bu dosyalar bağlı değildir; sekme "bilinmiyor" der ve
düğmeler çalışmaz.


## Güncelleme

    git pull && docker compose up -d --build

Bir platformda: yeniden dağıtın (platform depoyu çekip derler). Göçler her açılışta
`--isolated` ile koşar; iki örnek aynı anda kalksa bile bir kez çalışır.

GitHub Actions her main'de imajı derleyip `ghcr.io/mustafa-ozen95/nys` altına da
yükler (`.github/workflows/image.yml`): hazır imaj isteyen kurulum `docker/Dockerfile`'ı
derlemek yerine onu kullanabilir.

### Güncelleme kontrolü

Ayarlar → Güncelleme (yalnız yönetici) çalışan sürümü GitHub'da main dalının **son başarılı
imaj derlemesiyle** karşılaştırır: kaç commit geride, hangi commit'ler yeni. Yön de ölçülür;
çalışan sürüm son derlemeden yeniyse "güncelleme" denmez. Kontrol her sabah 06:50'de kendiliğinden
yapılır (`guncelleme:denetle`). Yeni sürüm varsa yöneticiler panelin üstünde uyarı görür.
Ekran **yalnızca bakar**.

- Çalışan sürüm imaja derlemede yazılır (`ARG SURUM` → `UYGULAMA_SURUMU`; iş akışı commit'i verir).
- Depo açık: jeton gerekmez, GitHub'a jetonsuz sorulur (sınır IP başına saatte 60 istek). Depo
  özelse `GUNCELLEME_JETONU` salt okunur bir jeton olmalı (fine-grained, yalnız panel depoları,
  Contents ve Actions: read). Erişilemezse kontrol "hata" der, panelin başka yeri etkilenmez.
- Çalışan sürüm derlemede imaja yazılır (`ARG SURUM`, iş akışı commit'i verir). Derlemeye commit
  vermeyen platformlarda (NineDeploy gibi) bağlamdaki `.git`'ten okunur (`docker/version-read.sh`;
  `.dockerignore` yalnız `HEAD` ve ref'leri alır).
- Panel GitHub'a çıkamıyorsa (kapalı ağ) kontrol "erişilemedi" der.


## Derleme tuzakları

- **`php8.5-opcache` diye bir paket yok.** 8.3 ve öncesinde ayrıydı, 8.5'te
  `php-common` içinde geliyor. Alışkanlıkla eklenirse derleme
  "Unable to locate package" ile düşer.
- **İmajda `.env` yoktur**, ayarlar ortamdan okunur. `DB_CONNECTION`
  verilmezse Laravel SQLite'a düşer ve göçler
  "Database file at path [nsm] does not exist" ile patlar; başlatıcı `mysql` verir.
- **`migrate --isolated` kilidi önbelleğe yazar**, önbellek de Redis. Başlatıcı
  göç komutunu dosya önbelleğiyle koşar: redis ayrı servis ve açılışta henüz
  hazır olmayabilir.
- **`HTTP_HOST` portu Debian'ın `fastcgi_params`'ında düşüyor.** Dosya
  bilinçli bir güvenlik önlemi olarak `fastcgi_param HTTP_HOST $host;` yazıyor
  ve `$host` istemcinin verdiği PORTU KORUMAZ. Sonuç: standart olmayan bir
  portta çalışan panel giriş sonrası `http://192.0.2.50/giris` adresine
  yönlendirir, port kaybolduğu için sayfa açılmaz. nginx 1.30 bunun için
  `$is_request_port$request_port` getirdi; 1.22'de yok, aynısını `map` ile
  elle kuruyoruz — konak nginx'in normalleştirdiği `$host`tan, port yalnızca
  RAKAM olarak Host başlığından alınır, ham konak adı asla geçmez.
- **`http2 on;` YAZILMAZ.** O sözdizimi nginx 1.25.1 ile geldi; imajdaki
  bookworm 1.22 taşıyor ve "unknown directive http2" ile başlamayı reddediyor.
- **guacd 1.5.5 imajı Alpine/musl tabanlıdır.** İkilileri glibc tabanlı bu
  imaja kopyalanamaz; guacd kaynaktan derlenir (`guacd-derleme` aşaması,
  ~7 dakika). Java tarafı taşınabilir olduğu için `guacamole.war` ve
  `guacamole-auth-json-1.5.5.jar` resmi imajdan doğrudan kopyalanır.
- **WAR derleme sırasında AÇILIR.** Tomcat `nsm` olarak çalışıyor, kurulum
  dizini ise root'un; çalışma anında ne WAR'ı açabilir ne de
  `conf/Catalina/localhost`'u yaratabilir ("Unable to create directory for
  deployment", `/guacamole` için 404). Yalnızca yazması gereken dizinler
  devredilir, `bin` ve `lib` root'ta kalır.
- **Tomcat 8090 dinler**, varsayılan 8080 değil: `config/remote.php` ve
  nginx'teki `/rdp-proxy/` oraya bakıyor. Varsayılan bırakılsaydı aynı sayı
  üç ayrı yerde değiştirilecekti.

Guacamole'un kimlik doğrulaması **json-auth**'tur: panel imzalayıp şifrelediği
JSON'u POST eder, Guacamole güvenir. Bu yüzden Guacamole'un kendi veritabanı
yoktur — tek gereken paylaşılan sır (`GUACAMOLE_SECRET`).


## Sorun giderme

    docker compose logs -f panel
    docker compose exec panel supervisorctl status
    docker compose exec panel php artisan tinker
