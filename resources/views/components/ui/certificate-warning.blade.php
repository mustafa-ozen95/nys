@if ($show)
    {{--
        Yalnızca yöneticiye: sertifikayı yenileyecek kişi odur, diğerleri
        için yapabilecekleri bir şey olmayan bir uyarı gürültüdür.

        Yenileme panelden yapılır; uyarı da oraya götürür.

        "Süre dolarsa tarayıcı paneli tümüyle açmaz" denmez: bu yalnızca
        HSTS açıkken doğrudur. HSTS varsayılan kapalıdır (bkz.
        config/security.php); süresi dolmuş sertifikada tarayıcı uyarı
        gösterip geçmeye izin verir.
    --}}
    <div class="alert alert--warning alert--spaced" role="alert">
        <strong>TLS sertifikasının süresi doluyor.</strong>
        {{ $status['kalan_gun'] }} gün kaldı ({{ $status['bitis'] }}).
        <a href="{{ route('settings.certificate') }}">Ayarlar → Sertifika</a>
        ekranından yeni tur başlatın: TXT kaydı orada görünür, DNS'e ekleyip
        onaylamanız yeter.
    </div>
@endif
