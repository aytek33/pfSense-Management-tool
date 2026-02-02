# MAC Binding Troubleshooting Guide

## MAC Binding Görünmüyor Sorunu

### Hızlı Teşhis

pfSense sunucusunda şu komutu çalıştırın:

```bash
/usr/local/sbin/macbind_diagnose.sh a4:f6:e8:ed:c2:69
```

Bu komut tüm sistem bileşenlerini kontrol eder ve sorunun kaynağını gösterir.

### Yaygın Sorunlar ve Çözümleri

#### Sorun 1: Hook Çalışmıyor (Queue Dosyası Boş)

**Belirtiler**:
- Queue dosyası yok veya boş
- Voucher login başarılı ama MAC binding oluşmuyor

**Neden**:
- Custom portal sayfası kullanılmıyor (pfSense default sayfası kullanılıyor)
- Hook kodu portal sayfasında yok

**Çözüm Seçenekleri**:

**Seçenek A: Custom Portal Sayfasını Aktif Et (Önerilen)**

1. pfSense WEB UI'a giriş yapın
2. Services > Captive Portal > [Zone: office] sayfasına gidin
3. "Portal Page Contents" bölümünü bulun
4. `portal_mac_to_voucher.php` dosyasının içeriğini kopyalayıp buraya yapıştırın
5. Save butonuna tıklayın

**Seçenek B: pfSense Core Dosyasına Hook Ekle**

Eğer custom portal sayfası kullanmak istemiyorsanız, hook'u pfSense'in default portal sayfasına ekleyin:

1. SSH ile pfSense'e bağlanın
2. `/usr/local/captiveportal/index.php` dosyasını düzenleyin
3. Satır 209'dan sonra (voucher başarılı olduğunda), `captive_portal_hook.php` dosyasındaki hook kodunu ekleyin

**Kontrol**:
```bash
# Queue dosyasına yazıldığını kontrol et
tail -f /var/db/macbind_queue.csv
# Yeni bir voucher login yap, queue'ya satır eklenmeli
```

#### Sorun 2: Cron Job Çalışmıyor

**Belirtiler**:
- Queue dosyasında MAC var ama active DB'de yok
- Config'de AUTO_BIND tag'li MAC yok

**Kontrol**:
```bash
# Cron job var mı?
crontab -l | grep macbind
# veya
grep macbind /etc/crontab.local
```

**Çözüm**:

**GUI ile**:
1. System > Package Manager > Available Packages
2. "Cron" paketini yükleyin (yoksa)
3. Services > Cron > Add
4. Ayarlar:
   - Minute: `*`
   - Hour: `*`
   - Day of Month: `*`
   - Month: `*`
   - Day of Week: `*`
   - User: `root`
   - Command: `/usr/local/sbin/macbind_sync.sh`
5. Save

**Manuel**:
```bash
echo '* * * * * root /usr/local/sbin/macbind_sync.sh' >> /etc/crontab.local
```

**Test**:
```bash
# Manuel sync çalıştır
/usr/local/sbin/macbind_sync.php

# Log'u kontrol et
tail -20 /var/log/macbind_sync.log
```

#### Sorun 3: Zone Adı Uyuşmazlığı

**Belirtiler**:
- Queue'da MAC var, active DB'de var ama config'de yok
- Farklı zone adları kullanılıyor

**Kontrol**:
```bash
# Queue'daki zone adını kontrol et
tail -1 /var/db/macbind_queue.csv | cut -d',' -f2

# Config'deki zone adlarını kontrol et
grep -A 2 "<zone>" /conf/config.xml | grep -v "^--$"
```

**Çözüm**:
- Zone adlarının tam olarak eşleştiğinden emin olun (büyük/küçük harf duyarlı)
- Queue'daki zone adı config'deki zone adı ile aynı olmalı

#### Sorun 4: Permissions Sorunu

**Belirtiler**:
- PHP error log'da "Permission denied" hatası
- Queue dosyasına yazılamıyor

**Kontrol**:
```bash
# Queue dosyası permissions
ls -la /var/db/macbind_queue.csv

# Dizin permissions
ls -ld /var/db/
```

**Çözüm**:
```bash
# Doğru permissions ayarla
chown root:www /var/db/macbind_queue.csv
chmod 0664 /var/db/macbind_queue.csv

# Dizin permissions
chmod 0775 /var/db/
```

#### Sorun 5: Sync Disabled

**Belirtiler**:
- Sync log'da "Sync disabled" mesajı
- Cron job çalışıyor ama işlem yapmıyor

**Kontrol**:
```bash
# Disable flag var mı?
ls -la /var/db/macbind_disabled
```

**Çözüm**:
```bash
# Disable flag'i kaldır
rm /var/db/macbind_disabled
```

### Adım Adım Teşhis

