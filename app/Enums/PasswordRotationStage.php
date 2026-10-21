<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parola değiştirme turunun nereye kadar geldiği.
 *
 * Bir tur yarıda kalırsa cihazın hangi durumda olduğunu SADECE bu söyler:
 * kurtarma hesabı açıldı mı, parola değişti mi, eski parola hâlâ geçerli mi.
 * Kayıt tutulmazsa arıza anında cihaza hangi parolayla bağlanılacağı bilinmez.
 */
enum PasswordRotationStage: string
{
    case Started = 'started';
    case RecoveryCreated = 'recovery_created';
    case RecoveryVerified = 'recovery_verified';
    case PasswordChanged = 'password_changed';
    case PasswordVerified = 'password_verified';
    case RecoveryRemoved = 'recovery_removed';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Başladı',
            self::RecoveryCreated => 'Kurtarma hesabı açıldı',
            self::RecoveryVerified => 'Kurtarma hesabı denendi',
            self::PasswordChanged => 'Parola cihaza yazıldı',
            self::PasswordVerified => 'Yeni parola denendi',
            self::RecoveryRemoved => 'Kurtarma hesabı silindi',
        };
    }

    /**
     * Renk, o aşamada TAKILMIŞ bir turun ne kadar acil olduğunu söyler:
     * cihazda kurtarma hesabı duruyorsa kırmızı, parola henüz yazılmadıysa
     * gri, tur bittiyse yeşil.
     */
    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Started => BadgeVariant::Gray,
            self::RecoveryCreated,
            self::RecoveryVerified => BadgeVariant::Orange,
            self::PasswordChanged,
            self::PasswordVerified => BadgeVariant::Red,
            self::RecoveryRemoved => BadgeVariant::Green,
        };
    }

    /**
     * Bu aşamada takılan bir tur, cihazda hangi parolanın geçerli olduğunu
     * söyler. Ekranda kullanıcıya ne yapması gerektiğini anlatır.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Started => 'Cihaza hiç yazılmadı; eski parola geçerli.',
            self::RecoveryCreated,
            self::RecoveryVerified => 'Eski parola hâlâ geçerli. Cihazda kurtarma hesabı duruyor.',
            self::PasswordChanged => 'Cihazda YENİ parola var ama denenemedi. Kurtarma hesabı duruyor.',
            self::PasswordVerified => 'Yeni parola çalışıyor. Kurtarma hesabı silinemedi.',
            self::RecoveryRemoved => 'Tur tamamlandı.',
        };
    }

    /**
     * Turun kaçıncı adımında olunduğu (1'den başlar).
     *
     * Sıra SwitchPasswordRotator'daki adımlarla birebir aynıdır; ekrandaki
     * çubuk ile kodun adımları ayrışırsa çubuk yalan söyler.
     */
    public function step(): int
    {
        return match ($this) {
            self::Started => 1,
            self::RecoveryCreated => 2,
            self::RecoveryVerified => 3,
            self::PasswordChanged => 4,
            self::PasswordVerified => 5,
            self::RecoveryRemoved => 6,
        };
    }

    /** Turdaki toplam adım sayısı. */
    public const ADIM_SAYISI = 6;

    /**
     * Tur SÜRERKEN ne yapıldığını anlatır.
     *
     * hint() takılmış bir turu anlatıyor ("eski parola hâlâ geçerli"); süren
     * bir turda o dil yanlış olur. Bu yüzden ayrı: kayıtta duran aşama en son
     * BİTEN adım olduğundan, sıradaki iş yazılır.
     */
    public function running(): string
    {
        return match ($this) {
            self::Started => 'Cihaza bağlanılıyor, kurtarma hesabı açılıyor…',
            self::RecoveryCreated => 'Kurtarma hesabıyla giriş deneniyor…',
            self::RecoveryVerified => 'Yeni parola cihaza yazılıyor…',
            self::PasswordChanged => 'Yeni parola deneniyor…',
            self::PasswordVerified => 'Panel güncellendi, kurtarma hesabı siliniyor…',
            self::RecoveryRemoved => 'Tur tamamlanıyor…',
        };
    }
}
