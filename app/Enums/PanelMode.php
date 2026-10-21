<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Panelin ayrıntı düzeyi.
 *
 * Günlük işte alanların çoğu kullanılmaz ve cihazın varsayılanıyla
 * ilerlenir; her formu bütün seçenekleriyle göstermek işi yavaşlatıyor ve
 * yanlış dolduruma davet ediyor.
 *
 * GİZLEMEK SİLMEK DEĞİLDİR. Panelde birçok alan "gönderilmedi = cihazdan
 * sil" anlamına gelir; basit modda gizlenen alan isteğe HİÇ konmaz, böylece
 * cihazdaki değere dokunulmaz. Bu kural PanelModeTest ile kilitlidir.
 */
enum PanelMode: string
{
    case Simple = 'simple';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Basit',
            self::Advanced => 'Gelişmiş',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Simple => 'Yalnızca sık kullanılan alanlar. Gizli alanlara dokunulmaz.',
            self::Advanced => 'Bütün alanlar görünür.',
        };
    }

    /**
     * Oturumdaki kullanıcının modu.
     *
     * Oturum yoksa (giriş ekranı, konsol, e-posta görünümü) BASİT sayılır:
     * o bağlamlarda gelişmiş alan yoktur ve tanımsız bir değişkenle
     * patlamamalı.
     */
    public static function current(): self
    {
        return auth()->user()?->etkinPanelModu() ?? self::Simple;
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Simple => BadgeVariant::Gray,
            self::Advanced => BadgeVariant::Cyan,
        };
    }

    public function isAdvanced(): bool
    {
        return $this === self::Advanced;
    }

    public function other(): self
    {
        return $this === self::Simple ? self::Advanced : self::Simple;
    }
}
