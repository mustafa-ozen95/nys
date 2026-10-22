<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Settings\SettingDefinition;
use App\Services\Settings\SettingsCatalog;
use App\Support\Credentials\PasswordGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ayar formunun doğrulaması.
 *
 * Kurallar elle yazılmaz: config/settings.php içindeki tanımlardan üretilir.
 * Yeni bir ayar eklendiğinde burada değişiklik gerekmez ve ekranda görünen
 * alan ile doğrulanan alan ayrışamaz.
 *
 * Alan adlarında nokta yerine çift alt çizgi kullanılır: HTML form adında
 * nokta, PHP tarafında dizi indeksi gibi ayrıştırılır ve "zabbix.url" adlı
 * alan hiçbir zaman doğrulanamazdı.
 */
class SettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(SettingsCatalog $catalog): array
    {
        $rules = [];

        foreach ($this->submitted($catalog) as $definition) {
            $rules[$definition->field()] = $definition->validationRules();
        }

        return $rules;
    }

    /**
     * Alanlar tek tek geçerli olsa da BİRLEŞİMİ geçersiz olabilir.
     *
     * Kontrol İSTEĞİ değil, KAYDETTİKTEN SONRA YÜRÜRLÜKTE OLACAK durumu
     * sınar: gönderilmeyen alan için yürürlükteki değer kullanılır.
     *
     * Bu ayrım önemliydi. Önceki hâli yalnızca istekteki değerlere bakıyor ve
     * "lowercase alanı gönderilmediyse hiç kontrol etme" diyordu; tek alanlık
     * bir PUT (elle hazırlanmış istek, betik ya da kısmi form) kontrolü
     * tamamen atlıyor, kaydedilmiş değerlerle birleşince cihazın reddedeceği
     * bir ayar kalıcı olarak yazılabiliyordu. Sonrasında her parola turu
     * düşerdi.
     *
     * Son söz yine üreteçtedir (PasswordGenerator kabul etmeyeceği ayarla
     * parola üretmez); burası hatayı erken ve anlaşılır gösterir.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->guardSecretEndpoints($validator, app(SettingsCatalog::class)));

        $validator->after(function (Validator $validator): void {
            $fields = [
                'length' => 'credentials.rotation.length',
                'lowercase' => 'credentials.rotation.lowercase',
                'uppercase' => 'credentials.rotation.uppercase',
                'digits' => 'credentials.rotation.digits',
                'symbols' => 'credentials.rotation.symbols',
            ];

            $sent = array_filter(
                $fields,
                fn (string $key): bool => $this->has(str_replace('.', '__', $key)),
            );

            // Bu ayarlardan hiçbiri gönderilmediyse kontrol edilecek bir şey yok.
            if ($sent === []) {
                return;
            }

            /*
             * Yürürlükte olacak değer: gönderildiyse istekten, gönderilmediyse
             * mevcut ayardan.
             */
            $active = [];

            foreach ($fields as $name => $key) {
                $alan = str_replace('.', '__', $key);

                $active[$name] = $name === 'length'
                    ? ($this->has($alan) ? (int) $this->input($alan) : (int) config($key))
                    : ($this->has($alan) ? $this->boolean($alan) : (bool) config($key));
            }

            $problem = PasswordGenerator::problemWith(
                $active['length'],
                $active['lowercase'],
                $active['uppercase'],
                $active['digits'],
                $active['symbols'],
            );

            if ($problem !== null) {
                // Hata, gönderilen alanlardan birine iliştirilir ki ekranda görünsün.
                $validator->errors()->add(
                    str_replace('.', '__', reset($sent)),
                    $problem,
                );
            }
        });
    }

    /**
     * Yazma-yalnız bir sır, kimlik doğruladığı ucun değişmesiyle başkasına
     * GİTMEZ.
     *
     * Sır (Zabbix jetonu, SMTP parolası) ekranda hiç basılmaz ve boş
     * bırakılınca korunur. Ucu değiştirebilen ama sırrı göremeyen biri
     * (varsayılanda yönetici, ayar yetkisi dağıtılmışsa bölüm yetkilisi)
     * yalnızca adresi kendi sunucusuna çevirip bir sonraki çağrıda sırrı o
     * sunucuya gönderterek ele geçirebilirdi. Bağlı uç değişiyorsa sır AYNI
     * istekte yeniden girilmelidir; uç aynıysa hiçbir şey sorulmaz.
     */
    private function guardSecretEndpoints(Validator $validator, SettingsCatalog $catalog): void
    {
        $section = $this->section($catalog);

        foreach ($catalog->items() as $key => $secret) {
            if (! $secret->isSecret() || $secret->binds === [] || ! in_array($key, $section, true)) {
                continue;
            }

            // Saklı sır yoksa korunacak bir şey yok.
            if (blank(config($key)) || filled($this->input($secret->field()))) {
                continue;
            }

            foreach ($secret->binds as $endpoint) {
                $definition = $catalog->items()[$endpoint] ?? null;

                if ($definition === null || ! $this->has($definition->field())) {
                    continue;
                }

                $new = (string) ($definition->cast($this->input($definition->field())) ?? '');
                $old = (string) (config($endpoint) ?? '');

                if ($new !== $old) {
                    $validator->errors()->add(
                        $secret->field(),
                        "“{$definition->label}” değişirken kayıtlı “{$secret->label}” yeni uca gönderilir; ".
                        'değeri yeniden girmeden bu değişiklik kaydedilemez.',
                    );

                    return;
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $labels = [];

        foreach (app(SettingsCatalog::class)->items() as $definition) {
            $labels[$definition->field()] = $definition->label;
        }

        return $labels;
    }

    /**
     * Doğrulanmış değerler, ayar anahtarlarına ve saklanacak tiplerine
     * çevrilmiş hâlde.
     *
     * @return array<string, string|int|bool|null>
     */
    public function settings(SettingsCatalog $catalog): array
    {
        $values = [];

        foreach ($this->submitted($catalog) as $key => $definition) {
            $values[$key] = $definition->cast($this->input($definition->field()));
        }

        return $values;
    }

    /**
     * Yalnızca GÖNDERİLEN ayarlar.
     *
     * Ekranda her bölüm kendi formudur; bir bölüm kaydedilirken diğerlerinin
     * alanları istekte hiç bulunmaz. Bütün katalog üzerinden yürünseydi
     * gönderilmeyen her ayar "boşaltıldı" sayılır ve tek bir kaydetme bütün
     * yapılandırmayı silerdi.
     *
     * Açık/kapalı kutularında gizli bir "0" alanı bulunduğu için işaretsiz
     * kutu da gönderilmiş sayılır; "kapatıldı" ile "dokunulmadı" karışmaz.
     *
     * @return array<string, SettingDefinition>
     */
    private function submitted(SettingsCatalog $catalog): array
    {
        $section = $this->section($catalog);

        return array_filter(
            $catalog->items(),
            fn (SettingDefinition $definition, string $key): bool => $this->has($definition->field())
                && in_array($key, $section, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Adresteki bölümün ayar anahtarları.
     *
     * İstek yalnızca BU bölümün alanlarını taşıyabilir. Sınır olmasaydı
     * "bakım" formuna izleme sistemi jetonu iliştirilebilir ve bölüm bazlı
     * yetki hiçbir şey ifade etmezdi.
     *
     * @return list<string>
     */
    private function section(SettingsCatalog $catalog): array
    {
        $group = $catalog->group((string) $this->route('section'));

        return $group === null
            ? []
            : array_map(fn (SettingDefinition $definition): string => $definition->key, $group['items']);
    }
}
