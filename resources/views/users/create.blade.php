<x-layout.app title="Yeni kullanıcı">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head title="Yeni kullanıcı" subtitle="Hesap oluşturduktan sonra yetkileri ayrıca tanımlayın." />

            @include('users.form')
        </div>
    </div>
</x-layout.app>
