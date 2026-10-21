<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sunucu parolası döndürme
|--------------------------------------------------------------------------
|
| NYS'nin sunucuları KENDİNE AİTTİR: parolaları başka bir panelden çekilmez,
| burada değiştirilir. Sıra ve gerekçesi ServerPasswordRotator'da yazılı.
|
| Linux'ta SSH, Windows'ta WinRM kullanılır. Seçim işletim sisteminden yapılır,
| kayıttaki bağlantı protokolünden değil (bkz. DriverSelector).
|
*/

return [

    /*
     * Üretilen parolanın uzunluğu.
     *
     * Cihaz parolalarıyla aynı üreteç kullanılır (Ayarlar > Cihaz parolası
     * oradaki sınıfları belirler); buradaki yalnızca uzunluktur.
     */
    'parola_uzunlugu' => (int) env('SUNUCU_PAROLA_UZUNLUGU', 24),

    /*
     * SSH komut zaman aşımı.
     *
     * Sabit 15 saniye yetmiyor: yükü yüksek bir makinede `chpasswd` 15
     * saniyeyi aşabilir; tur "değiştiremedim" diyerek kapanır ama parola
     * makinede DEĞİŞMİŞ olur.
     */
    'ssh_zaman_asimi' => (int) env('SUNUCU_SSH_ZAMAN_ASIMI', 30),

    /*
     * Kayıt başına kilidin ömrü.
     *
     * İşin zaman aşımından uzun tutulur: işi öldüren bir zaman aşımı
     * `finally` bloğunu çalıştırmayabilir ve kilit ancak süresi dolunca düşer.
     */
    'kilit_saniye' => (int) env('SUNUCU_KILIT_SANIYE', 600),

    'winrm' => [
        'python' => env('SUNUCU_PYTHON', '/usr/bin/python3'),
        'runner' => env('SUNUCU_WINRM_RUNNER', resource_path('bin/winrm-runner.py')),
        'openssl_conf' => env('SUNUCU_OPENSSL_CONF', resource_path('bin/openssl-legacy.cnf')),

        /*
         * WinRM'İN KENDİ PORTU. Sunucu kaydındaki port RDP'ninkidir (3389) ve
         * WinRM oraya bağlanmaz; bu yüzden kayıttan okunmaz.
         */
        'port' => (int) env('SUNUCU_WINRM_PORT', 5985),
        'timeout' => (int) env('SUNUCU_WINRM_ZAMAN_ASIMI', 60),

        /*
         * Kurtarma hesabı açılsın mı.
         *
         * VARSAYILAN KAPALI: ölçüldü (Windows Server 2019) — yeni açılan yerel
         * yönetici hesabı UZAKTAN yönetici yetkisi alamıyor
         * (LocalAccountTokenFilterPolicy tanımsızken) ve kurtarma hesabı kanıt
         * aşamasında düşüp turu boşuna iptal ediyor. O kaydı 1 yapan
         * kurulumlarda açılabilir.
         */
        'kurtarma' => (bool) env('SUNUCU_WINRM_KURTARMA', false),
    ],
];
