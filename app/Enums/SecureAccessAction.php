<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sınır aşıldığında cihazın yapacağı şey.
 *
 * Junos'ta iki ayrı sınır aynı eylem kümesini kullanır:
 *
 *   secure-access-port vlan <ad>      mac-move-limit <n> action <...>
 *   secure-access-port interface <ad> mac-limit      <n> action <...>
 *
 * "shutdown" portu HİZMET DIŞI bırakır: arayüz kapatılır ve elle (ya da
 * recovery zamanlayıcısıyla) açılana kadar öyle kalır. Diğer üçü trafiği
 * etkiler ama portu ayakta tutar. Bu ayrım paneldeki uyarıların da
 * kaynağıdır; bkz. isDisruptive().
 */
enum SecureAccessAction: string
{
    case Drop = 'drop';
    case Log = 'log';
    case Shutdown = 'shutdown';
    case None = 'none';

    /** Yalnızca ELS'te vardır; düşürür VE günlüğe yazar (test4'te ölçüldü). */
    case DropAndLog = 'drop-and-log';

    public function label(): string
    {
        return match ($this) {
            self::Drop => 'Fazlasını düşür (drop)',
            self::Log => 'Yalnızca günlüğe yaz (log)',
            self::Shutdown => 'Portu kapat (shutdown)',
            self::None => 'Hiçbir şey yapma (none)',
            self::DropAndLog => 'Düşür ve günlüğe yaz (drop-and-log)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Drop => BadgeVariant::Orange,
            self::Log => BadgeVariant::Gray,
            self::Shutdown => BadgeVariant::Red,
            self::None => BadgeVariant::Gray,
            self::DropAndLog => BadgeVariant::Orange,
        };
    }

    /**
     * Portu hizmet dışı bırakan eylem mi?
     *
     * Ekran ve onay metni bunu ayrıca söyler: yanlış bir sınırla birlikte
     * "shutdown" seçmek, sınırı aşan ilk cihaz takıldığında portun ölmesi
     * demektir ve port kendiliğinden geri gelmez.
     */
    public function isDisruptive(): bool
    {
        return $this === self::Shutdown;
    }

    /**
     * Formdaki seçenek listesi: değer => etiket.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::optionsFrom(self::cases());
    }

    /**
     * Verilen eylemlerden seçenek listesi.
     *
     * Hangi eylemlerin geçerli olduğu CİHAZ AİLESİNE bağlıdır ve lehçe
     * söyler: ELS "log" ile "none"ı reddediyor, karşılığında "drop-and-log"
     * sunuyor (test4'te ölçüldü). Panelin bütün kümeyi göstermesi,
     * kullanıcıya cihazın kabul etmeyeceği bir seçim sunmak olurdu.
     *
     * @param  list<self>  $actions
     * @return array<string, string>
     */
    public static function optionsFrom(array $actions): array
    {
        $options = ['' => 'Cihazın varsayılanı'];

        foreach ($actions as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
