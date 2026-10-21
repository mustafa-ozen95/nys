<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Settings\SettingsCatalog;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Panelden değiştirilen ayarları config üzerine bindirir.
 *
 * Bindirme boot() içinde yapılır, register() içinde DEĞİL: register
 * aşamasında önbellek ve veritabanı bağlantıları henüz kurulmamıştır ve
 * onları o anda çözmeye çalışmak uygulamayı ayağa kaldırmaz. Servisler
 * config'i çalışma anında okuduğu için boot yeterince erkendir.
 *
 * Değerler Redis'te önbelleklidir: istek başına tek okuma yapılır. Ayar
 * tablosu yoksa (ilk kurulum, migration sırası) hiçbir şey bindirilmez ve
 * config dosyalarındaki varsayılanlar geçerli kalır.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsCatalog::class);
        $this->app->singleton(SettingsRepository::class);
    }

    public function boot(SettingsRepository $settings): void
    {
        $settings->apply();
    }
}
