<?php

declare(strict_types=1);

namespace App\Services\Kimlik;

use App\Enums\ActivityChannel;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\ActivityRecorder;

/**
 * Merkezdeki değişikliği YEREL hesaba taşır.
 *
 * NEDEN VAR: merkezde birinin rolü değişince, hesabı askıya alınınca ya da
 * silinince buradaki kopya eskir. O kişi merkeze giremediği hâlde burada
 * erişmeye devam ederdi — oturumu zaten açıktır ve kimse ona "artık olmaz"
 * demez. GİRİŞTE BAKMAK YETMEZ: değişiklik, kişi hiç giriş yapmadan da
 * olur.
 *
 * TEK YÖN: merkez söyler, burası uygular. Buradan merkeze hiçbir şey
 * yazılmaz; yazılsaydı iki kaynak birbirini ezerdi.
 *
 * HESAP SİLİNMEZ, KAPATILIR. Merkezde silinen kimliğin buradaki karşılığı
 * pasife alınır: kimin ne yaptığı denetim kaydında duruyor ve sahibi silinmiş
 * satırlar o kaydı okunmaz yapardı.
 *
 * YÖNETİCİLİK BURADA DA DEVROLMAZ (bkz. HesapAcici): merkezde rol dağıtabilen
 * biri, buradaki hiçbir yöneticinin haberi olmadan kendini yönetici
 * yapamamalı. Yönetici rolü yalnızca `kimlik.yonetici_devri` açıkken geçer.
 */
class KimlikEsitleyici
{
    public function __construct(
        private MerkezIstemcisi $merkez,
        private ActivityRecorder $activity,
    ) {}

    /**
     * Merkeze bağlı bütün hesapları eşitler.
     *
     * @return array{sorulan: int, kapatilan: int, acilan: int, rol_degisen: int}
     */
    public function esitle(): array
    {
        $baglar = UserIdentity::with('user.role')
            ->where('provider', HesapEslestirici::SAGLAYICI)
            ->get()
            ->filter(fn (UserIdentity $bag): bool => $bag->user !== null);

        $sayac = ['sorulan' => $baglar->count(), 'kapatilan' => 0, 'acilan' => 0, 'rol_degisen' => 0];

        if ($baglar->isEmpty()) {
            return $sayac;
        }

        $durumlar = $this->merkez->kimlikDurumlari($baglar->pluck('subject')->all());

        foreach ($baglar as $bag) {
            /*
             * YANIT GELMEYEN KİMLİĞE DOKUNULMAZ.
             *
             * Merkez o kimliği yanıtta hiç anmadıysa elimizde bilgi yok
             * demektir; "bilmiyorum"u "yok" saymak, merkezdeki bir aksaklıkta
             * bütün kadroyu panelden atmak olurdu.
             */
            if (! isset($durumlar[$bag->subject])) {
                continue;
            }

            $sonuc = $this->uygula($bag->user, (array) $durumlar[$bag->subject]);

            $sayac['kapatilan'] += (int) $sonuc['kapatildi'];
            $sayac['acilan'] += (int) $sonuc['acildi'];
            $sayac['rol_degisen'] += (int) $sonuc['rol_degisti'];
        }

        return $sayac;
    }

    /**
     * Tek bir hesabı merkezdeki durumuna getirir.
     *
     * MERKEZ HER ZAMAN EZER — iki yönlü.
     *
     * Yalnızca kapatmak yetmez: merkezde geri açılan hesap burada kapalı
     * kalırsa kimsenin açıklayamadığı bir ayrışma doğar — merkezde "etkin"
     * yazan hesap panelde pasif durur ve hangisinin doğru olduğu bilinmez.
     *
     * Bu yüzden durum da rol de merkezdekine getirilir. Panelde yapılan yerel
     * değişiklik bir sonraki eşitlemede geri alınır; tek doğruluk kaynağı
     * merkezdir ve ekran bunu yazar.
     *
     * @param  array{durum?: string, girebilir?: bool, rol?: ?string}  $durum
     * @return array{kapatildi: bool, acildi: bool, rol_degisti: bool}
     */
    public function uygula(User $kullanici, array $durum): array
    {
        $girebilir = (bool) ($durum['girebilir'] ?? false);

        $kapatildi = false;
        $acildi = false;

        if (! $girebilir && $kullanici->isActive()) {
            $kapatildi = $this->kapat($kullanici, (string) ($durum['durum'] ?? 'bilinmiyor'));
        } elseif ($girebilir && ! $kullanici->isActive()) {
            $acildi = $this->ac($kullanici);
        }

        /*
         * ROL HER DURUMDA eşitlenir. Kapalı hesabın rolünü atlamak, hesap
         * merkezden geri açıldığında eski rolle açılmasına yol açardı.
         */
        $rolDegisti = $this->roluGuncelle($kullanici, $durum['rol'] ?? null);

        /* Ortak çıkış: bildirim kaybolduysa an buradan gelir (bkz. OrtakCikis). */
        if (is_int($durum['son_cikis'] ?? null) && ($bag = OrtakCikis::bag($kullanici)) !== null) {
            OrtakCikis::isle($bag, $durum['son_cikis']);
        }

        return ['kapatildi' => $kapatildi, 'acildi' => $acildi, 'rol_degisti' => $rolDegisti];
    }

