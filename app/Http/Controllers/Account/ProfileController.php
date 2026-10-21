<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Kullanıcının kendi hesabı.
 *
 * Yetkilendirme: herkes YALNIZCA kendi hesabını düzenler; kullanıcı oturumdan
 * gelir, adresten değil. Başkasının profiline giden bir yol olmadığı için
 * ayrıca bir yetki kuralı gerekmiyor.
 *
 * ROL, DURUM VE YETKİLER BURADA DEĞİŞMEZ. "Kendi profilini düzenle" yetkisi,
 * kendini yönetici yapma yetkisi değildir.
 */
class ProfileController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('auth')];
    }

    public function index(#[CurrentUser] $kullanici): View
    {
        return view('account.profile', ['kullanici' => $kullanici]);
    }

    public function update(Request $request, ActivityRecorder $recorder, #[CurrentUser] $kullanici): RedirectResponse
    {
        /*
         * E-POSTA BURADA DEĞİŞMEZ.
         *
         * Merkezî girişte (KYM) ilk eşleşme yerel hesabın e-postasıyla kurulur
         * (bkz. AccountMatcher). Kullanıcı kendi adresini serbestçe
         * değiştirebilseydi, henüz panele girmemiş bir meslektaşın kurumsal
         * adresini yazıp onun ilk KYM girişini ve rolünü devralabilirdi.
         * Adres yönetici tarafından kullanıcı ekranından yönetilir. İstekte
         * "email" gelse bile doğrulanmaz, yazılmaz.
         */
        $veri = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], attributes: ['name' => 'ad']);

        $kullanici->update($veri);

        return back()->with('status', 'Bilgileriniz kaydedildi.');
    }

    public function parola(Request $request, ActivityRecorder $recorder, #[CurrentUser] $kullanici): RedirectResponse
    {
        $veri = $request->validate([
            /* Mevcut parola şart: oturumu ele geçiren biri hesabı devralamasın. */
            'mevcut' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], attributes: ['mevcut' => 'mevcut parola', 'password' => 'yeni parola']);

        $kullanici->update(['password' => $veri['password']]);

        /*
         * Parola değişince DİĞER OTURUMLAR DÜŞER.
         *
         * Parola değiştirmenin başlıca sebebi "başkası girmiş olabilir"
         * şüphesidir; eski oturumlar açık kalsaydı değişiklik o kişiyi dışarı
         * atmazdı.
         */
        Auth::logoutOtherDevices($veri['password']);
        $request->session()->regenerate();

        $recorder->record($kullanici, 'updated', 'Kendi parolasını değiştirdi');

        return back()->with('status', 'Parolanız değiştirildi; diğer oturumlarınız kapatıldı.');
    }
}
