# Laravel Development Standards

## ⛔ TASARIMA DOKUNMADAN ÖNCE: DESIGN.md

Bu panelin arayüzü ürün ailesindeki kardeş panellerle ORTAKTIR. CSS dosyaları, sayfa kabuğu, üst
çubuk, giriş ekranı ve `ui` bileşenleri byte byte aynıdır; biri değişecekse
hepsi birlikte değişir.

**Arayüzde bir şey değiştirmeden önce `DESIGN.md` dosyasını oku.** Yeni
sınıf/renk/ölçü icat etme, var olan bileşenleri kullan, tek panelde
"iyileştirme" yapma.


## Genel Prensipler

- Laravel Best Practices takip edilmeli.
- SOLID prensiplerine uyulmalı.
- DRY (Don't Repeat Yourself) uygulanmalı.
- KISS prensibi uygulanmalı.
- Gereksiz abstraction oluşturulmamalı.
- Laravel'in kendi özellikleri tercih edilmeli, custom çözüm son seçenek olmalı.

## Controller Kuralları

Controller sadece request'i yönetmeli. Inline işlem olmamalı.

Controller içinde **bulunmamalı**:

- Business logic
- SQL sorgusu
- Büyük foreach blokları
- Validation (Form Request kullanılmalı)

## Form Request

- Tüm validation'lar Form Request içinde olmalı.

## Policy

- Authorization asla Controller içinde yazılmamalı.

## Model Kuralları

Model içinde **bulunabilir**:

- Relationships
- Scopes
- Accessors
- Casts

Model içinde **bulunmamalı**:

- Mail gönderme
- PDF üretme
- API çağırma
- Büyük business logic

## Eloquent

Raw SQL son seçenek olmalı. Tercih sırası:

1. Relationship
2. Scope
3. Query Builder
4. Raw SQL

N+1 sorgularına izin verilmemeli. Gerekli yerlerde her zaman:

- `with()`
- `load()`
- `loadMissing()`

kullanılmalı.

## Route

Route dosyasında business logic olmayacak. Route şu şekilde tanımlanmalı:

```php
Route::resource(...);
// veya
Route::controller(...);
```

## Blade

Blade içinde PHP kodu yazılmamalı.

Yanlış:

```blade
@php
@endphp
```

Yanlış:

```blade
<?php
```

Yanlış: `@if(...)` / `@foreach(...)` içinde uzun hesaplamalar.

Blade sadece görüntü oluşturmalı ve hazır veri göstermeli.

## ViewModel / Data

View'a gönderilen veri Controller veya Service tarafından hazırlanmalı.

Blade içinde yapılmamalı:

- Filtreleme
- Sıralama
- Hesaplama
- Collection işlemleri

## JavaScript

Business logic JS içinde olmamalı. JS sadece şu işleri yapmalı:

- DOM
- AJAX
- Event
- UI

Laravel tarafındaki kuralları JS tekrar etmemeli.

## AJAX

AJAX endpointleri JSON döndürmeli:

```php
return response()->json(...);
```

## API

API Resource kullanılmalı. Controller içinde:

```php
return UserResource::collection(...);
```

tercih edilmeli.

## Mass Assignment

- `fillable` veya `guarded` doğru kullanılmalı.
- Hiçbir model korumasız bırakılmamalı.

## Transactions

Birden fazla tablo güncelleniyorsa `DB::transaction()` kullanılmalı.

## Queue

Queue'ya alınmalı:

- Mail
- Bildirim
- Büyük import
- Export
- PDF
- Uzun süren işlemler

## Events

Bir işlem başka işlemleri tetikliyorsa Event kullanılmalı.

Örneğin:

```
OrderCreated
  ↓
  SendMail
  CreateInvoice
  NotifyAdmin
```

## Config

Magic string kullanılmamalı. Şunlar config/env dosyasında olmalı:

- API URL
- API Key
- Timeout
- Limit
- Dosya yolları

## Exception

- try/catch gereksiz kullanılmamalı.
- Laravel Exception Handler tercih edilmeli.

## Logging

- `dd()`, `dump()`, `var_dump()` production kodunda bulunmamalı.
- Log gerekiyorsa `Log::info()` / `Log::error()` kullanılmalı.

## Kod Stili

- PSR-12 standardı uygulanmalı.
- Anlamlı değişken isimleri kullanılmalı.
- Fonksiyonlar tek sorumluluğa sahip olmalı.
- Bir metod mümkün olduğunca 20-30 satırı geçmemeli.
- Tekrarlayan kodlar ortaklaştırılmalı.
- Vite kullanılmalı, responsive olmalı.

## Naming Convention

| Tür | Örnek |
| --- | --- |
| Controller | `PostController` |
| Service | `PostService` |
| Policy | `PostPolicy` |
| Request | `StorePostRequest` |
| Resource | `PostResource` |
| Event | `PostCreated` |
| Listener | `SendPostNotification` |
| Job | `ProcessPostJob` |

Laravel isimlendirme standartları korunmalı.

## Frontend

- Blade sadece render işlemi yapmalı.
- PHP kodu Blade içinde yazılmamalı.
- Inline JavaScript mümkün olduğunca kullanılmamalı.
- Inline CSS kullanılmamalı.
- Assetler Vite üzerinden yönetilmeli.

### Vite Yapısı

- Entrypoint'ler: `resources/css/app.css` ve `resources/js/app.js`, layout'ta `@vite([...])` ile yüklenir.
- `resources/css/app.css` sadece import barındırır; kurallar `base.css` ve `components/*.css` içinde.
- Yeni bir CSS bileşeni `resources/css/components/` altına eklenir ve `app.css`'ten import edilir.
- Geliştirme: `npm run dev` (veya `composer run dev`), production: `npm run build`.
- Blade içine `<style>` veya `<script>` blokları eklenmez; ilgili CSS/JS dosyasına taşınır.

## Güvenlik (Öncelikli)

Güvenlik bu projede diğer başlıklardan önce gelir. Bir özellik güvenli değilse
tamamlanmış sayılmaz. Aşağıdaki kurallar her yeni ekranda tekrar kontrol
edilir.

### Kimlik ve yetki

- Her rota ya `auth` altında olacak ya da neden olmadığı yorumla açıklanacak.
- Her controller `HasMiddleware` ile `can:` tanımlar veya `authorize()` çağırır.
  Yetkisiz bırakılmış tek bir controller bile kabul edilmez.
- Dış dünyaya etki eden işlemler (e-posta gönderme, uzak oturum açma) ayrı bir
  yetki ister; "görüntüle" yetkisi bunları kapsamaz.
- Kullanıcı rolünü yalnızca yönetici değiştirebilir. "Kullanıcıları düzenle"
  yetkisi, kendini yönetici yapma yetkisi değildir.

### Nesne erişimi (IDOR)

- İç içe rotalarda alt kayıt üst kayıt üzerinden çözülür: `scopeBindings()`.
  Bunsuz A switchinde yetkisi olan biri B switchinin kaydını değiştirebilir.
- Liste sorguları `visibleTo($user)` ile daraltılır.
- Sıralı birincil anahtar dışarı çıkmaz; URL, sorgu parametresi, form değeri ve
  DOM kimliği uuid taşır. Ayrıntı: `.ai/rules/junos.md` ("Terimler de uuid taşır").

### Uzak oturumlar

- ttyd ve Guacamole proxy'leri nginx `auth_request` ile korunur; ttyd'nin kendi
  kimlik doğrulaması yoktur. Ayrıntı ve nginx tuzakları:
  `docker/rootfs/etc/nginx/conf.d/nsm.conf` (yorumlar) ve `docker/README.md`.
- Oturum jetonu kısa ömürlüdür, sahibi kayıtlıdır ve yetki her istekte
  yeniden sorulur.

### Veri

- Parola, community ve anahtar alanları `encrypted` cast ile saklanır ve
  `$hidden` içindedir; hiçbir ekranda ve JSON yanıtında basılmaz.
- Toplu atama `$fillable` ile sınırlıdır; `$request->all()` kullanılmaz.
- Oturum çerezi `Secure` işaretlidir.

### Denetim

Yeni bir ekran veya uç eklendiğinde şunlar çalıştırılır ve çıktısı boş olmalıdır:

```
# Yetkisiz controller. İstisna bilinçliyse sınıf docblock'una
# "Yetkilendirme: <neden>" satırı yazılır; denetim onu kabul eder.
for f in app/Http/Controllers/*.php app/Http/Controllers/*/*.php; do
  grep -q "middleware()\|authorize(\|Yetkilendirme:" $f || echo "$f"
done

# auth dışı rota. API uçları "auth:api" taşır; ikisi de kabul edilir.
php artisan route:list --except-vendor --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    if not any(m.startswith('auth') for m in (r.get('middleware') or [])):
        print(r['uri'], r.get('name'))
"
```

Her düzeltme bir regresyon testiyle kilitlenir: açığın kapandığını değil,
bir daha açılamayacağını kanıtlarız.

## Riskli İşlemler (Onay Zorunlu)

Riskli işlemler **açıkça söylenmediği sürece asla yapılmaz**; her durumda önce onay alınır.

Onay gerektiren işlemler:

- Veritabanı değişiklikleri: `migrate`, `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed`
- Veri silme veya toplu güncelleme (`delete`, `truncate`, toplu `update`)
- Dosya/dizin silme veya üzerine yazma
- `.env`, config ve deploy ayarlarında değişiklik
- Git işlemleri: `commit`, `push`, `reset --hard`, `checkout -f`, branch silme
- Composer/NPM paket ekleme, kaldırma veya güncelleme
- Cache/queue/storage temizleme komutları (`optimize:clear`, `queue:flush` vb.)
- Production ortamına yönelik her türlü işlem
- Dış servislere gerçek istek gönderen işlemler (mail, ödeme, SMS, webhook)

Kural: Emin değilsen **yapma, sor**. Onay tek bir işlem için geçerlidir, sonraki işlemleri kapsamaz.

## Kod Üretim Kuralları (Claude Code İçin)

- Laravel'in yerleşik özelliklerini yeniden icat etme.
- Yeni bir dosya oluşturmadan önce mevcut yapıyı incele ve yeniden kullan.
- Gereksiz paket önerme.
- Gereksiz helper fonksiyonları oluşturma.
- Her değişiklik mevcut mimariyle uyumlu olsun.
- Kod üretmeden önce mevcut proje yapısını analiz et.
- Oluşturulan kod PSR-12 uyumlu olsun.
- Her yeni özellikte güvenlik (Policy, Validation, CSRF, Mass Assignment) kontrolü yap.
- Performans açısından N+1 sorgularını önle ve gereksiz veritabanı sorgularından kaçın.
- Gerekli durumlarda Feature ve Unit testleri de üret.
- Görsel testleri atlama; Playwright + Chromium ile görsel testleri de yap.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
