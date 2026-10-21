<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bakım
|--------------------------------------------------------------------------
| Sınırsız büyüyen tek tablo etkinlik kayıtlarıdır; oturum açma, cihaza yazma
| ve her CRUD işlemi oraya düşer.
|
| İKİ AYRI SÜRE vardır çünkü iki ayrı zincir vardır:
|
|   denetim (audit)  — oturum açma, CRUD, cihaza yazma. Az satır, yüksek
|                      değer; uzun saklanır.
|   erişim (access)  — sayfa açma ve form gönderme. Hacmin neredeyse tamamı;
|                      denetim sorusuna "kim neye baktı" diye cevap verir ama
|                      aynı süre boyunca tutulması tabloyu şişirir.
|
| Erişim kayıtları zincir DIŞINA atılmaz; kendi zincirlerinde korunur ve
| kendi başlarından budanır (bkz. ActivityChain).
|
| Sıfır verilirse o zincire dokunulmaz.
*/

return [

    'activity_retention_days' => (int) env('ACTIVITY_RETENTION_DAYS', 365),

    'access_retention_days' => (int) env('ACCESS_RETENTION_DAYS', 180),
];
