<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\SwitchPrefixList;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Prefix listenin kendi ayarları: apply-path ifadeleri.
 *
 * Junos bir listeyi apply-path ile DİNAMİK doldurur: girdiler yapılandırmada
 * tek tek yazılmaz, cihaz onları başka bir hiyerarşiden türetir. Örnek
 * (test1'de commit check ile ölçüldü):
 *
 *     apply-path "system radius-server <*>";
 *
 * Statik girdilerle birlikte kullanılabilir; ikisi birbirini dışlamaz.
 *
 * Cihazın kendi kuralları burada, forma dönerken söylenir: cihaz reddedene
 * kadar beklemek kullanıcıya "commit check başarısız" demekten başka bir şey
 * anlatmaz.
 */
class PrefixListSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Boş satırlar doğrulamadan ÖNCE düşer.
     *
     * Tekrarlanan alanda son satır silinemez, yalnızca boşaltılır (bkz.
     * route-form.js). Boş dize doğrulamaya girseydi "ifadeyi kaldır" işlemi
     * "bu alan gerekli" hatasıyla dönerdi ve liste hiç boşaltılamazdı.
     */
    protected function prepareForValidation(): void
    {
        $paths = array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            (array) $this->input('apply_paths', []),
        );

        $this->merge([
            'apply_paths' => array_values(array_filter($paths, static fn (string $path): bool => $path !== '')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'apply_paths' => ['array', 'max:8'],

            /*
             * İfade bir yapılandırma yoludur: sözcükler, joker <*> ve yol
             * karakterleri. Junos tırnak içinde saklar; tırnağı biz koymayız,
             * XML yüklemede gerekmez.
             */
            'apply_paths.*' => [
                'required', 'string', 'max:255',
                'regex:/^[A-Za-z][A-Za-z0-9 <>*_.:\/-]*$/',
            ],

            /*
             * Çıkış bekçisi listeyi boşaltmak bilinçli bir karardır; kutu
             * yalnızca liste gerçekten boşalacaksa sorulur ve aksi hâlde hiç
             * gönderilmez.
             */
            'acknowledge_empty' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Cihaza yazılacak ifadeler; boş satırlar prepareForValidation'da düşmüştür.
     *
     * @return list<string>
     */
    public function applyPaths(): array
    {
        return array_values((array) $this->input('apply_paths', []));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Biçim hatası varsa cihaz kuralını sormaya gerek yok.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->guardAngleBrackets($validator);
            $this->guardDuplicates($validator);
            $this->guardEmptyGuard($validator);
        });
    }

    /**
     * Joker işareti kapanmalı: "<*" cihazda sözdizimi hatasıdır ve iç içe
     * açılmaz.
     */
    private function guardAngleBrackets(Validator $validator): void
    {
        foreach ($this->applyPaths() as $path) {
            $open = 0;

            foreach (str_split($path) as $character) {
                $open += match ($character) {
                    '<' => 1,
                    '>' => -1,
                    default => 0,
                };

                if ($open < 0 || $open > 1) {
                    break;
                }
            }

            if ($open !== 0) {
                $validator->errors()->add(
                    'apply_paths',
                    "Joker işareti kapanmamış: “{$path}”. Doğrusu “system radius-server <*>” biçimindedir.",
                );

                return;
            }
        }
    }

    private function guardDuplicates(Validator $validator): void
    {
        $paths = $this->applyPaths();

        if (count($paths) !== count(array_unique($paths))) {
            $validator->errors()->add('apply_paths', 'Aynı ifade iki kez yazılamaz.');
        }
    }

    /**
     * Çıkış bekçisi bir listeyi BOŞALTMAK, o uplinkten geçen bütün trafiği
     * düşürmek demektir: filtre "kaynağı bu listede olanı kabul et" der.
     *
     * İşlem YASAK DEĞİLDİR — cihaz kabul ediyor ve panelin görevi yönetmeyi
     * mümkün kılmak. Ama sonucu görülmeden yapılmamalı: kullanıcı ne olacağını
     * okuyup açıkça onaylar. Onay kutusu yalnızca liste gerçekten boşalacaksa
     * sorulur.
     */
    private function guardEmptyGuard(Validator $validator): void
    {
        if (! $this->emptiesEgressGuard() || $this->boolean('acknowledge_empty')) {
            return;
        }

        /** @var SwitchPrefixList $list */
        $list = $this->route('prefixList');

        $validator->errors()->add(
            'apply_paths',
            'Bu liste '.implode(', ', $list->egress_interfaces).' çıkışını koruyor ve içinde statik prefix yok. '
                .'Son ifade kaldırılırsa liste boşalır ve o çıkıştan hiçbir trafik geçemez. '
                .'Yine de devam edecekseniz onay kutusunu işaretleyin.',
        );
    }

    /**
     * Kaydedilirse çıkış bekçisi bir liste tamamen boşalacak mı?
     */
    private function emptiesEgressGuard(): bool
    {
        $list = $this->route('prefixList');

        if (! $list instanceof SwitchPrefixList || ! $list->is_egress_guard) {
            return false;
        }

        return $this->applyPaths() === [] && ! $list->entries()->exists();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'apply_paths' => 'apply-path ifadeleri',
            'acknowledge_empty' => 'boşaltma onayı',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'apply_paths.*.regex' => 'İfade yalnızca yol sözcükleri ve joker (<*>) içerebilir. Örnek: system radius-server <*>',
        ];
    }
}
