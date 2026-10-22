<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\PortMode;
use App\Models\SwitchPort;
use App\Models\SwitchVoipInterface;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * VoIP (ses) VLAN ayarı.
 *
 * Cihazın ve Junos'un kuralları formda ÖNCEDEN söylenir:
 *
 * - Arayüz ethernet-switching ailesinde olmalı. Cihaz aksini
 *   "Interface ge-0/0/20.0 not found or does not have ethernet-switching
 *   family" ile reddediyor (test1'de ölçüldü). "all" ve "access-ports"
 *   cihazın kabul ettiği sanal adlardır.
 * - Seçilen VLAN bu switchte TANIMLI olmalı; cihazda VLAN'ın kimliği adıdır.
 * - Ses VLAN'ı portun ETİKETSİZ (access) VLAN'ından farklı olmalıdır:
 *   telefon etiketli, bilgisayar etiketsiz gider. Aynı VLAN verilirse ayar
 *   hiçbir işe yaramaz.
 * - Arayüz adı cihazda KİMLİKTİR; düzenlemede değiştirilemez ve değişmiş
 *   bir değer sessizce yok sayılmaz, açıkça reddedilir.
 */
class VoipInterfaceRequest extends FormRequest
{
    private SwitchPort|null|false $cachedPort = false;

