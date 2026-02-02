# Deployment Dosyaları Listesi

## Otomatik Deployment (Önerilen)

`deploy_to_pfsense.sh` veya `deploy_all.sh` scriptlerini kullanırsanız, aşağıdaki dosyalar otomatik olarak kopyalanır:

### 1. System Scripts (`/usr/local/sbin/`)

```
usr/local/sbin/macbind_sync.php          # Ana sync script (PHP)
usr/local/sbin/macbind_sync.sh           # Cron wrapper script
usr/local/sbin/macbind_install.sh        # Installation script
usr/local/sbin/macbind_import.php        # Import utility
usr/local/sbin/macbind_manage.php        # Management utility
usr/local/sbin/macbind_diagnose.sh       # Diagnostic script
```

### 2. Web API (`/usr/local/www/`)

```
usr/local/www/macbind_api.php            # REST API endpoint
```

### 3. Configuration (`/usr/local/etc/`)

```
usr/local/etc/macbind_api.conf.sample    # API config template
```

---

## Manuel Deployment

Eğer otomatik script kullanmıyorsanız, yukarıdaki dosyaları manuel olarak kopyalayın:

### Adım 1: Script Dosyalarını Kopyala

```bash
# pfSense'e SSH ile bağlan
ssh admin@PFSENSE_IP

# Script dosyalarını kopyala
scp usr/local/sbin/macbind_*.php admin@PFSENSE_IP:/usr/local/sbin/
scp usr/local/sbin/macbind_*.sh admin@PFSENSE_IP:/usr/local/sbin/

# Çalıştırılabilir yap
ssh admin@PFSENSE_IP "chmod +x /usr/local/sbin/macbind_*.sh /usr/local/sbin/macbind_*.php"
```

### Adım 2: Web API Dosyasını Kopyala

```bash
scp usr/local/www/macbind_api.php admin@PFSENSE_IP:/usr/local/www/
```

### Adım 3: Config Template'i Kopyala

```bash
scp usr/local/etc/macbind_api.conf.sample admin@PFSENSE_IP:/usr/local/etc/
```

### Adım 4: Installation Script'i Çalıştır

```bash
ssh admin@PFSENSE_IP "/usr/local/sbin/macbind_install.sh"
```

---

## Captive Portal Hook (Ayrı İşlem)

**ÖNEMLİ:** Captive Portal hook dosyaları deployment scriptine dahil değildir. Bunları ayrıca yapılandırmanız gerekir.

### Seçenek 1: Custom Portal Page (Önerilen)

```
pfSense/portal_mac_to_voucher.php        # Custom portal page (hook entegre)
```

**Kurulum:**
1. `portal_mac_to_voucher.php` dosyasını pfSense'e kopyalayın
2. Services > Captive Portal > [Zone] > Portal Page Contents
3. "Use custom page" seçeneğini aktif edin
4. Dosya içeriğini yapıştırın veya dosya yolunu belirtin

### Seçenek 2: Hook Kodu (Manuel Entegrasyon)

```
captive_portal_hook.php                  # Sadece hook kodu
```

**Kurulum:**
1. `captive_portal_hook.php` dosyasını açın
2. Hook kodunu (lines 90-219) kopyalayın
3. Kendi portal sayfanıza entegre edin
4. Veya `HOOK_INSTALLATION_GUIDE.md` dosyasındaki talimatları izleyin

---

## Dosya Yapısı Özeti

```
pfSense-captive-poral-MAC-Bind/
├── deploy_to_pfsense.sh          # Tek firewall deployment
├── deploy_all.sh                  # Batch deployment
│
├── usr/local/sbin/                # System scripts
│   ├── macbind_sync.php          ✓ Otomatik kopyalanır
│   ├── macbind_sync.sh           ✓ Otomatik kopyalanır
│   ├── macbind_install.sh        ✓ Otomatik kopyalanır
│   ├── macbind_import.php        ✓ Otomatik kopyalanır
│   ├── macbind_manage.php        ✓ Otomatik kopyalanır
│   └── macbind_diagnose.sh       ✓ Otomatik kopyalanır
│
├── usr/local/www/                 # Web files
│   └── macbind_api.php           ✓ Otomatik kopyalanır
│
├── usr/local/etc/                 # Config files
│   └── macbind_api.conf.sample   ✓ Otomatik kopyalanır
│
├── pfSense/                       # Captive Portal files
│   └── portal_mac_to_voucher.php ⚠ MANUEL KURULUM GEREKİR
│
└── captive_portal_hook.php        # Hook code only
                                    ⚠ MANUEL ENTEGRASYON GEREKİR
```

---

## Hızlı Kontrol Listesi

### Otomatik Deployment Sonrası:

- [x] Script dosyaları kopyalandı (`/usr/local/sbin/`)
- [x] Web API kopyalandı (`/usr/local/www/`)
- [x] Config template kopyalandı (`/usr/local/etc/`)
- [x] Installation script çalıştırıldı
- [x] Cron job eklendi (`/etc/crontab.local`)
- [x] API key oluşturuldu (`/usr/local/etc/macbind_api.conf`)

### Manuel Yapılması Gerekenler:

- [ ] **Captive Portal Hook kuruldu** (ÖNEMLİ!)
  - [ ] Custom portal page aktif edildi VEYA
  - [ ] Hook kodu portal sayfasına entegre edildi
- [ ] Pass-through MAC Auto Entry devre dışı bırakıldı
- [ ] Google Apps Script'e firewall eklendi (API key ile)

---

## Sorun Giderme

### Dosyalar kopyalanmadı mı?

```bash
# Manuel kontrol
ssh admin@PFSENSE_IP "ls -la /usr/local/sbin/macbind_*"
ssh admin@PFSENSE_IP "ls -la /usr/local/www/macbind_api.php"
```

### Script çalışmıyor mu?

```bash
# Permissions kontrolü
ssh admin@PFSENSE_IP "ls -la /usr/local/sbin/macbind_*.sh"
# Çalıştırılabilir olmalı: -rwxr-xr-x

# Manuel çalıştırma
ssh admin@PFSENSE_IP "/usr/local/sbin/macbind_diagnose.sh"
```

### Hook çalışmıyor mu?

1. Custom portal page aktif mi kontrol edin
2. `captive_portal_hook.php` içeriğini kontrol edin
3. `QUICK_FIX_MACBIND.md` dosyasına bakın
