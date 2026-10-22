<?php

declare(strict_types=1);

namespace App\View\Components\Form;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Listeden seç ya da elle gir.
 *
 * Mekanizma tektir ve burada durur: seçim kutusu ile elle giriş kutusu AYNI
 * adı taşır, her an yalnızca birinde `name` niteliği bulunur. Böylece Form
 * Request tarafında hiçbir şey değişmez ve JavaScript çalışmasa bile form
 * doğru değeri gönderir.
 *
 * Önce yalnızca IP adresleri için vardı (bkz. IpPicker). Anons ekranında aynı
 * soru AS numarası için de soruldu — "listede olanı seç ya da müşterininkini
 * yaz" — ve mekanizmayı ikinci kez yazmak yerine buraya alındı. Alan türüne
 * özgü olan tek şey METİNLERDİR; onlar alt sınıfta ya da çağrı yerinde
 * verilir.
 */
class Picker extends Component
{
    /** Elle giriş seçeneğinin değeri. Geçerli bir veri olamayacağı için çakışmaz. */
    public const MANUAL = '__elle__';

    /** Formun taşıdığı güncel değer. */
    public string $current;

    /** Değer listede yok: elle giriş kutusu açık başlar. */
    public bool $manualActive;

    /**
     * @param  array<string, mixed>  $options  Düz (değer => etiket) ya da
     *                                         gruplu (başlık => (değer => etiket))
     */
    public function __construct(
        public string $name,
        public array $options = [],
        public ?string $id = null,
        ?string $value = null,
        public bool $manual = false,
        public string $placeholder = '',
        public string $empty = 'Seçiniz',
        public ?string $emptyText = null,
        public string $manualLabel = 'Listede yok — elle gir',
        public string $manualAria = 'Değeri elle girin',
    ) {
        $this->current = (string) (old($name, $value) ?? '');
        $this->manualActive = $manual && $this->current !== '' && ! $this->contains($this->current);
    }

    /**
     * Seçilecek bir şey var mı? Yoksa ekran elle girişe düşer.
     */
    public function hasOptions(): bool
    {
        return $this->options !== [];
    }

    /**
     * Liste boşken ve elle giriş de kapalıyken gösterilecek metin.
     *
     * Adı $emptyText DEĞİLDİR: Blade'de aynı adı taşıyan özellik ile metot
     * çakışır, `$emptyText()` özelliği çağırmaya kalkar.
     */
    public function emptyMessage(): string
    {
        return $this->emptyText ?? 'Seçilecek kayıtlı değer yok.';
    }

    /**
     * Kutuda gösterilecek seçenekler; boş seçenek ve elle giriş dahil.
     *
     * @return array<string, mixed>
     */
    public function choices(): array
    {
        $options = ['' => $this->empty] + $this->options;

        return $this->manual
            ? $options + [self::MANUAL => $this->manualLabel]
            : $options;
    }

    /**
     * Elle giriş kutusunun kimliği.
     *
     * Seçilecek bir şey yoksa tek kutu odur ve alanın ASIL kimliğini taşır:
     * etiketin "for" değeri ile ekranda gerçekten duran kutu ayrışmamalı.
     */
    public function manualId(): string
    {
        return $this->hasOptions() ? ($this->id ?? $this->name).'-elle' : ($this->id ?? $this->name);
    }

    /** Kutuda seçili duracak değer. */
    public function selected(): string
    {
        return $this->manualActive ? self::MANUAL : $this->current;
    }

    /** Elle giriş kutusunun taşıyacağı değer. */
    public function manualValue(): string
    {
        return $this->manualActive || ! $this->hasOptions() ? $this->current : '';
    }

    /** Elle giriş kutusu şu an açık mı? */
    public function manualOpen(): bool
    {
        return ! $this->hasOptions() || $this->manualActive;
    }

    private function contains(string $value): bool
    {
        foreach ($this->options as $key => $label) {
            if (is_array($label) ? isset($label[$value]) : (string) $key === $value) {
                return true;
            }
        }

        return false;
    }

    public function render(): View
    {
        return view('components.form.picker');
    }
}
