<?php

declare(strict_types=1);

namespace App\Services\Identity;

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
 * YÖNETİCİLİK BURADA DA DEVROLMAZ (bkz. AccountOpener): merkezde rol dağıtabilen
 * biri, buradaki hiçbir yöneticinin haberi olmadan kendini yönetici
 * yapamamalı. Yönetici rolü yalnızca `identity.yonetici_devri` açıkken geçer.
 */
class IdentitySyncer
{
    public function __construct(
        private CenterClient $center,
        private ActivityRecorder $activity,
    ) {}

    /**
     * Merkeze bağlı bütün hesapları eşitler.
     *
     * @return array{sorulan: int, kapatilan: int, acilan: int, rol_degisen: int}
     */
    public function sync(): array
    {
        $binds = UserIdentity::with('user.role')
            ->where('provider', AccountMatcher::PROVIDER)
            ->get()
            ->filter(fn (UserIdentity $bag): bool => $bag->user !== null);

        $counter = ['sorulan' => $binds->count(), 'kapatilan' => 0, 'acilan' => 0, 'rol_degisen' => 0];

        if ($binds->isEmpty()) {
            return $counter;
        }

        $statuses = $this->center->identityStatuses($binds->pluck('subject')->all());

        foreach ($binds as $bag) {
            /*
             * YANIT GELMEYEN KİMLİĞE DOKUNULMAZ.
             *
             * Merkez o kimliği yanıtta hiç anmadıysa elimizde bilgi yok
             * demektir; "bilmiyorum"u "yok" saymak, merkezdeki bir aksaklıkta
             * bütün kadroyu panelden atmak olurdu.
             */
            if (! isset($statuses[$bag->subject])) {
                continue;
            }

            $result = $this->apply($bag->user, (array) $statuses[$bag->subject]);

            $counter['kapatilan'] += (int) $result['kapatildi'];
            $counter['acilan'] += (int) $result['acildi'];
            $counter['rol_degisen'] += (int) $result['rol_degisti'];
        }

        return $counter;
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
     * @param  array{status?: string, can_enter?: bool, role?: ?string, last_logout?: ?int}  $durum
     * @return array{kapatildi: bool, acildi: bool, rol_degisti: bool}
     */
    public function apply(User $user, array $status): array
    {
        $canEnter = (bool) ($status['can_enter'] ?? false);

        $closed = false;
        $opened = false;

        if (! $canEnter && $user->isActive()) {
            $closed = $this->close($user, (string) ($status['status'] ?? 'bilinmiyor'));
        } elseif ($canEnter && ! $user->isActive()) {
            $opened = $this->open($user);
        }

        /*
         * ROL HER DURUMDA eşitlenir. Kapalı hesabın rolünü atlamak, hesap
         * merkezden geri açıldığında eski rolle açılmasına yol açardı.
         */
        $roleChanged = $this->roleUpdate($user, $status['role'] ?? null);

        /* Ortak çıkış: bildirim kaybolduysa an buradan gelir (bkz. SharedLogout). */
        if (is_int($status['last_logout'] ?? null) && ($bag = SharedLogout::bag($user)) !== null) {
            SharedLogout::isle($bag, $status['last_logout']);
        }

        return ['kapatildi' => $closed, 'acildi' => $opened, 'rol_degisti' => $roleChanged];
    }

    /**
     * Merkezde erişimi geri gelen burada da açılır.
     *
     * Merkezin "etkin" demesi yetiyor: panelin yerel durumu merkezinkini
     * ezemez (bkz. uygula). Yerel bir acil kapatma isteniyorsa merkezden
     * yapılmalı — orası her yere işler.
     */
    private function open(User $user): bool
    {
        $user->forceFill(['status' => UserStatus::Active])->save();

        $this->activity->recordAs(
            $user->id, null, request()->ip(), $user, 'kimlik_erisimi_geldi',
            "Merkezde erişimi geri geldi, hesap açıldı: {$user->email}",
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
    private function close(User $user, string $centerStatus): bool
    {
        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->activity->recordAs(
            $user->id, null, request()->ip(), $user, 'kimlik_erisimi_kalkti',
            "Merkezde erişimi kalktı, hesap kapatıldı: {$user->email} (merkezdeki durum: {$centerStatus})",
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
    private function roleUpdate(User $user, ?string $slug): bool
    {
        $new = $this->role($slug);

        if ($new === null || $user->role_id === $new->id) {
            return false;
        }

        $old = $user->role?->label ?? 'rolsüz';

        $user->forceFill(['role_id' => $new->id])->save();

        $this->activity->recordAs(
            $user->id, null, request()->ip(), $user, 'kimlik_rolu_degisti',
            "Merkezde rolü değişti: {$user->email} · {$old} → {$new->label}"
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
    private function role(?string $slug): ?Role
    {
        if (blank($slug)) {
            return null;
        }

        $role = Role::query()->where('slug', $slug)->first();

        if ($role === null || ($role->is_admin && ! config('identity.yonetici_devri'))) {
            return null;
        }

        return $role;
    }
}
