<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Models\UserIdentity;

/**
 * Merkezdeki kimliği YEREL hesapla eşleştirir.
 *
 * Sıra ve gerekçeleri:
 *
 *  1. Daha önce bağlanmışsa SUBJECT ile bulunur. Kişi merkezde e-postasını
 *     değiştirse bile bağ kopmaz.
 *
 *  2. Bağ yoksa e-postaya bakılır — ama YALNIZCA merkez o adresi doğrulanmış
 *     sayıyorsa ve yerelde tam olarak BİR karşılığı varsa. Doğrulanmamış bir
 *     adresle eşleştirmek, meslektaşının e-postasıyla merkezde hesap açan
 *     birinin onun yerine girmesi demek olurdu.
 *
 *  3. Hiçbiri tutmuyorsa BAĞLANMAZ. Otomatik kullanıcı oluşturmak yok:
 *     merkezde hesabı olan herkesin bu panelde de işi olduğu anlamına
 *     gelmez. Hesabı yönetici açar.
 */
class AccountMatcher
{
    public const PROVIDER = 'kimlik';

    /** @param  array{sub: string, name: string, email: string, email_verified: bool}  $kimlik */
    public function find(array $identity): ?User
    {
        $bag = UserIdentity::where('provider', self::PROVIDER)
            ->where('subject', $identity['sub'])
            ->first();

        if ($bag?->user) {
            return $bag->user;
        }

        return $identity['email_verified'] ? $this->emailBy($identity['email']) : null;
    }

    /** İlk eşleşmeyi kalıcı hâle getirir. */
    public function bind(User $user, array $identity): UserIdentity
    {
        return UserIdentity::updateOrCreate(
            ['provider' => self::PROVIDER, 'subject' => $identity['sub']],
            ['user_id' => $user->id, 'email' => $identity['email'], 'last_login_at' => now()],
        );
    }

    /**
     * E-postayla eşleşme: TEK karşılık şart.
     *
     * Birden fazla hesap aynı adresi taşıyorsa hangisinin kastedildiği belli
     * değildir; tahmin etmek yanlış hesaba giriş demek olurdu.
     */
    private function emailBy(string $email): ?User
    {
        $candidates = User::where('email', $email)->limit(2)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
