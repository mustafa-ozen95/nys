---
paths:
  - 'database/seeders/**'
---

# Seeders

## Seeder'a uydurma envanter eklenmez
Envanter seeder'ları yalnızca yerel envanter dosyasındaki (`database/seeders/local/inventory.php`, depoda yok) kayıtları yazar; dosya yoksa hiçbir şey yazmazlar. Demo/örnek switch, sunucu veya kullanıcı üretilmez.

Seeder uydurma kayıt ve rastgele (`fake()->ipv4()`) uplink yazmaz: gerçek veriymiş gibi görünür ve ekranlarda yanlış bilgi oluşturur.

Otomatik toplanan alanlar seeder'da doldurulmaz — onları eşitleme yazar: status, cpu_usage, memory_usage, temperature, uptime_seconds, zabbix_host_id, model, serial_number, junos_version. Uydurma zabbix_host_id sync'i sessizce boşa düşürür.

Toplanamayan alanlar hiç tutulmaz: sunucularda cpu/ram/disk/uptime kolonu yoktur, elle girilen ölü veri olurlardı.

Factory'ler yalnızca test içindir, seeder'da kullanılmaz.
