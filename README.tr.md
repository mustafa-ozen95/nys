# NYS — Ağ Yönetim Sistemi

[English](README.md) · **Türkçe**

Juniper (JunOS) switch'leri envanterleyen, ölçen ve **cihaza NETCONF ile
yazan** panel. Yanında sunucu envanteri, IP adres yönetimi (IPAM), prefix
anons talepleri, uzak oturum (SSH/RDP) ve denetim kaydı taşır.

Kardeş projeler: EYS (parola kasası) ve Kimlik Yönetim Merkezi (kimlik sağlayıcı).

Neyin **ne ölçüde** desteklendiği: [`DESTEK.md`](DESTEK.md).

## Cihaza yazmanın sırası

```
istek → doğrulama (senkron)
     → aynı kayda ikinci yazma var mı?   → varsa reddedilir
     → yazma işi kaydı (kuyruğa)
     ──────────────────────────────────────────────
     oturum 1:  lock-configuration
                compare rollback 0   → BOŞ olmalı
                load-configuration   (merge / override / set)
                compare rollback 0   → fark BOŞSA commit hiç atılmaz
                commit confirmed <N dakika>
                unlock
     oturum 2:  lock; commit; unlock          ← onay
     ──────────────────────────────────────────────
     → cihazın kabul ettiği fark denetim kaydına yazılır
```

**Geri alınabilir commit** güvenlik ağıdır: ikinci oturum onaylamazsa cihaz
kendini eski hâline döndürür. Kilit yükleme, commit ve onayın tamamını kapsar
— Junos'ta bekleyen bir `commit confirmed` varken başkasının commit'i onu da
onaylar. Ayrı `commit check` atılmaz: ELS'te on saniyeyi aşabilir ve
`confirmed` zaten aynı doğrulamayı yapar.

## İki JunOS lehçesi

Eski (pre-ELS) ve yeni (ELS) cihazlar aynı şeyi farklı söyler: L3 arayüz
`vlan` mı `irb` mi, hız değerleri nasıl yazılır, analyzer kökü ne. Panel bunu
tek yerde toplar ve **şema sondasıyla** öğrenir: özel bir aday yapılandırmaya
deneme yükü basılır, sözdizimi hatası verirse ELS, geçerse pre-ELS. Sonda
commit atmaz. Tanımadığı modeli uydurmaz; ekranda "Bilinmiyor — ELS öncesi
varsayılıyor" yazar.

Panelin modellemediği cihaz satırları **korunur**: okunamayan kısım
`residual_xml` olarak saklanır ve geri yazılır. Panel bilmediği bir şeyi
silmez.

## Ölçüm

Üç kaynak: **Zabbix** (hız, dupleks, anlık trafik, CPU/bellek/sıcaklık,
geçmiş), **NETCONF** (arayüz listesi ve yapılandırma — asıl gerçek), **SNMP**
(IP adresleri, commit geçmişi — yedek).

- **Devre kesici:** hata veren kaynak bir süre denenmez; olmasaydı kesinti
  anında her sayfa zaman aşımı kadar donardı.
- **"Bilinmiyor" ≠ "çevrimdışı":** hiçbir kaynak yanıt veremezse durum
  bilinmiyor olur ve kayıtlı ölçümler silinmez.
- **Bir HTTP isteği ölçüm için cihaza bağlanmaz.** Ölçümler kayıtlı veriden
  gelir; yoksa her sayfa switch sayısı kadar cihaz gidiş dönüşü beklerdi.

## Yetki

Her ekran bir kaynaktır; on aksiyon vardır (`view`, `create`, `update`,
`delete`, `connect`, `configure`, `provision`, `secret`, `rotate`, `export`). Cihaz nesnelerinde
(port, VLAN, firewall…) kayıtlar **switch'lerdir**: "şu switch'in VLAN'larını
düzenleyebilir" denebilir.

Rol canlı okunur, kişinin satırları istisnadır. Karar sırası: kişinin kayda
özel satırı → kişinin kaynak geneli satırı → rolün yetkisi → reddet. Cihaza
yazan aksiyonlar ayrıca **NETCONF kimliği** ister ve bu koşulu yönetici bile
atlayamaz.

Durumu değiştiren her istek **gerekçe** ister; kontrol sunucudadır ve gerekçe
zincire girdiği için sonradan değiştirilemez.

## Kurulum

Konteyner kurulumu deponun kökündeki `docker-compose.yml` ile (Compose standardı:
MariaDB ve redis ayrı servis, panel yalnız HTTP; her platformda aynı dosya).
Ayrıntı ve değişkenler: `docker/README.md`, `.env.example`.

```bash
cp .env.example .env && docker compose up -d --build   # kur / güncelle
docker compose logs -f panel                           # kayıtlar
```

Aşağısı konteynersiz (site
kullanıcılı) kurulumu anlatır:

```bash
composer setup     # geliştirme
composer deploy    # üretim (no-dev, optimize, migrate, build)
```

Bütün `artisan` ve `npm` komutları **site kullanıcısıyla** çalıştırılır:

```bash
sudo -u <site-kullanicisi> php artisan ...
```

Root ile çalıştırılırsa `storage/framework/views`, `bootstrap/cache` ve
`public/build` root'a geçer ve panel tamamen 500 verir. Kurtarma:

```bash
chown -R <site-kullanicisi>:<site-kullanicisi> storage bootstrap/cache public/build
sudo -u <site-kullanicisi> php artisan optimize
```

### Cihaza yazma kuyruğu

Yazma işleri **ayrı bir kuyrukta** koşar ve işçisi systemd birimidir. Birim
kurulmadan yazma işleri hiç çalışmaz ve ekranda "cihaza yazılıyor" hiç
değişmez:

```bash
sudo cp deploy/nms-switch-write.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now nms-switch-write
```

Diğer kuyruk işçileri zamanlayıcıdan doğar; ayrıca supervisor gerekmez.

## Testler

```bash
composer test                                        # tam takım
sudo -u <site-kullanicisi> env PLAYWRIGHT_BROWSERS_PATH=<kurulum-dizini>/.cache \
  npx playwright test --reporter=list                # üç genişlikte görsel
```

Cihazlar sahte bir Junos ile taklit edilir: gelen RPC'ye bakıp cevap verir,
yani testler çağrı sırasına bağlı değildir.

## Kurallar

Kod standartları `CLAUDE.md`'de; alan bilgisi `.ai/rules/` altındaki
dosyalarda (NETCONF, JunOS lehçesi, yetki, adresleme, anons, parolalar, arayüz,
testler). Yeni bir ekran açmadan önce ilgili kural dosyası okunur.
