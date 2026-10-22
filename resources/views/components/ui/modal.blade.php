@props(['id', 'title', 'wide' => false, 'open' => false])

{{--
    Genel amaçlı pencere. Açmak için herhangi bir düğmeye
    data-modal-open="{{ $id }}" eklemek yeterlidir.

    :open ile SUNUCUDAN açılabilir. Kaldırılmış bir düzenleme sayfasının
    adresi listeye yönlendiğinde pencerenin kendiliğinden açılması gerekiyor;
    yer imi çalışmaya devam etsin diye.
--}}
<div class="modal" id="{{ $id }}" data-modal @unless ($open) hidden @endunless
     role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-header">
    <div @class(['modal__box', 'modal__box--wide' => $wide])>
        <header class="modal__header">
            <h2 class="modal__title" id="{{ $id }}-header">{{ $title }}</h2>
            <button type="button" class="modal__close" data-modal-close aria-label="Kapat">&times;</button>
        </header>

        {{ $slot }}
    </div>
</div>
