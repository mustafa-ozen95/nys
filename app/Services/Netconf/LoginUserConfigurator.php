<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchLoginUser;

/**
 * Cihaz üzerindeki yerel giriş hesaplarını yazar.
 *
 * Parola cihaza DÜZ METİN olarak gider ve cihaz kendi özetini üretir;
 * panelde saklanmaz. Commit açıklamasına konmaz ve cihaz farkı kayda
 * geçerken maskelenir (bkz. ConfigurationRedactor).
 *
 * Ad cihazda hesabın KİMLİĞİDİR; değiştirmek silip yeniden yaratmak
 * demektir. Panel adı düzenletmez.
 *
 * Panelin bağlandığı hesap silinemez ve yetkisi düşürülemez — kontrol
 * doğrulamadadır (bkz. LoginUserRequest), burada değil: bu sınıf ne
 * istenirse onu yazar ve tek sorumluluğu doğru Junos üretmektir.
 */
class LoginUserConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private DeviceSettingsSynchronizer $settings,
    ) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function create(
        NetworkSwitch $switch,
        string $name,
        string $class,
        ?string $fullName,
        ?int $uid,
        ?string $password,
    ): array {
        return $this->apply(
            $switch,
            $this->element($name, $class, $fullName, $uid, $password),
            'giris hesabi eklendi',
        );
    }

    /**
     * Parola boş bırakılırsa cihazdaki DEĞİŞMEZ: merge yalnızca ekler ve
     * authentication düğümüne dokunulmaz.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function update(
        NetworkSwitch $switch,
        SwitchLoginUser $user,
        string $class,
        ?string $fullName,
        ?string $password,
    ): array {
        return $this->apply(
            $switch,
            $this->element((string) $user->name, $class, $fullName, null, $password),
            'giris hesabi guncellendi',
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchLoginUser $user): array
    {
        return $this->apply(
            $switch,
            '<user delete="delete"><name>'.$this->escape((string) $user->name).'</name></user>',
            'giris hesabi silindi',
        );
    }

    private function element(string $name, string $class, ?string $fullName, ?int $uid, ?string $password): string
    {
        $body = '<name>'.$this->escape($name).'</name>'
            .'<class>'.$this->escape($class).'</class>';

        // Tam ad boş bırakılırsa cihazdan silinir; yarım kalmış bir etiket
        // bırakmak yerine temizlenir.
        $body .= filled($fullName)
            ? '<full-name>'.$this->escape((string) $fullName).'</full-name>'
            : '<full-name delete="delete"/>';

        if ($uid !== null) {
            $body .= "<uid>{$uid}</uid>";
        }

        if (filled($password)) {
            $body .= '<authentication><plain-text-password-value>'
                .$this->escape((string) $password)
                .'</plain-text-password-value></authentication>';
        }

        return "<user>{$body}</user>";
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(NetworkSwitch $switch, string $body, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            "<configuration><system><login>{$body}</login></system></configuration>",
            $summary,
            fn (NetconfSession $session) => $this->settings->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
