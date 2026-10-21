<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\SwitchPrefixListEntry;
use App\Services\Switching\DependencyGuard;
use App\Support\Net\Cidr;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Prefix listeye eklenecek ağ.
 *
 * Ağ tek alanda gelir ("adres/maske"): listeden seçilir, gerekirse elle
 * yazılır. Adres ve maske ayrı sorulduğunda kullanıcı ikisini uyumsuz
 * bırakabiliyordu; tek alan bu ihtimali kaldırır.
 *
 * IPv4 ve IPv6 ikisi de yazılabilir; cihaz IPv6 girdiyi kabul ediyor
 * ("2001:db8::/32", test1'de commit check ile ölçüldü). Doğrulama ip2long'a
 * DAYANMAZ: o fonksiyon IPv6'da false döner ve sessizce yanlış sonuç üretirdi.
 * Karşılaştırmalar inet_pton üzerinden, adresin ikili karşılığıyla yapılır —
 * "2001:DB8:0::" ile "2001:db8::" aynı ağdır ve kullanıcıya yazım biçimi
 * yüzünden hata gösterilmez.
 */
class PrefixListEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cidr' => ['required', 'string', 'max:45'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('cidr')) {
                return;
            }

            $cidr = $this->parsed();

            if ($cidr === null) {
                $validator->errors()->add(
                    'cidr',
                    'Ağ “adres/maske” biçiminde olmalı. Örnek: 192.0.2.0/24 ya da 2001:db8::/32',
                );

                return;
            }

            // Maskeye hizalı olmayan ağ sessizce düzeltilmez: kullanıcı ne
            // yazdığını görmeli, yoksa cihaza beklemediği prefix gider.
            if (! $this->isAligned($cidr)) {
                $validator->errors()->add(
                    'cidr',
                    "Adres maskeye hizalı değil; /{$cidr->prefixLength} için ağ adresi {$cidr->network} olmalı.",
                );

                return;
            }

            /*
             * Karşılaştırma NORMALLEŞTİRİLMİŞ adresle yapılır: envanterde
             * cihazdan gelen "2001:db8::" durur, kullanıcı "2001:0db8::"
             * yazabilir. Düz metin eşitliği aynı prefixi iki kez eklemeye
             * izin verirdi ve cihaz ikincisini sessizce yutardı.
             */
            $zatenVar = SwitchPrefixListEntry::query()
                ->where('switch_prefix_list_id', $this->route('prefixList')->id)
                ->where('prefix_length', $cidr->prefixLength)
                ->pluck('address')
                ->contains(fn (string $address): bool => @inet_pton($address) === inet_pton($cidr->network));

            if ($zatenVar) {
                $validator->errors()->add('cidr', 'Bu prefix listede zaten var.');

                return;
            }

            $this->guardFamily($validator, $cidr);
        });
    }

    /**
     * Listenin ailesi KULLANILDIĞI YERE göre belirlenir.
     *
     * "family inet" bir firewall filtresinden atıf alan listeye IPv6 prefix
     * eklemek cihazca reddediliyor: "Policy: invalid prefix 2001:db8::/32 for
     * family inet" (test1'de ölçüldü). Bağımsız bir liste karışık taşıyabilir
     * ve cihaz onu kabul ediyor — kural "IPv6 yasak" değil, "kullanıldığı
     * aileyle uyumlu olmalı"dır.
     */
    private function guardFamily(Validator $validator, Cidr $cidr): void
    {
        $aileler = app(DependencyGuard::class)->prefixListFamilies($this->route('prefixList'));

        if ($aileler === []) {
            return;
        }

        $gereken = $cidr->isIpv6 ? 'inet6' : 'inet';

        if (! in_array($gereken, $aileler, true)) {
            $validator->errors()->add(
                'cidr',
                'Bu liste '.implode(', ', $aileler).' ailesindeki bir firewall filtresinden kullanılıyor; '
                ."cihaz oraya {$gereken} prefix'i kabul etmiyor.",
            );
        }
    }

    /**
     * Cihaza yazılan biçim: prefix-list-item adı CIDR'dır.
     */
    public function cidr(): string
    {
        $cidr = $this->parsed();

        return $cidr === null ? '' : "{$cidr->network}/{$cidr->prefixLength}";
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['cidr' => 'ağ'];
    }

    private function parsed(): ?Cidr
    {
        return Cidr::tryParse($this->string('cidr')->trim()->toString());
    }

    /** Kullanıcının yazdığı adres kısmı; hizalama karşılaştırması için. */
    private function address(): string
    {
        return trim(explode('/', $this->string('cidr')->trim()->toString())[0]);
    }

    /**
     * Yazılan adres maskeye hizalı mı?
     *
     * Metin karşılaştırması IPv6'da yanlış cevap verir: "2001:0db8::/32"
     * geçerli ve hizalıdır ama ağ adresinin kanonik yazımı "2001:db8::"tir.
     * Kıyas ikili karşılıklar üzerinden yapılır.
     */
    private function isAligned(Cidr $cidr): bool
    {
        $yazilan = @inet_pton($this->address());

        return $yazilan !== false && $yazilan === inet_pton($cidr->network);
    }
}
