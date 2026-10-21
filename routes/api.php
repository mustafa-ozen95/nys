<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AddressBlockApiController;
use App\Http\Controllers\Api\IdentityLogoutController;
use App\Http\Controllers\Api\IdentityRefreshController;
use App\Http\Controllers\Api\ServerApiController;
use App\Http\Controllers\Api\SwitchApiController;
use App\Http\Controllers\Api\SwitchBgpApiController;
use App\Http\Controllers\Api\SwitchIpAddressApiController;
use App\Http\Controllers\Api\SwitchPortApiController;
use App\Http\Controllers\Api\SwitchRouteApiController;
use App\Http\Controllers\Api\SwitchVlanApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Kimlik doğrulaması yalnızca jetonladır ("auth:api"); oturum çerezi kabul
| edilmez. Jeton sahibi adına konuşur ve onu AŞAMAZ: yetki hem jetonun kendi
| listesinden hem de sahibinin panel yetkilerinden geçer
| (bkz. TokenAbilityGate).
|
| Sürüm adresin parçasıdır: yarın alan adı değiştiğinde eski istemciler
| kırılmasın diye "v1" sabit kalır ve kırıcı değişiklik "v2" açar.
|
| Hız sınırı JETON başınadır, IP başına değil: aynı IP'den gelen iki jeton
| birbirini yavaşlatmamalı. Bunun için sınır kimlik doğrulamasından SONRA
| uygulanır — jeton ancak o zaman bilinir.
|
| Kimliği DOĞRULANAMAYAN istek ise ThrottleApiClients ile, "api" grubunda
| sınırlanır. Buraya "throttle:..." yazmak İŞE YARAMAZ: Laravel ara katmanları
| önceliğe göre sıralıyor ve Authenticate, ThrottleRequests'ten önce geliyor —
| listede önüne yazılsa bile auth önce çalışıp isteği 401 ile bitiriyor.
*/

Route::middleware(['auth:api', 'throttle:api'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('switches', [SwitchApiController::class, 'index'])->name('switches.index');
        Route::get('switches/{switch}', [SwitchApiController::class, 'show'])->name('switches.show');

        /*
         * Alt kayıtlar üst kayıt üzerinden çözülür (scopeBindings): A
         * switchinde yetkisi olan biri B switchinin portunu okuyamaz.
         */
        Route::get('switches/{switch}/ports', SwitchPortApiController::class)
            ->name('switches.ports');
        Route::get('switches/{switch}/vlans', SwitchVlanApiController::class)
            ->name('switches.vlans');
        Route::get('switches/{switch}/ip-addresses', SwitchIpAddressApiController::class)
            ->name('switches.ip-addresses');
        Route::get('switches/{switch}/routes', SwitchRouteApiController::class)
            ->name('switches.routes');
        Route::get('switches/{switch}/bgp-neighbors', SwitchBgpApiController::class)
            ->name('switches.bgp-neighbors');

        /*
         * SUNUCULAR.
         *
         * Panel hem switchleri hem sunucuları yönetiyor; jeton ekranı ikisi
         * için de yetki vermeye izin veriyordu ama yalnızca switchlerin ucu
         * vardı — yani API tutmadığı bir söz veriyordu.
         *
         * Bağlantı parolası ve kullanıcı adı DÖNMEZ: ikisi birlikte sunucuya
         * giriş demek. Parola okumak isteyen kasanın kendi ucunu kullanır;
         * orada ayrı yetki ve her okuma için denetim kaydı var.
         */
        Route::get('servers', [ServerApiController::class, 'index'])->name('servers.index');
        Route::get('servers/{server}', [ServerApiController::class, 'show'])->name('servers.show');

        /*
         * ADRESLEME.
         *
         * "Hangi ipler kullanılıyor, hangileri boşta" sorusunun cevabı
         * yalnızca ekranda duruyordu; envanterini başka bir yerde tutan
         * herkes dışarıda kalıyordu. Çakışmayı önlemenin yolu bu bilgiyi
         * paylaşmaktan geçiyor.
         *
         * Adres dökümü AYRI UÇ: liste ucunda dönseydi her blok için adres
         * adres tarama yapılır, yüzlerce bloklu bir kurulumda tek istek
         * dakikalar sürerdi. Panelin ekranı da aynı ayrımı yapıyor.
         */
        Route::get('addressing/blocks', [AddressBlockApiController::class, 'index'])
            ->name('addressing.blocks.index');
        Route::get('addressing/blocks/{addressBlock}', [AddressBlockApiController::class, 'show'])
            ->name('addressing.blocks.show');
        Route::get('addressing/blocks/{addressBlock}/hosts', [AddressBlockApiController::class, 'hosts'])
            ->name('addressing.blocks.hosts');
    });

/*
 * MERKEZDEN GELEN "bu kimlik değişti" bildirimi.
 *
 * Jeton gerektirmez: kimlik doğrulaması istek gövdesinin HMAC imzasıdır
 * (istemci sırrıyla). Gelen veriye güvenilmez; panel doğruyu merkezden kendi
 * sorar (bkz. IdentityRefreshController).
 *
 * Hız sınırı var: imza tutmasa bile bu uç merkeze bir istek attırabilir.
 */
Route::post('kimlik/tazele', IdentityRefreshController::class)
    ->middleware('throttle:60,1')
    ->name('api.kimlik.tazele');

/* MERKEZDEN "bu kişi çıkış yaptı" — ortak çıkış (bkz. IdentityLogoutController). */
Route::post('kimlik/cikis', IdentityLogoutController::class)
    ->middleware('throttle:60,1')
    ->name('api.kimlik.cikis');
