<x-layout.app :title="$user->name.' düzenle'">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head :title="$user->name" :subtitle="$user->email">
                <x-slot:actions>
                    @can('managePermissions', $user)
                        <a href="{{ route('users.permissions', $user) }}" class="btn btn--sm">Yetkiler</a>
                    @endcan
                </x-slot:actions>
            </x-ui.page-head>

            @include('users.form')
        </div>
    </div>
</x-layout.app>
