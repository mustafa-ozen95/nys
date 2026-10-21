<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityChannel: string
{
    case Auth = 'auth';
    case Crud = 'crud';
    case Ssh = 'ssh';
    case Rdp = 'rdp';
    case Sync = 'sync';
    case Config = 'config';

    /** Sayfa açma ve form gönderme — kullanıcının her hareketi. */
    case Access = 'access';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Oturum',
            self::Crud => 'Kayıt İşlemi',
            self::Ssh => 'SSH',
            self::Rdp => 'RDP',
            self::Sync => 'Senkronizasyon',
            self::Config => 'Cihaz Yapılandırması',
            self::Access => 'Erişim',
        };
    }

    /**
     * Satırın yazılacağı hash zinciri.
     *
     * Erişim kayıtları (sayfa açma) hacmin neredeyse tamamıdır ve denetim
     * kayıtlarından ÇOK DAHA KISA saklanır. Tek zincirin ortasından satır
     * silmek zinciri kırdığı için ayrı zincire yazılırlar; koruma azalmaz,
     * yalnızca budama bağımsızlaşır.
     */
    public function chain(): string
    {
        return $this === self::Access ? 'access' : 'audit';
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Auth => BadgeVariant::Blue,
            self::Crud => BadgeVariant::Cyan,
            self::Ssh => BadgeVariant::Green,
            self::Rdp => BadgeVariant::Purple,
            self::Sync => BadgeVariant::Gray,
            self::Config => BadgeVariant::Orange,
            self::Access => BadgeVariant::Gray,
        };
    }
}
