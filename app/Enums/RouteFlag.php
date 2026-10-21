<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statik route'un boş (değersiz) seçenekleri.
 *
 * Junos'ta bunlar birer varlık/yokluk etiketidir: <retain/>, <no-readvertise/>.
 * Hepsi test1'de commit check ile denendi; birbirini dışlayan çiftler
 * cihazın kendi kuralıdır ("Must not specify resolve with retain" — ölçüldü)
 * ve doğrulamada karşılanır.
 */
enum RouteFlag: string
{
    case Retain = 'retain';
    case NoRetain = 'no-retain';
    case Readvertise = 'readvertise';
    case NoReadvertise = 'no-readvertise';
    case Resolve = 'resolve';
    case NoResolve = 'no-resolve';
    case Install = 'install';
    case NoInstall = 'no-install';
    case Active = 'active';
    case Passive = 'passive';

    public function label(): string
    {
        return match ($this) {
            self::Retain => 'Yeniden başlatmada koru (retain)',
            self::NoRetain => 'Yeniden başlatmada koruma (no-retain)',
            self::Readvertise => 'Yeniden duyur (readvertise)',
            self::NoReadvertise => 'Yeniden duyurma (no-readvertise)',
            self::Resolve => 'Sonraki atlamayı çöz (resolve)',
            self::NoResolve => 'Sonraki atlamayı çözme (no-resolve)',
            self::Install => 'Yönlendirme tablosuna kur (install)',
            self::NoInstall => 'Yönlendirme tablosuna kurma (no-install)',
            self::Active => 'Etkin (active)',
            self::Passive => 'Pasif — sonraki atlama erişilemese de dursun (passive)',
        };
    }

    /**
     * Rozet rengi: "olumsuz" seçenekler (no-*, passive) gri, olumlular mavi.
     */
    public function badge(): BadgeVariant
    {
        return str_starts_with($this->value, 'no-') || $this === self::Passive
            ? BadgeVariant::Gray
            : BadgeVariant::Blue;
    }

    /**
     * Birlikte kullanılamayan çiftler. Cihaz zaten reddeder; panel önce söyler.
     *
     * @return array<string, list<string>>
     */
    public static function conflicts(): array
    {
        return [
            self::Retain->value => [self::NoRetain->value, self::Resolve->value],
            self::NoRetain->value => [self::Retain->value],
            self::Readvertise->value => [self::NoReadvertise->value],
            self::NoReadvertise->value => [self::Readvertise->value],
            self::Resolve->value => [self::NoResolve->value, self::Retain->value],
            self::NoResolve->value => [self::Resolve->value],
            self::Install->value => [self::NoInstall->value],
            self::NoInstall->value => [self::Install->value],
            self::Active->value => [self::Passive->value],
            self::Passive->value => [self::Active->value],
        ];
    }
}
