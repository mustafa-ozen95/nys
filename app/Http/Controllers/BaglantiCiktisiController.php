<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Services\Disa\BaglantiCiktisi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Sunucu ve switchlerin bağlantı bilgilerini Excel dosyası olarak verir.
 *
 * Kuralların tamamı ve gerekçesi BaglantiCiktisi'nde. Burada duran tek karar
 * şudur: dosya TARAYICIYA İNER, sunucuda bir yere yazılmaz. Geçici bir
 * dizinde bırakılan çıktı, kimsenin bakmadığı bir yerde duran açık parola
 * listesi olurdu.
 *
 * Adres POST'tur: GET olsaydı tarayıcı geçmişine, sunucu erişim kaydına ve
 * bağlantı ön yüklemesine takılırdı. Gerekçeyi RequireActionReason ara katmanı
 * zaten zorunlu kılıyor.
 *
 * KAPI İKİ YETKİDEN BİRİYLE AÇILIR: yalnızca sunucularda yetkisi olan biri de
 * çıktı alabilmeli, yalnızca switchlerde olan da. Hangi satırın dosyaya
 * gireceğine tek tek export() karar veriyor.
 */
class BaglantiCiktisiController extends Controller implements HasMiddleware
{
    /** Dakikada kaç çıktı: tek istek bütün envanteri dosyaya yazabilir. */
    private const DAKIKADA = 3;

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return ['throttle:'.self::DAKIKADA.',1'];
    }

    public function __invoke(Request $request, BaglantiCiktisi $ciktisi): Response|RedirectResponse
    {
        $kullanici = $request->user();

        $sunucularVar = $kullanici->can('exportAny', Server::class);
        $switchlerVar = $kullanici->can('exportAny', NetworkSwitch::class);

        abort_unless($sunucularVar || $switchlerVar, 403);

        $veri = $request->validate([
            /*
             * Kapsam: hangi tabloların yazılacağı. Sunucu ekranından gelen
             * çıktı switchleri de taşısaydı, kullanıcı istemediği bir şeyi
             * indirmiş olurdu.
             */
            'kapsam' => ['required', 'in:sunucular,switchler,hepsi'],
            'q' => ['nullable', 'string', 'max:190'],
        ]);

        $arama = trim((string) ($veri['q'] ?? '')) ?: null;
        $kapsam = $veri['kapsam'];

        $sunucular = $sunucularVar && $kapsam !== 'switchler'
            ? Server::query()
                ->visibleTo($kullanici)
                ->search($arama)
                ->orderBy('short_name')
                ->get()
            : collect();

        $switchler = $switchlerVar && $kapsam !== 'sunucular'
            ? NetworkSwitch::query()
                ->visibleTo($kullanici)
                ->search($arama)
                ->with('credentials')
                ->orderBy('hostname')
                ->get()
            : collect();

        $sonuc = $ciktisi->uret(
            $sunucular,
            $switchler,
            $kullanici,
            (string) $request->input('reason'),
        );

        if ($sonuc['yazilan'] === 0) {
            return back()->with('error', 'Çıktıya alınacak bağlantı bilgisi yok: '.$this->elenenler($sonuc).'.');
        }

        $ek = $this->elenenler($sonuc);
        $ek = $ek === '' ? '' : '; '.$ek;

        return response($sonuc['icerik'])
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$sonuc['ad'].'"')
            ->header('X-Nys-Ozet', $sonuc['yazilan'].' satır yazıldı'.$ek)
            /* Açık parola listesi hiçbir ara sunucuda ve tarayıcıda durmamalı. */
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Elenenleri SEBEBİYLE anlatır.
     *
     * "Atlandı" demek yetmiyor: yetkinin yetmemesi ile kaydın zaten parolasız
     * durması bambaşka iki durum ve kullanıcının yapacağı şey de ikisinde
     * başka.
     *
     * @param  array{yazilan: int, yetkisiz: int, parolasiz: int}  $sonuc
     */
    private function elenenler(array $sonuc): string
    {
        $parcalar = [];

        if ($sonuc['parolasiz'] > 0) {
            $parcalar[] = $sonuc['parolasiz'].' kaydın parolası yok';
        }

        if ($sonuc['yetkisiz'] > 0) {
            $parcalar[] = $sonuc['yetkisiz'].' kayıtta çıktı yetkiniz yok';
        }

        return implode(', ', $parcalar);
    }
}
