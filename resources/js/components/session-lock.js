/**
 * EKRAN KİLİDİ — hareketsiz kalan sekme oturumu kapatır.
 *
 * Sunucudaki oturum süre dolunca düşer ama açık sayfa olduğu gibi kalır:
 * panel bütün gece ekranda açık durabilir ve oturumun düştüğü ancak bir yere
 * tıklanınca anlaşılır. Arka planda çalışan bir yoklama da sunucudaki
 * oturumu canlı tutabilir.
 *
 * Süre SUNUCUDAN gelir (<meta name="oturum-suresi">, dakika): panel
 * ayarından değişirse kilit de ona uyar, burada ikinci bir sayı tutulmaz.
 * Etiket yalnızca oturum açıkken basılır; giriş ekranında kilit çalışmaz.
 *
 * Bütün sekmeler TEK saat paylaşır (localStorage): bir sekmede çalışan
 * kişiyi öbür sekme dışarı atmaz. Süre dolunca önce çıkış istenir — oturumu
 * yoklamalar canlı tutmuş olsa bile kapansın — sonra giriş ekranına gidilir.
 *
 * AYNI DOSYA KARDEŞ PANELLERDE DE VAR; ayrışmamalı.
 */

const KEY = 'oturum-kilidi:son-hareket';
const ACTIVITY_EVENTS = ['keydown', 'pointerdown', 'wheel', 'touchstart', 'mousemove'];

/* Denetim aralığı ve yazma seyrekliği (ms). Fare hareketi her piksel yazılmasın. */
const AUDIT = 30000;
const WRITE = 15000;

let installed = false;
let local = Date.now();
let lastWrite = 0;

function lastMove() {
    let value = local;

    try {
        const shared = Number(localStorage.getItem(KEY));
        if (Number.isFinite(shared) && shared > value) {
            value = shared;
        }
    } catch {
        /* Tarayıcı belleği kapalıysa yalnızca bu sekmenin saati sayılır. */
    }

    return value;
}

function move() {
    local = Date.now();

    if (local - lastWrite < WRITE) {
        return;
    }

    lastWrite = local;

    try {
        localStorage.setItem(KEY, String(local));
    } catch {
        /* yok say */
    }
}

async function lock(logoutUrl, loginUrl) {
    const icon = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    try {
        await fetch(logoutUrl, {
            method: 'POST',
            credentials: 'same-origin',
            /*
             * ZAMAN AŞIMI İŞARETİ: bu çıkış kullanıcının değil, saatin kararı.
             * Ortak çıkışı TETİKLEMEZ — bir sekmede süresi dolan kişi öteki
             * panellerden atılmasın (bkz. SharedLogout · zamanAsimiMi).
             */
            headers: { 'X-CSRF-TOKEN': icon, Accept: 'text/html', 'X-Oturum-Zaman-Asimi': '1' },
            redirect: 'manual',
        });
    } catch {
        /* Oturum zaten düşmüş ya da ağ yok: giriş ekranına gitmek yine doğru. */
    }

    window.location.replace(loginUrl);
}

export function init() {
    if (installed) {
        return;
    }

    const minute = Number(document.querySelector('meta[name="oturum-suresi"]')?.content);
    const logout = document.querySelector('meta[name="oturum-cikis"]')?.content;
    const login = document.querySelector('meta[name="oturum-giris"]')?.content;

    if (!Number.isFinite(minute) || minute <= 0 || !logout || !login) {
        return;
    }

    installed = true;
    const limit = minute * 60000;

    move();
    ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, move, { passive: true, capture: true }));

    const check = () => {
        if (Date.now() - lastMove() >= limit) {
            lock(logout, login);
        }
    };

    setInterval(check, AUDIT);

    /* Uykudan dönen bilgisayarda zamanlayıcı gecikir; sekme görününce hemen bak. */
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            check();
        }
    });
}
