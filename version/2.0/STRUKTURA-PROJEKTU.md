# 📁 Struktura projektu - PHP Backup Tool v2.0

## ✅ Povinné soubory

### Hlavní soubory:
- ✅ `index.php` - Webové rozhraní
- ✅ `api.php` - API endpoint
- ✅ `BackupManager.php` - Hlavní třída pro zálohování
- ✅ `config.php` - Konfigurační soubor
- ✅ `.htaccess` - Bezpečnostní nastavení s IP whitelistem

### Volitelné soubory:
- `generate-token.php` - Pomocný skript pro generování tokenu (odstranit po použití)
- `setup/index.php` - Webové rozhraní pro nastavení tokenu (odstranit po použití)
- `setup/.htaccess` - Ochrana setup adresáře

### Adresáře:
- `backups/` - Adresář pro ukládání záloh (vytvoří se automaticky)
- `backups/.htaccess` - Ochrana adresáře backups (vytvoří se automaticky)
- `setup/` - Setup adresář (odstranit po dokončení nastavení)

### Dokumentace:
- `BEZPECNOSTNI-AUDIT.md` - Detailní bezpečnostní audit
- `BEZPECNOSTNI-SOUHRN.md` - Rychlý souhrn bezpečnostních opatření
- `IP-WHITELIST-NASTAVENI.md` - Návod na nastavení IP whitelistu
- `STRUKTURA-PROJEKTU.md` - Tento soubor

## 🔧 Kontrola před nasazením

### 1. Zkontrolujte přítomnost souborů:
```bash
# Hlavní soubory
ls -la index.php api.php BackupManager.php config.php .htaccess

# Adresáře
ls -la backups/ setup/
```

### 2. Zkontrolujte oprávnění:
```bash
chmod 644 *.php
chmod 640 config.php
chmod 750 backups/
chmod 644 .htaccess
```

### 3. Zkontrolujte konfiguraci:
- [ ] API token je nastaven (ne výchozí)
- [ ] `backup_root` je nastavena na správnou cestu
- [ ] IP whitelist v `.htaccess` obsahuje vaše IP adresy

### 4. Zkontrolujte bezpečnost:
- [ ] `.htaccess` blokuje přístup k citlivým souborům
- [ ] Adresář `backups/` má vlastní `.htaccess`
- [ ] Setup adresář je smazán nebo zablokován

## 📋 Minimální struktura pro nasazení

```
version/2.0/
├── index.php              ✅ POVINNÉ
├── api.php                ✅ POVINNÉ
├── BackupManager.php      ✅ POVINNÉ
├── config.php             ✅ POVINNÉ
├── .htaccess              ✅ POVINNÉ
├── backups/               ✅ Vytvoří se automaticky
│   └── .htaccess          ✅ Vytvoří se automaticky
└── setup/                 ⚠️ Odstranit po použití
    ├── index.php
    └── .htaccess
```

## 🗑️ Soubory k odstranění po nastavení

Po dokončení nastavení můžete smazat:
- `generate-token.php` (pokud není použito)
- `setup/` (celý adresář)

---

**Verze:** 2.0

