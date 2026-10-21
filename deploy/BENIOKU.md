# Kurulum notları

## Cihaza yazma kuyruğu işçisi

Panel cihaza yazmayı kuyruğa alır: bir yazma ELS switchte bir dakikaya
yaklaşabilir ve istek içinde yapılsaydı HTTP isteğini o kadar tutardı.

Bu işçi **kurulmadan yazma işleri çalışmaz** — kuyrukta bekler ve ekranda
"cihaza yazılıyor" yazısı hiç değişmez.

Birimdeki `<site-kullanicisi>` ve `<kurulum-dizini>` yer tutucuları önce
kurulumunuza göre doldurulur.

```
sudo cp deploy/nms-switch-write.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nms-switch-write
systemctl status nms-switch-write
```

Neden `nms-switch-sync` işçisinde değil:

- O işçi `--tries=2` ile çalışıyor. Cihaz yazması **kör tekrar edilmez**;
  yarıda kalmış bir değişikliğe ikinci kez yazmak, panelin bilmediği bir
  duruma yazmaktır.
- O işçi scheduler'dan 55 saniyelik parçalar hâlinde doğuyor. Yazma en kötü
  ihtimalle bir sonraki tura kadar bekler; kullanıcı ekranda bekliyor.
- Uzun yazmalar eşitleme turlarının arkasında sıraya girmemeli.

Kuyruk adı `config/netconf.php` → `write_queue` (env: `NETCONF_WRITE_QUEUE`).
