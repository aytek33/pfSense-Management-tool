# MAC Binding Görünmüyor - Hızlı Çözüm

## Sorun

Voucher login başarılı ama AUTO_BIND tag'li MAC'ler WEB UI'da görünmüyor.

**Log örneği**:
```
Voucher login good for 78 min.: 3r66NQ6LstJ, a4:f6:e8:ed:c2:69, 172.18.34.13
CONCURRENT LOGIN - REUSING OLD SESSION
```

## Hızlı Teşhis

pfSense sunucusunda şu komutu çalıştırın:

```bash
/usr/local/sbin/macbind_diagnose.sh a4:f6:e8:ed:c2:69
```

Bu komut tüm sistem bileşenlerini kontrol eder.

## En Olası Sorun ve Çözüm

### Sorun: Custom Portal Sayfası Kullanılmıyor

Hook sadece `portal_mac_to_voucher.php` dosyasında. Eğer pfSense default portal sayfası kullanılıyorsa, hook çalışmaz.

### Çözüm: Custom Portal Sayfasını Aktif Et

1. **pfSense WEB UI'a giriş yapın**
2. **Services > Captive Portal > office** sayfasına gidin
3. **Portal Page Contents** bölümünü bulun
4. **`pfSense/portal_mac_to_voucher.php`** dosyasının içeriğini açın ve kopyalayın
5. **Portal Page Contents** alanına yapıştırın
6. **Save** butonuna tıklayın

### Alternatif: pfSense Core Dosyasına Hook Ekle

Eğer custom portal sayfası kullanmak istemiyorsanız:

1. SSH ile pfSense'e bağlanın
2. `/usr/local/captiveportal/index.php` dosyasını düzenleyin
3. Satır 209'dan sonra, `captive_portal_hook.php` dosyasındaki hook kodunu ekleyin

Detaylı talimatlar: `HOOK_INSTALLATION_GUIDE.md`

## Kontrol Adımları

### 1. Hook Çalışıyor mu?

```bash
# Yeni bir voucher login yap
# Hemen queue dosyasını kontrol et
tail -1 /var/db/macbind_queue.csv
```

**Beklenen**: Yeni CSV satırı görünmeli

**Eğer yoksa**: Hook çalışmıyor → Custom portal sayfasını aktif et

### 2. Sync Çalışıyor mu?

```bash
# Manuel sync çalıştır
/usr/local/sbin/macbind_sync.php

# Config'de kontrol et
grep -i "AUTO_BIND" /conf/config.xml | grep "a4:f6:e8:ed:c2:69"
```

**Beklenen**: Config'de AUTO_BIND tag'li MAC görünmeli

**Eğer yoksa**: Cron job çalışmıyor → Cron job'ı kontrol et

### 3. WEB UI'da Görünüyor mu?

1. **Services > Captive Portal > office > MACs** sayfasına gidin
2. Sayfayı yenileyin (F5)
3. MAC adresini arayın: `a4:f6:e8:ed:c2:69`
4. Description sütununda `AUTO_BIND:` ile başladığını kontrol edin

**Eğer yoksa**: Config'de var mı kontrol et, varsa sayfayı yenileyin

## Concurrent Login Sorunu

Log'larda "CONCURRENT LOGIN - REUSING OLD SESSION" görüyorsanız:

- Bu durumda hook çalışmaz (yeni session oluşturulmaz)
- Kullanıcıyı logout yapıp tekrar login yaptırın
- Veya kullanıcının mevcut session'ını sonlandırın

## Detaylı Yardım

- `MACBIND_TROUBLESHOOTING.md` - Detaylı sorun giderme
- `HOOK_INSTALLATION_GUIDE.md` - Hook kurulum rehberi
- `/usr/local/sbin/macbind_diagnose.sh` - Otomatik teşhis scripti
