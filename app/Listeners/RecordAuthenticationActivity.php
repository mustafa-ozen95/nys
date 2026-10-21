<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Oturum olaylarını etkinlik kaydına yazar ve son giriş zamanını günceller.
 */
class RecordAuthenticationActivity
{
    public function __construct(private Request $request, private ActivityChain $chain) {}

    public function handleLogin(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->record($user->id, 'login', "{$user->name} oturum açtı");
    }

    public function handleLogout(Logout $event): void
    {
        /** @var User|null $user */
        $user = $event->user;

        if ($user !== null) {
            $this->record($user->id, 'logout', "{$user->name} oturumu kapattı");
        }
    }

    public function handleFailed(Failed $event): void
    {
        $email = $this->identity($event->credentials['email'] ?? null);

        $this->record(null, 'login_failed', "Başarısız giriş denemesi: {$email}");
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $this->identity($event->request->input('email'));

        $this->record(null, 'lockout', "Çok fazla başarısız deneme: {$email}");
    }

    /**
     * Denenen hesap adı, kayıtta ve tekilleştirme anahtarında aynı biçimde
     * görünsün diye küçük harfe çevrilir ve kısaltılır.
     */
    private function identity(mixed $email): string
    {
        $email = is_string($email) ? trim($email) : '';

        return $email === '' ? 'bilinmiyor' : mb_strtolower(mb_substr($email, 0, 255));
    }

    /**
     * Aynı kullanıcı aynı IP'den kısa aralıkla tekrar giriş yaparsa yeni kayıt
     * açılmaz, mevcut kaydın zamanı tazelenir.
     *
     * Bunsuz sekme yenilemeleri ve tarayıcı testleri "son hareketler"
     * listesini aynı satırla dolduruyordu.
     *
     * Tekilleştirme anahtarına AÇIKLAMA da girer: başarısız girişte açıklama
     * denenen hesabı taşır. Yoksa tek IP'den farklı hesaplara yapılan parola
     * püskürtmesi tek satıra düşüyor, yalnızca ilk hesap kayda geçiyordu.
     *
     * Satır hash zincirine yazılır (updated_at özete girmez; tekilleştirmede
     * yalnızca o tazelenir, böylece zincir bozulmaz).
     */
    private function record(?int $userId, string $action, string $description): void
    {
        $ip = $this->request->ip();
        $recent = ActivityLog::query()
            ->where('channel', ActivityChannel::Auth)
            ->where('action', $action)
            ->where('description', $description)
            ->where('user_id', $userId)
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes(config('activity.auth_dedupe_minutes')))
            ->latest()
            ->first();

        if ($recent !== null) {
            $recent->touch();

            return;
        }

        $this->chain->append([
            'user_id' => $userId,
            'channel' => ActivityChannel::Auth,
            'action' => $action,
            'description' => $description,
            'ip_address' => $ip,
        ]);
    }
}
