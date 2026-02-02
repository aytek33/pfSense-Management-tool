# MAC Binding Hook Kurulum Rehberi

## Sorun: MAC Binding Görünmüyor

Log'larda voucher login başarılı görünüyor ama AUTO_BIND tag'li MAC'ler WEB UI'da görünmüyor.

**En Olası Neden**: Custom portal sayfası kullanılmıyor, hook çalışmıyor.

## Çözüm Seçenekleri

### Seçenek 1: Custom Portal Sayfasını Aktif Et (Kolay)

**Adımlar**:

1. pfSense WEB UI'a giriş yapın
2. **Services > Captive Portal > [Zone: office]** sayfasına gidin
3. **Portal Page Contents** bölümünü bulun
4. `pfSense/portal_mac_to_voucher.php` dosyasının içeriğini açın
5. Tüm içeriği kopyalayıp **Portal Page Contents** alanına yapıştırın
6. **Save** butonuna tıklayın

**Avantajlar**:
- Kolay kurulum
- Mevcut custom sayfa özelliklerini korur (random MAC kontrolü, voucher listesi, vb.)
- pfSense core dosyasını değiştirmez

**Kontrol**:
```bash
# Yeni bir voucher login yap
# Queue dosyasını kontrol et
tail -1 /var/db/macbind_queue.csv
# Yeni satır eklenmiş olmalı
```

### Seçenek 2: pfSense Core Dosyasına Hook Ekle (Kalıcı)

Eğer custom portal sayfası kullanmak istemiyorsanız, hook'u pfSense'in default portal sayfasına ekleyin.

**Adımlar**:

1. SSH ile pfSense'e bağlanın
2. Core dosyasını yedekleyin:
   ```bash
   cp /usr/local/captiveportal/index.php /usr/local/captiveportal/index.php.backup
   ```

3. Dosyayı düzenleyin:
   ```bash
   vi /usr/local/captiveportal/index.php
   ```

4. Satır 209'dan sonra (voucher başarılı olduğunda), hook kodunu ekleyin:
   - `captive_portal_hook.php` dosyasındaki hook kodunu kopyalayın (satır 90-219)
   - `index.php` dosyasında satır 209'dan sonra, `if ($timecredit > 0) {` bloğunun içine yapıştırın
   - `MACBIND_DEFAULT_DURATION_MINUTES` ve `MACBIND_QUEUE_FILE` tanımlarını da ekleyin

5. Dosyayı kaydedin

**Alternatif: Patch Dosyası Kullan**

`pfsense-src-2.7.2/src/usr/local/captiveportal/index.php.patch` dosyasını kullanarak patch uygulayın:

```bash
cd /usr/local/captiveportal
patch -p0 < /path/to/index.php.patch
```

**Avantajlar**:
- Tüm login'lerde çalışır (custom portal sayfası olsun veya olmasın)
- Kalıcı çözüm

**Dezavantajlar**:
- pfSense güncellemesinde kaybolabilir (yeniden uygulanması gerekir)
- Core dosyasını değiştirir

## Hangi Seçeneği Seçmeliyim?

- **Custom portal sayfası kullanıyorsanız**: Seçenek 1 (Custom Portal Sayfasını Aktif Et)
- **Default portal sayfası kullanıyorsanız**: Seçenek 2 (pfSense Core Dosyasına Hook Ekle)
- **Her iki durumda da çalışmasını istiyorsanız**: Seçenek 2

## Kurulum Sonrası Kontrol

### 1. Hook'un Çalıştığını Doğrula

```bash
# Queue dosyasını izle
tail -f /var/db/macbind_queue.csv

# Yeni bir cihazla voucher login yap
# Queue'ya yeni satır eklenmeli
```

### 2. Sync'in Çalıştığını Doğrula

```bash
# Manuel sync çalıştır
/usr/local/sbin/macbind_sync.php

# Active DB'yi kontrol et
grep -i "a4:f6:e8:ed:c2:69" /var/db/macbind_active.json

# Config'i kontrol et
grep -i "AUTO_BIND" /conf/config.xml | grep "a4:f6:e8:ed:c2:69"
```

### 3. WEB UI'da Kontrol Et

1. Services > Captive Portal > office > MACs sayfasına gidin
2. Sayfayı yenileyin (F5)
3. MAC adresini arayın
4. Description sütununda `AUTO_BIND:` ile başladığını doğrulayın

## Sorun Giderme

### Hook Çalışmıyor

**Kontrol**:
```bash
# PHP error log'u kontrol et
tail -100 /var/log/php_errors.log | grep -i macbind

# Queue dosyası permissions
ls -la /var/db/macbind_queue.csv
```

**Çözüm**:
- Custom portal sayfasının doğru yüklendiğinden emin olun
- Queue dosyası permissions'ını kontrol edin: `chown root:www /var/db/macbind_queue.csv && chmod 0664 /var/db/macbind_queue.csv`

### Concurrent Login Sorunu

Log'larda "CONCURRENT LOGIN - REUSING OLD SESSION" görüyorsanız:

- Bu durumda hook çalışmaz (yeni session oluşturulmaz)
- Kullanıcıyı logout yapıp tekrar login yaptırın
- Veya kullanıcının session'ını sonlandırın

### Zone Adı Uyuşmazlığı

Queue'daki zone adı config'deki zone adı ile eşleşmeli:

```bash
# Queue'daki zone
tail -1 /var/db/macbind_queue.csv | cut -d',' -f2

# Config'deki zone
grep -A 2 "<zone>" /conf/config.xml | grep -v "^--$"
```

Zone adları tam olarak aynı olmalı (büyük/küçük harf duyarlı).

## Detaylı Teşhis

Tüm sistem bileşenlerini kontrol etmek için:

```bash
/usr/local/sbin/macbind_diagnose.sh a4:f6:e8:ed:c2:69
```

Bu script tüm kontrolleri yapar ve sorunun kaynağını gösterir.

## Daha Fazla Yardım

- `MACBIND_TROUBLESHOOTING.md` - Detaylı sorun giderme rehberi
- `README.md` - Genel dokümantasyon
- `/usr/local/sbin/macbind_sync.php --help` - Sync script yardımı
