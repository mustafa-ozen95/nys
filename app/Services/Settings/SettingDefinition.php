<?php

declare(strict_types=1);

namespace App\Services\Settings;

use BackedEnum;

/**
 * config/settings.php içindeki tek bir ayar tanımı.
 *
 * Ekranın alan türü, doğrulama kuralı ve tip çevrimi hep buradan okunur;
 * üç yerde ayrı ayrı tanımlanıp birbirinden ayrışmaz.
 */
readonly class SettingDefinition
{
    /**
     * @param  class-string<BackedEnum>|null  $enum
     * @param  list<string>  $rules
     * @param  list<string>  $binds
     */
    private function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public string $type,
        public ?string $unit,
        public ?string $enum,
        /** @var array<string,string>|null Enum yerine doğrudan verilen liste. */
        public ?array $secenekler,
        public bool $mono,
        public array $rules,
        /** @var list<string> Bu sırrın kimlik doğruladığı uç ayarlar (bkz. SettingsRequest). */
        public array $binds = [],
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            key: $key,
            label: (string) $definition['label'],
            hint: $definition['hint'] ?? null,
            type: (string) ($definition['type'] ?? 'string'),
            unit: $definition['unit'] ?? null,
            enum: $definition['enum'] ?? null,
            secenekler: $definition['options'] ?? null,
            mono: (bool) ($definition['mono'] ?? false),
            rules: $definition['rules'] ?? [],
            binds: $definition['binds'] ?? [],
        );
    }

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Form alanı adı. Nokta HTML'de dizi indeksi gibi ayrıştırıldığı için
     * alt çizgiye çevrilir; geri çevrim SettingsRequest'te yapılır.
     */
    public function field(): string
    {
        return str_replace('.', '__', $this->key);
    }

    /**
     * Ekranda gösterilecek seçenekler. Yalnızca select alanları için.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        // Enum yerine düz liste verilebilir: saat dilimi gibi yüzlerce
        // seçeneği olan alanlar için enum yazmak anlamsız olurdu.
        if ($this->secenekler !== null) {
            return $this->secenekler;
        }

        if ($this->enum === null) {
            return [];
        }

        $options = [];

        foreach ($this->enum::cases() as $case) {
            $options[(string) $case->value] = method_exists($case, 'label') ? $case->label() : (string) $case->value;
        }

        return $options;
    }

    /**
     * Gelen form değerini saklanacak tipe çevirir.
     */
    public function cast(mixed $value): string|int|bool|null
    {
        return match ($this->type) {
            'boolean' => (bool) $value,
            'integer' => $value === null || $value === '' ? null : (int) $value,
            default => $value === null || $value === '' ? null : (string) $value,
        };
    }

    /**
     * Doğrulama kuralları. Tür kuralı tanımdaki kurallara eklenir; her ayarda
     * elle tekrarlanmaz.
     *
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $rules = match ($this->type) {
            'boolean' => ['boolean'],
            'integer' => ['integer'],
            'select' => ['string'],
            default => ['nullable', 'string'],
        };

        if ($this->type === 'select' && ($this->enum !== null || $this->secenekler !== null)) {
            $rules[] = 'in:'.implode(',', array_keys($this->options()));
        }

        return array_values(array_unique([...$rules, ...$this->rules], SORT_REGULAR));
    }
}
