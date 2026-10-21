<form method="POST" action="{{ $action }}"
      data-confirm="Kullanıcı kaydı kaydedilecek."
      data-confirm-title="Kullanıcıyı kaydet">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.panel title="Hesap">
        <div class="form-grid">
            <x-form.field name="name" label="Ad soyad">
                <x-form.input name="name" :value="$user?->name" required autofocus />
            </x-form.field>

            <x-form.field name="email" label="E-posta">
                <x-form.input name="email" type="email" :value="$user?->email" mono required />
            </x-form.field>

            <x-form.field name="password" label="Parola"
                          :hint="$user ? 'Boş bırakılırsa parola değişmez.' : 'En az 10 karakter, harf ve rakam içermeli.'">
                <x-form.input name="password" type="password" autocomplete="new-password" />
            </x-form.field>

            <x-form.field name="password_confirmation" label="Parola (tekrar)">
                <x-form.input name="password_confirmation" type="password" autocomplete="new-password" />
            </x-form.field>

            {{-- Rolü yalnızca yönetici belirler; sunucu da bunu doğrular. --}}
            @if (auth()->user()->isAdmin())
                <x-form.field name="role" label="Rol">
                    <x-form.select name="role" :options="$roleOptions" :selected="$user?->role?->uuid" />
                </x-form.field>
            @else
                <x-form.field name="role_readonly" label="Rol"
                              hint="Rolü yalnızca yönetici değiştirebilir.">
                    <x-form.input name="role_readonly" :value="$user?->role?->label" disabled />
                </x-form.field>
            @endif

            <x-form.field name="status" label="Durum">
                <x-form.select name="status" :options="$statusOptions" :selected="$user?->status?->value" />
            </x-form.field>
        </div>
    </x-ui.panel>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">{{ $submitLabel }}</button>
        <a href="{{ route('users.index') }}" class="btn btn--ghost">Vazgeç</a>
    </div>
</form>
