<?php

declare(strict_types=1);

use App\Enums\SyncInterval;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Zamanlanmış eşitlemeler
|--------------------------------------------------------------------------
| Sıklıklar ayar ekranından yönetilir (config/sync.php varsayılanları tutar).
| Bu dosya her schedule:run çağrısında yeniden okunduğu için ayardaki
| değişiklik bir dakika içinde yürürlüğe girer; sürecin yeniden başlatılması
| gerekmez.
|
| Kaynaklar ayrıdır ve sıklıkları bilinçli olarak farklıdır: ölçüm sık ve
| ucuz, envanter seyrek ve pahalıdır. İşler ShouldBeUnique olduğu için üst
| üste binmez, withoutOverlapping ise komutun kendisini korur.
*/

/**
 * Bir komutu ayarda seçilen sıklıkla zamanlar. "Kapalı" seçiliyse komut hiç
 * zamanlanmaz.
 */
$plan = function (string $command, string $setting): void {
    $interval = SyncInterval::tryFrom((string) config("sync.{$setting}")) ?? SyncInterval::Off;

    if ($interval === SyncInterval::Off) {
        return;
    }

    $event = Schedule::command($command);

    // Saniyelik sıklığın cron karşılığı yoktur; çerçevenin kendi yardımcısı
    // kullanılır.
    $interval === SyncInterval::ThirtySeconds
        ? $event->everyThirtySeconds()
        : $event->cron((string) $interval->cron());

    $event->withoutOverlapping()->runInBackground();
};

/*
 * Ölçüm: çevrimiçilik, CPU, bellek, sıcaklık, uptime.
 *
 * Kaynağı ayarlarda seçilir; izleme sistemi kapalıysa ya da erişilemiyorsa
 * cihazın kendisine sorulur. Bu yüzden Zabbix'in durumuna bakılarak
 * zamanlanmaz — iş her hâlükârda anlamlıdır.
 */
$plan('network:sync-status', 'status');

// Port ölçümleri: oper durumu, hız, dupleks, anlık trafik.
$plan('network:sync-ports', 'ports');

/*
 * Cihazın kendi yapılandırması: portlar, VLAN'lar, IP adresleri, route'lar,
 * prefix list'ler, firewall filtreleri, politikalar, BGP ve toplama
 * arayüzleri — hepsi NETCONF'tan.
 *
 * Değişim algısı açıkken her tur tek RPC'ye iner: son commit değişmemişse
 * envanter hiç okunmaz.
 */
$plan('network:sync-netconf', 'netconf');

// Süresi dolmuş etkinlik kayıtları ve trafik örnekleri.
$plan('network:prune', 'maintenance');

/*
 * Etkinlik kaydı zincirinin bozulmadığının doğrulanması.
 *
 * Budamayla aynı sıklıkta çalışır ve sonucu önbelleğe bırakır; kayıtlar
 * ekranındaki bütünlük rozeti oradan okunur. Doğrulamayı ekrana bağlamak,
 * her açılışta bütün kaydı yeniden özetlemek demek olurdu.
 */
$plan('network:log-verify', 'maintenance');

/*
 * SNMP yedek okumaları: IP adresleri ve NETCONF kimliği olmayan cihazlarda
 * VLAN ile commit geçmişi. SNMP kapatılmışsa hiç çalıştırılmaz.
 */
if (config('snmp.enabled')) {
    $plan('network:sync-snmp', 'snmp');
}