    private bool $portResolved = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Junos arayüz adını BİRİMİYLE yazar (ge-0/0/5.0) ve cihazdan da öyle
     * okunur. Birimsiz gelen ad burada tamamlanır; yoksa aynı arayüz iki
     * ayrı kayıt olarak durur ve tekillik kuralı hiçbir şeyi korumaz.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('interface_name')) {
            $this->merge(['interface_name' => self::canonical($this->string('interface_name')->toString())]);
        }
    }

    public static function canonical(string $name): string
    {
        $name = trim($name);

        if (in_array($name, SwitchVoipInterface::VIRTUAL_INTERFACES, true) || $name === '') {
            return $name;
        }

        return preg_match('/\.\d+$/', $name) === 1 ? $name : $name.'.0';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switchId = $this->route('switch')->id;
        $current = $this->route('voipInterface');

        return [
            'interface_name' => [
                Rule::requiredIf($current === null),
                'string', 'max:64',
                Rule::unique(SwitchVoipInterface::class, 'interface_name')
                    ->where('switch_id', $switchId)
                    ->ignore($current),
            ],

            /*
             * Ses VLAN'ı ADIYLA yazılır ve cihazda tanımlı olmalıdır;
             * tanımsız ada atıf commit'i düşürür.
             */
            'vlan_name' => [
                'required_without:forwarding_class',
                'nullable', 'string', 'max:64',
                Rule::exists(Vlan::class, 'name')->where('switch_id', $switchId),
            ],

            /*
             * Sınıf listesi kapalı DEĞİLDİR: cihazda kendi sınıflarını
             * tanımlayan kurulumlar var ve panel onları reddetmemeli.
             * Yalnızca Junos'un ad kuralı uygulanır (harfle başlar).
             */
            'forwarding_class' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardImmutableName($validator);
            $this->guardRejectedName($validator);
            $this->guardSwitchingPort($validator);
            $this->guardAccessVlanCollision($validator);
        });
    }

    /**
     * Arayüz adı cihazda kimliktir: değiştirmek silip yeniden yaratmaktır.
     * Değişmiş bir değer SESSİZCE yok sayılsaydı kullanıcı adı değiştirdiğini
     * sanırdı (bkz. .ai/rules/junos.md → "cihazda KİMLİKTİR").
     */
    private function guardImmutableName(Validator $validator): void
    {
        $current = $this->route('voipInterface');

        if (! $current instanceof SwitchVoipInterface || ! $this->filled('interface_name')) {
            return;
        }

        if ($this->string('interface_name')->toString() !== $current->interface_name) {
            $validator->errors()->add(
                'interface_name',
                'Arayüz adı cihazda kimliktir ve değiştirilemez; kayıt silinip yeniden eklenir.',
            );
        }
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta VoIP VLAN vermiyor:
     * "Interface ge-0/0/20.0 not found or does not have ethernet-switching
     * family" (test1'de ölçüldü).
     */
    private function guardSwitchingPort(Validator $validator): void
    {
        $port = $this->port();

        if ($port === false) {
            return;
        }

        if ($port === null) {
            $validator->errors()->add('interface_name', 'Bu switchte böyle bir port yok.');

            return;
        }

        if (! in_array($port->port_mode, [PortMode::Access, PortMode::Trunk], true)) {
            $validator->errors()->add(
                'interface_name',
                "{$this->interfaceName()} anahtarlama ailesinde değil; cihaz bu porta VoIP VLAN tanımlamayı reddediyor.",
            );
        }
    }

    /**
     * Ses VLAN'ı portun ETİKETSİZ VLAN'ından farklı olmalıdır: telefon
     * etiketli, ona bağlı bilgisayar etiketsiz gider. Aynı VLAN verilirse
     * ayarın hiçbir etkisi olmaz.
     *
     * Cihazdan OKUNMUŞ böyle bir kayıt varsa düzenlemesi kilitlenmez: kural
     * yalnızca değeri gerçekten değiştirene uygulanır, yoksa bozuk bir satır
     * panelden hiç düzeltilemezdi.
     */
    private function guardAccessVlanCollision(Validator $validator): void
    {
        $port = $this->port();
        $vlan = $this->string('vlan_name')->toString();
        $current = $this->route('voipInterface');

        if (! $port instanceof SwitchPort || $vlan === '') {
            return;
        }

        if ($current instanceof SwitchVoipInterface && $current->vlan_name === $vlan) {
            return;
        }

        $untagged = $port->vlans->firstWhere('pivot.is_untagged', true);

        if ($untagged !== null && $untagged->name === $vlan) {
            $validator->errors()->add(
                'vlan_name',
                "{$vlan} bu portun etiketsiz VLAN'ı; ses VLAN'ı ondan farklı olmalıdır "
                    .'(telefon etiketli, bilgisayar etiketsiz gider).',
            );
        }
    }

    /**
     * Cihazın VoIP'te reddettiği sanal ad.
     *
     * Ekranda sunulmuyor ama eski bir form ya da elle gönderilen bir istek
     * hâlâ taşıyabilir. Sessizce "all.0"a dönüşmesi daha kötü olurdu:
     * kullanıcı bütün portları ayarladığını sanırdı.
     */
    private function guardRejectedName(Validator $validator): void
    {
        if ($this->interfaceName() === 'all') {
            $validator->errors()->add(
                'interface_name',
                'Cihaz VoIP ayarında “all” adını kabul etmiyor; “access-ports” ya da tek bir port seçin.',
            );
        }
    }

    /**
     * İlgili port: sanal adlarda false, bulunamadığında null.
     *
     * İki ayrı kural aynı porta bakıyor; sorgu bir kez çalışır.
     */
    private function port(): SwitchPort|null|false
    {
        if ($this->portResolved) {
            return $this->cachedPort;
        }

        $this->portResolved = true;
        $name = $this->interfaceName();

        if ($name === '' || in_array($name, SwitchVoipInterface::VIRTUAL_INTERFACES, true)) {
            return $this->cachedPort = false;
        }

        return $this->cachedPort = SwitchPort::query()
            ->with('vlans')
            ->where('switch_id', $this->route('switch')->id)
            ->firstWhere('name', (string) preg_replace('/\.\d+$/', '', $name));
    }

    /**
     * Cihaza yazılacak ayarlar. Korunan ham satırlar düzenlenen kayıttan
     * gelir; panelde gösterilmeyen satırlar kaybolmasın.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $current = $this->route('voipInterface');

        return [
            'vlan_name' => $this->filled('vlan_name') ? $this->string('vlan_name')->toString() : null,
            'forwarding_class' => $this->filled('forwarding_class')
                ? $this->string('forwarding_class')->toString()
                : null,
            'residual_xml' => $current instanceof SwitchVoipInterface ? $current->residual_xml : null,
        ];
    }

    /**
     * Cihaza yazılacak arayüz adı. Düzenlemede KAYITTAN okunur: ad cihazda
     * kimliktir ve formdan gelen değer onu değiştiremez.
     */
    public function interfaceName(): string
    {
        $current = $this->route('voipInterface');

        return $current instanceof SwitchVoipInterface
            ? (string) $current->interface_name
            : self::canonical($this->string('interface_name')->toString());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'interface_name' => 'arayüz',
            'vlan_name' => 'ses VLAN’ı',
            'forwarding_class' => 'iletim sınıfı',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vlan_name.exists' => 'Bu switchte böyle bir VLAN tanımlı değil.',
            'vlan_name.required_without' => 'En az bir ayar verilmeli: ses VLAN’ı ya da iletim sınıfı.',
            'forwarding_class.regex' => 'Junos sınıf adı harfle başlar; harf, rakam, tire ve alt çizgi içerir.',
        ];
    }
}