#### 1. Queue Kontrolü

```bash
# Queue dosyası var mı?
ls -la /var/db/macbind_queue.csv

# İçeriğini kontrol et
cat /var/db/macbind_queue.csv

# Belirli bir MAC'i ara
grep "a4:f6:e8:ed:c2:69" /var/db/macbind_queue.csv
```

**Beklenen**: Voucher login yaptıktan sonra queue'da CSV satırı olmalı

**Eğer yoksa**: Hook çalışmıyor → Custom portal sayfasını kontrol et

#### 2. Active DB Kontrolü

```bash
# Active DB var mı?
ls -la /var/db/macbind_active.json

# İçeriğini kontrol et
cat /var/db/macbind_active.json | python3 -m json.tool

# MAC'i ara
grep -i "a4:f6:e8:ed:c2:69" /var/db/macbind_active.json
```

**Beklenen**: Cron job çalıştıktan sonra active DB'de MAC olmalı

**Eğer yoksa**: Cron job çalışmıyor veya queue işlenmiyor

#### 3. Config Kontrolü

```bash
# AUTO_BIND tag'li MAC'ler
grep -i "AUTO_BIND" /conf/config.xml

# Belirli MAC'i ara
grep -i "a4:f6:e8:ed:c2:69" /conf/config.xml
```

**Beklenen**: Config'de `AUTO_BIND:` tag'li MAC entry'si olmalı

**Eğer yoksa**: Config sync çalışmıyor

#### 4. WEB UI Kontrolü

**Yol**: Services > Captive Portal > office > MACs

**Kontrol**:
- MAC adresi listede var mı?
- Description: `AUTO_BIND:...` ile başlıyor mu?
- Action: Pass olarak görünüyor mu?

**Eğer yoksa**: Sayfayı yenileyin veya config'i kontrol edin

### Manuel Test

#### Test 1: Hook'un Çalıştığını Doğrula

1. Yeni bir cihazla voucher login yapın
2. Hemen queue dosyasını kontrol edin:
   ```bash
   tail -1 /var/db/macbind_queue.csv
   ```
3. Yeni satır eklenmiş olmalı

#### Test 2: Sync'in Çalıştığını Doğrula

1. Queue'da MAC olduğundan emin olun
2. Manuel sync çalıştırın:
   ```bash
   /usr/local/sbin/macbind_sync.php
   ```
3. Active DB'yi kontrol edin:
   ```bash
   grep -i "a4:f6:e8:ed:c2:69" /var/db/macbind_active.json
   ```
4. Config'i kontrol edin:
   ```bash
   grep -i "AUTO_BIND" /conf/config.xml | grep "a4:f6:e8:ed:c2:69"
   ```

#### Test 3: WEB UI'da Görünürlük

1. Services > Captive Portal > office > MACs sayfasına gidin
2. Sayfayı yenileyin (F5)
3. MAC adresini arayın
4. Description sütununda `AUTO_BIND:` ile başladığını doğrulayın

### Log Analizi

#### Sync Log

```bash
# Son 50 satır
tail -50 /var/log/macbind_sync.log

# Hataları filtrele
grep -E "\[ERROR\]|\[WARN\]" /var/log/macbind_sync.log

# Belirli MAC için
grep "a4:f6:e8:ed:c2:69" /var/log/macbind_sync.log
```

#### PHP Error Log

```bash
# macbind hataları
grep -i "macbind" /var/log/php_errors.log

# Son hatalar
tail -100 /var/log/php_errors.log | grep -i "macbind"
```

### Sık Sorulan Sorular

**S: Voucher login başarılı ama MAC binding oluşmuyor?**

A: Hook çalışmıyor olabilir. Custom portal sayfasının kullanıldığından emin olun veya pfSense core dosyasına hook ekleyin.

**S: Queue'da MAC var ama WEB UI'da görünmüyor?**

A: Cron job çalışmıyor olabilir veya config sync başarısız olmuş olabilir. Sync log'unu kontrol edin.

**S: "CONCURRENT LOGIN - REUSING OLD SESSION" görüyorum, hook çalışır mı?**

A: Hayır. Concurrent login durumunda yeni session oluşturulmaz, bu yüzden hook çalışmaz. Kullanıcıyı logout yapıp tekrar login yaptırın.

**S: Zone adı farklı görünüyor, sorun olur mu?**

A: Evet. Zone adları tam olarak eşleşmeli (büyük/küçük harf duyarlı). Queue'daki zone adı config'deki zone adı ile aynı olmalı.

### Destek

Daha fazla yardım için:
- README.md dosyasını okuyun
- `/usr/local/sbin/macbind_sync.php --help` komutunu çalıştırın
- `/usr/local/sbin/macbind_diagnose.sh` teşhis scriptini kullanın
