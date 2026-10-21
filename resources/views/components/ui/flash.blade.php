{{--
    Ekranın bildirim bloğu: başarı, hata ve doğrulama mesajları.

    Aynı üç blok her sayfada elle yazılmaz. Biçim tek yerden gelmeli;
    aksi hâlde bir ekranda hata kırmızı, diğerinde gri olur.
--}}
@if (session('status'))
    <div class="alert alert--success gate__alert">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="alert alert--danger gate__alert">{{ session('error') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert--danger gate__alert">
        <ul class="alert__list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
