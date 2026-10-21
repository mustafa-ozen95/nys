@props(['record'])

{{--
    Kaydın sürüm damgası. Gönderim geldiğinde kayıt bu andan sonra
    değiştiyse işlem yapılmaz; bkz. EnsureRecordIsUnchanged.

    Yalnızca DÜZENLEME formlarında bulunur: yeni kayıt oluştururken
    karşılaştırılacak bir sürüm yoktur.
--}}
<input type="hidden" name="record_version" value="{{ $record->updated_at?->getTimestamp() }}">
