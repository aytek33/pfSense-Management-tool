# Hızlı Çözüm - Şimdi Yapılacaklar

## Sorun
- ⚠ **Stale lock file** - Sync çalışamıyor
- Queue'da MAC var ama Active DB'de yok
- Config'de AUTO_BIND tag'li MAC yok
- Sync log boş (sync hiç çalışmamış)

## Çözüm Adımları

### 1. Lock File'ı Kaldır

pfSense shell'de şu komutu çalıştırın:

```bash
rm /var/run/macbind_sync.lock
```

### 2. Sync'i Manuel Çalıştır

```bash
/usr/local/sbin/macbind_sync.php
```

### 3. Sonuçları Kontrol Et

```bash
# Active DB'de MAC var mı?
grep -i "a4:f6:e8:ed:c2:69" /var/db/macbind_active.json

# Config'de AUTO_BIND var mı?
grep -i "AUTO_BIND" /conf/config.xml | grep "a4:f6:e8:ed:c2:69"

# Sync log'unu kontrol et
tail -20 /var/log/macbind_sync.log
```

### 4. Tekrar Teşhis Çalıştır

```bash
/usr/local/sbin/macbind_diagnose.sh a4:f6:e8:ed:c2:69
```

## Tek Komutla Çözüm

Tüm adımları tek seferde yapmak için:

```bash
rm /var/run/macbind_sync.lock && /usr/local/sbin/macbind_sync.php && echo "=== Sync completed ===" && echo "" && echo "Active DB check:" && grep -i "a4:f6:e8:ed:c2:69" /var/db/macbind_active.json && echo "" && echo "Config check:" && grep -i "AUTO_BIND" /conf/config.xml | grep "a4:f6:e8:ed:c2:69"
```

## Beklenen Sonuç

Sync başarılı olduktan sonra:
- ✓ Active DB'de MAC görünmeli
- ✓ Config'de `AUTO_BIND:` tag'li MAC görünmeli
- ✓ Sync log'da kayıtlar olmalı
- ✓ WEB UI'da (Services > Captive Portal > office > MACs) MAC görünmeli

## Sorun Devam Ederse

1. Sync log'u kontrol edin: `cat /var/log/macbind_sync.log`
2. PHP hatalarını kontrol edin: `tail -50 /var/log/php_errors.log`
3. Sync script'i manuel test edin: `/usr/local/sbin/macbind_sync.php --help`
4. `MACBIND_TROUBLESHOOTING.md` dosyasına bakın
