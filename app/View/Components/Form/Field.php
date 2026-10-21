<?php

declare(strict_types=1);

namespace App\View\Components\Form;

use App\Enums\PanelMode;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Formlardaki tek alan sarmalayıcısı.
 *
 * "advanced" işaretli alan BASİT modda düzenlenemez: shouldRender() false
 * döndüğünde Laravel bileşeni çizmez, alan DOM'a girmez ve istekle
 * gönderilmez.
 *
 * Bu ayrım güvenliktir, süs değil. Panelde birçok alanda "gönderilmedi"
 * cihazdaki satırın SİLİNMESİ demektir; CSS ile gizlemek alanı yine
 * göndereceği için yetmez. Ayarları kuran taraf da yalnızca GELEN alanları
 * yazar, böylece kullanıcının görmediği bir ayar sessizce silinmez
 * (PanelModeTest bunu kilitler).
 *
 * GİZLEMEK YALAN SÖYLEMEK DEĞİLDİR. Alan cihazda varsayılanın DIŞINDA bir
 * değer taşıyorsa basit modda da görünür — ama salt okunur bir satır olarak,
 * girdi alanı olmadan. Aksi hâlde 100 Mbps'e sabitlenmiş ya da MTU'su
 * büyütülmüş bir port, basit moddaki kullanıcıya hiçbir iz bırakmadan
 * "olağan" görünürdü. $current bu değerin okunabilir hâlidir; boş bırakmak
 * "varsayılanda, söylenecek bir şey yok" demektir.
 *
 * Mod bileşenin KENDİSİ tarafından çözülür; paylaşılan bir görünüm
 * değişkenine bağlanmaz. Böylece bileşen web isteği dışında da (konsol,
 * e-posta görünümü, doğrudan Blade::render) tanımsız değişkenle patlamaz.
 */
class Field extends Component
{
    public function __construct(
        public string $name,
        public string $label,
        public ?string $hint = null,
        public ?string $id = null,
        public bool $advanced = false,
        public ?string $current = null,
        public ?string $kural = null,
    ) {}

    public function shouldRender(): bool
    {
        return ! $this->collapsed() || $this->locked();
    }

    /**
     * Alan düzenlenemez ama cihazdaki değeri söylenir mi?
     *
     * Blade bu kararı vermez, sorar.
     */
    public function locked(): bool
    {
        return $this->collapsed() && filled($this->current);
    }

    /**
     * Basit modda ÇİZİLMEYEN alan ("advanced" işaretli: Hız, Duplex, MTU).
     * Ayarlar → Basit mod'da açılırsa basit modda da çizilir.
     */
    private function collapsed(): bool
    {
        return $this->advanced
            && ! PanelMode::current()->isAdvanced()
            && ! (bool) config($this->ayarAnahtari(), false);
    }

    /**
     * Basit modda GİZLİ ama FORMDA KALAN alan.
     *
     * Ayarlar → Basit mod'da kapatılan alan görünmez; girdisi DOM'da kalır
     * ve dokunulmamış değeriyle gönderilir, cihazdaki değer korunur.
     * Doğrulama hatası taşıyan alan gizlenmez: okunamayan bir hata, kişiyi
     * düzeltemeyeceği bir formun önünde bırakırdı.
     */
    public function gizli(): bool
    {
        if ($this->advanced || $this->kural === null || PanelMode::current()->isAdvanced()) {
            return false;
        }

        if (session('errors')?->has($this->name)) {
            return false;
        }

        return ! (bool) config($this->ayarAnahtari(), true);
    }

    private function ayarAnahtari(): string
    {
        return 'simple_mode.alanlar.'.$this->kural.'.acik';
    }

    public function render(): View
    {
        return view('components.form.field');
    }
}