    /**
     * Merkezde erişimi geri gelen burada da açılır.
     *
     * Merkezin "etkin" demesi yetiyor: panelin yerel durumu merkezinkini
     * ezemez (bkz. uygula). Yerel bir acil kapatma isteniyorsa merkezden
     * yapılmalı — orası her yere işler.
     */
    private function ac(User $kullanici): bool
    {
        $kullanici->forceFill(['status' => UserStatus::Active])->save();

        $this->activity->recordAs(
            $kullanici->id, null, request()->ip(), $kullanici, 'kimlik_erisimi_geldi',
            "Merkezde erişimi geri geldi, hesap açıldı: {$kullanici->email}",
            ActivityChannel::Auth,
        );

        return true;
    }

    /**
     * Merkezde giremeyen burada da giremez.
     *
     * Oturumu açıksa da kapanır: EnsureUserIsActive her istekte durumu
     * sorar ve etkin olmayan hesabı dışarı atar.
     */
    private function kapat(User $kullanici, string $merkezDurumu): bool
    {
        $kullanici->forceFill(['status' => UserStatus::Inactive])->save();

        $this->activity->recordAs(
            $kullanici->id, null, request()->ip(), $kullanici, 'kimlik_erisimi_kalkti',
            "Merkezde erişimi kalktı, hesap kapatıldı: {$kullanici->email} (merkezdeki durum: {$merkezDurumu})",
            ActivityChannel::Auth,
        );

        return true;
    }

    /**
     * Merkezdeki rol değiştiyse buradaki de değişir — YETKİLERİYLE BİRLİKTE.
     *
     * Rol, yetkinin adıdır: yalnızca adı değiştirip yetkileri eski rolde
     * bırakmak "rol verdim ama hiçbir şey göremiyor" durumunu üretirdi.
     * Şablon burada da uygulanır; ekrandan rol değiştirmekle aynı sonuç.
     *
     * Bunun bedeli, o kişiye ESKİ rolünün üstüne elle verilmiş istisnaların
     * şablonda geçen kaynaklarda yeniden yazılmasıdır. Bilinçli: istisna
     * eski rolün üstüne verilmişti ve rol değişince onunla gitmesi doğru.
     */
    private function roluGuncelle(User $kullanici, ?string $slug): bool
    {
        $yeni = $this->rol($slug);

        if ($yeni === null || $kullanici->role_id === $yeni->id) {
            return false;
        }

        $eski = $kullanici->role?->label ?? 'rolsüz';

        $kullanici->forceFill(['role_id' => $yeni->id])->save();

        $this->activity->recordAs(
            $kullanici->id, null, request()->ip(), $kullanici, 'kimlik_rolu_degisti',
            "Merkezde rolü değişti: {$kullanici->email} · {$eski} → {$yeni->label}"
                .' · yeni rolün yetkileri geçerli',
            ActivityChannel::Auth,
        );

        return true;
    }

    /**
     * Merkezdeki slug'ın buradaki karşılığı.
     *
     * Karşılığı yoksa DOKUNULMAZ (null döner): tanınmayan bir rolü varsayılana
     * çevirmek, kimsenin istemediği bir anda birinin yetkisini daraltmak ya da
     * genişletmek olurdu. Yöneticilik yalnızca açıkça devrediliyorsa geçer.
     */
    private function rol(?string $slug): ?Role
    {
        if (blank($slug)) {
            return null;
        }

        $rol = Role::query()->where('slug', $slug)->first();

        if ($rol === null || ($rol->is_admin && ! config('kimlik.yonetici_devri'))) {
            return null;
        }

        return $rol;
    }
}
