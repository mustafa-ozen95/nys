<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Çalışma zamanı ayarlarının okunması, yazılması ve config üzerine
 * bindirilmesi.
 *
 * Ayarın anahtarı config yolunun kendisidir; bindirme yapıldıktan sonra
 * config('zabbix.url') diyen her yer yeni değeri görür. Bu bilinçli bir
 * tercihtir: veritabanından ayar okuyan yeni bir çağrı biçimi öğrenmek
 * gerekmez ve bir ayar silindiğinde uygulama config dosyasındaki varsayılana
 * kendiliğinden döner.
 *
 * Değerler Redis'te süresiz önbelleklenir; her istekte tek bir okuma yapılır.
 * Kayıt anında önbellek düşürülür.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'settings:values';

    public function __construct(private SettingsCatalog $catalog) {}

    /**
     * Kayıtlı bütün ayarlar. Gizli olanlar çözülmüş hâlde döner.
     *
     * @return array<string, string|int|bool|null>
     */
    public function all(): array
    {
        /*
         * Önbellek erişilemezse ayarlar doğrudan veritabanından okunur.
         * Redis'in düşmesi panelin yapılandırmasını kaybetmesine — Zabbix
         * adresinin, commit süresinin varsayılana dönmesine — yol açmamalı.
         */
        try {
            return LegacyKeys::currentKeys(Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->read()));
        } catch (Throwable) {
            return $this->read();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Kayıtlı değerleri config üzerine bindirir.
     *
     * Tanımı olmayan (config/settings.php'den çıkarılmış) anahtarlar yok
     * sayılır: eski bir satır kalmışsa uygulamanın davranışını sessizce
     * değiştirmemeli.
     */
    public function apply(): void
    {
        /*
         * config:cache uygulamayı ayağa kaldırıp config dizisini dosyaya
         * yazar. Bindirme o sırada çalışırsa veritabanındaki değerler dosyaya
         * gömülür ve bir ayar silindiğinde varsayılana dönmez. Çalışma
         * anındaki bindirme zaten her istekte yapılıyor; orada atlanır.
         */
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }

        foreach ($this->all() as $key => $value) {
            if ($this->catalog->has($key)) {
                config([$key => $value]);
            }
        }

        /*
         * Saat dilimi config'e yazmakla değişmez: Laravel PHP'nin varsayılan
         * dilimini AÇILIŞTA bir kez kuruyor ve sonraki config değişikliği ona
         * ulaşmıyor. Ayarlanmış olsa bile bütün tarihler eski dilimde kalırdı.
         */
        $dilim = config('app.timezone');

        if (is_string($dilim) && $dilim !== '') {
            if ($dilim !== date_default_timezone_get()) {
                date_default_timezone_set($dilim);
            }

            /*
             * Gösterim dilimi de birlikte gider; APP_DISPLAY_TIMEZONE ile
             * bilerek ayrılmadıysa iki ayrı dilim ortaya çıkmasın.
             */
            if (env('APP_DISPLAY_TIMEZONE') === null) {
                config(['app.display_timezone' => $dilim]);
            }
        }
    }

    /**
     * Verilen ayarları yazar ve değişenlerin listesini döner.
     *
     * Yalnızca gerçekten değişenler saklanır; dokunulmamış ayar veritabanına
     * girmez ve config dosyasındaki varsayılana bağlı kalır.
     *
     * Gizli alanlarda boş değer "değiştirme" demektir; formda jeton
     * gösterilmediği için boş gönderim mevcut jetonu silmemelidir.
     *
     * @param  array<string, string|int|bool|null>  $values
     * @return list<string> değişen anahtarlar
     */
    public function save(array $values, ?User $editor = null): array
    {
        $changed = [];

        foreach ($values as $key => $value) {
            $definition = $this->catalog->find($key);

            if ($definition === null) {
                continue;
            }

            if ($definition->isSecret() && ($value === null || $value === '')) {
                continue;
            }

            /*
             * Yürürlükteki değerle aynıysa yazılmaz. Karşılaştırma kayıtlı
             * satırla değil CONFIG ile yapılır: kullanıcı formu olduğu gibi
             * kaydettiğinde, o ana kadar hiç dokunulmamış ayarların hepsi
             * veritabanına düşmemeli — düşerse config dosyasındaki
             * varsayılan bir daha asla geçerli olmaz.
             */
            if (config($key) === $value) {
                continue;
            }

            Setting::query()->updateOrCreate(
                ['key' => LegacyKeys::toStored($key)],
                ['value' => $this->encode($definition, $value), 'updated_by' => $editor?->id],
            );

            $changed[] = $key;
        }

        if ($changed !== []) {
            $this->forget();

            /*
             * Kaydedilen değer AYNI istekte de geçerli olsun.
             *
             * Bindirme normalde boot() içinde yapılır, yani değişiklik ancak
             * BİR SONRAKİ istekte görünürdü. Kaydeden isteğin kendisi eski
             * değeri okumaya devam ederdi: aynı istekte kuyruğa atılan bir
             * iş ya da hemen üretilen bir parola, kullanıcının az önce
             * değiştirdiği ayarı değil eskisini kullanırdı.
             */
            $this->apply();
        }

        return $changed;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Ayarın en son ne zaman ve kim tarafından değiştirildiği.
     *
     * @return array<string, array{at: Carbon, by: ?string}>
     */
    public function auditTrail(): array
    {
        /*
         * Rol İLİŞKİSİ BİLEREK YÜKLENMEZ. User::$with = ['role'] her sorguda
         * rolü de çekiyor; sütun listesi verilince role_id seçilmediği için
         * katı kip (Model::shouldBeStrict) "The attribute [role_id] ... was
         * not retrieved" ile düşer. Üretimde katı kip kapalı olduğu için
         * hata görünmez, yalnızca boş bir sorgu daha atılırdı.
         *
         * Burada gereken tek şey ad; ilişki hiç açılmaz.
         */
        return Setting::query()
            ->with(['editor' => fn ($sorgu) => $sorgu->select('id', 'name')->without('role')])
            ->get()
            ->mapWithKeys(fn (Setting $setting): array => [
                LegacyKeys::toCurrent($setting->key) => ['at' => $setting->updated_at, 'by' => $setting->editor?->name],
            ])
            ->all();
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function read(): array
    {
        /*
         * Tablo henüz yoksa (ilk kurulum, migration sırası) uygulama
         * çalışmaya devam eder ve config dosyalarındaki varsayılanlar
         * geçerli olur. Ayar okumak uygulamayı ayağa kalkamaz hâle
         * getirmemeli.
         */
        try {
            $rows = Setting::query()->get(['key', 'value']);
        } catch (QueryException) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $anahtar = LegacyKeys::toCurrent($row->key);
            $definition = $this->catalog->find($anahtar);

            if ($definition === null) {
                continue;
            }

            $values[$anahtar] = $this->decode($definition, $row->value);
        }

        return $values;
    }

    private function encode(SettingDefinition $definition, string|int|bool|null $value): ?string
    {
        $json = json_encode($value);

        return $definition->isSecret() ? Crypt::encryptString((string) $json) : $json;
    }

    private function decode(SettingDefinition $definition, ?string $stored): string|int|bool|null
    {
        if ($stored === null) {
            return null;
        }

        if ($definition->isSecret()) {
            /*
             * APP_KEY değiştiyse çözülemez. Uygulamayı durdurmak yerine ayar
             * yokmuş gibi davranılır: config dosyasındaki varsayılan geçerli
             * olur ve kullanıcı ekrandan yeniden girer.
             */
            try {
                $stored = Crypt::decryptString($stored);
            } catch (DecryptException) {
                Log::warning("Ayar çözülemedi: {$definition->key}");

                return null;
            }
        }

        return json_decode($stored, true);
    }
}
