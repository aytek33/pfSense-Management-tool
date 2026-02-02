# Stale Lock File Sorunu - Hızlı Çözüm

## Sorun

Teşhis çıktısında görülen:
- ⚠ **Stale lock file detected** - Sync çalışamıyor
- Queue'da MAC var ama Active DB'de yok
- Config'de AUTO_BIND tag'li MAC yok

## Çözüm

### Adım 1: Lock File'ı Kaldır

pfSense shell'de veya SSH ile:

```bash
rm /var/run/macbind_sync.lock
```

### Adım 2: Sync'i Manuel Çalıştır

```bash
/usr/local/sbin/macbind_sync.php
```

### Adım 3: Sonuçları Kontrol Et

```bash
# Active DB'de MAC var mı?
grep -i "a4:f6:e8:ed:c2:69" /var/db/macbind_active.json

# Config'de AUTO_BIND var mı?
grep -i "AUTO_BIND" /conf/config.xml | grep "a4:f6:e8:ed:c2:69"

# Sync log'unu kontrol et
tail -20 /var/log/macbind_sync.log
```

### Adım 4: WEB UI'da Kontrol Et

1. Services > Captive Portal > office > MACs
2. Sayfayı yenileyin (F5)
3. MAC adresini arayın: `a4:f6:e8:ed:c2:69`
4. Description: `AUTO_BIND:...` ile başlamalı

## Neden Stale Lock Oluşur?

- Sync script'i çalışırken sistem yeniden başlatıldı
- Sync script'i kill edildi
- Disk dolu oldu
- Process crash oldu

## Önlem

Cron job'ın düzgün çalıştığından emin olun:

```bash
# Cron job kontrolü
grep macbind /etc/crontab.local

# Beklenen:
# * * * * * root /usr/local/sbin/macbind_sync.sh
```

Eğer cron job yoksa, ekleyin (Services > Cron GUI'den veya manuel).

## Tek Komutla Çözüm

```bash
rm /var/run/macbind_sync.lock && /usr/local/sbin/macbind_sync.php && echo "Sync completed. Check results above."
```

## Tekrar Teşhis

Sorun çözüldükten sonra tekrar teşhis çalıştırın:

```bash
/usr/local/sbin/macbind_diagnose.sh a4:f6:e8:ed:c2:69
```

Artık şunları görmelisiniz:
- ✓ MAC found in active DB
- ✓ MAC found in config
- ✓ MAC has AUTO_BIND tag
- ✓ No lock file (sync can run)