/*
|--------------------------------------------------------------------------
| Kuyruk işçileri
|--------------------------------------------------------------------------
| İşçiler bir işletim sistemi servisi (supervisor, systemd) tarafından değil
| ZAMANLAYICI tarafından başlatılır. Böylece kurulumun tek işletim sistemi
| bağımlılığı, zamanlayıcıyı çağıran o tek cron satırıdır; panel başka bir
| makineye taşındığında yanına servis tanımı taşınmaz.
|
| Her işçi 55 saniye yaşar (--max-time) ve bir sonraki dakika tikinden önce
| çekilir; zamanlayıcı her dakika temiz bir süreç başlatır, böylece bellek
| sızıntısı birikmez.
|
| --stop-when-empty KULLANILMAZ. Kullanıldığında işçi kuyruk o anda boş diye
| hemen çıkıyor, saniyeler sonra kuyruğa giren iş ise bir sonraki dakika
| tikine kadar bekliyordu: otuz saniyede bir çalışan ölçüm işi fiilen dakikada
| bire düşmüştü. İşçi dakikanın neredeyse tamamı boyunca ayakta kalır ve işi
| geldiği anda alır; boştayken saniyede bir Redis'e bakar, maliyeti yok
| denecek kadardır.
|
| Geriye dakika başına ~5 saniyelik bir boşluk kalır (55–60). O aralıkta
| kuyruğa giren iş birkaç saniye bekler. Cihaza YAZMA işleri bu kuyrukta
| değildir: ayrı bir kuyrukta (nms-switch-write) ve sürekli çalışan kendi
| systemd işçisinde koşarlar — kullanıcı ekranda beklediği için o yolda
| dakikalık bir boşluk kabul edilemez (bkz. deploy/README.md).
|
| İş zamanaşımı (--timeout) NETCONF zamanaşımından BÜYÜK olmak zorundadır:
| tek bir RPC config('netconf.timeout') kadar bekleyebilir ve bir eşitleme işi
| onlarca RPC çalıştırır. Küçük olursa cihaz sadece yavaşladığında iş
| ortasından kesilir, yeniden denenir ve aynı yerde yine kesilir. Değer
| config('sync.job_timeout')'tan gelir ve alt sınırı NETCONF zamanaşımı + 30
| saniyedir; ikisi ayrışamaz.
|
| --max-time buna engel değildir: o yalnızca YENİ iş almayı durdurur, çalışan
| iş bitene kadar sürer.
|
| withoutOverlapping kilidinin adı KOMUT METNİNDEN türetilir, ->name() ile
| verilen açıklamadan değil. Bu yüzden her işçiye --name ile ayrı bir ad
| verilir; verilmezse beşi de aynı kilidi paylaşır ve yalnızca biri çalışır
| (ölçüldü: beş tanım, tek süreç). Kilit iki dakikada düşer, süreç
| beklenmedik şekilde ölürse işçi yeri sonsuza kadar boş kalmasın.
*/
foreach (range(1, max(1, (int) config('sync.workers'))) as $order) {
    Schedule::command(sprintf(
        'queue:work %s --queue=%s --name=worker-%d --sleep=1 --max-time=55 --tries=2 --timeout=%d',
        config('queue.default'),
        config('zabbix.queue'),
        $order,
        max((int) config('sync.job_timeout'), (int) config('netconf.timeout') + 30),
    ))
        ->everyMinute()
        ->withoutOverlapping(2)
        ->runInBackground();
}

/*
|--------------------------------------------------------------------------
| Kimlik Yönetim Merkezi eşitlemesi
|--------------------------------------------------------------------------
| Merkezde rolü değişen ya da hesabı askıya alınan kişi burada da erişimini
| kaybetmeli. Panelde gezinen kişi için bunu ara katman zaten yapıyor
| (bkz. VerifyIdentityStatus); bu tur, panele hiç uğramayan hesapları da
| kapatır — biri ayrılınca hesabının açık kalması, kimsenin fark etmediği bir
| kapı olurdu.
|
| Merkez kapalıysa komut kendisi çıkar; burada ayrıca koşul yazmak, ayar
| değiştiğinde ikisinin ayrışmasına yol açardı.
*/
Schedule::command('identity:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

/*
 * Düşen uzak oturumlar.
 *
 * Tarayıcı kapanışı haber veremediğinde (çökme, uyku, kopan ağ) kapanış
 * kaydı bu tur sayesinde yazılır. Dakikada bir koşar; iş yoksa tek sorgu.
 */
Schedule::command('console:prune')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * YENİ SÜRÜM VAR MI — HER SABAH.
 *
 * Yalnızca bakar ve sonucu yazar; yeni sürüm varsa yönetici panelin üstünde
 * uyarı görür. Güncellemenin kendisi sunucudan yapılır. Ayarlar →
 * Güncelleme'deki düğme aynı kontrolü hemen yapar.
 */
Schedule::command('update:check')->dailyAt('06:50');
