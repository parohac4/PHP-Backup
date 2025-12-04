# ✅ Kontrola před nasazením na produkci

## 📋 Checklist

### 1. Konfigurace (config.php)
- [ ] API token je nastaven (ne výchozí `ZMENTE_TENTO_TOKEN_...`)
- [ ] `backup_root` je nastavena na správnou absolutní cestu
- [ ] Databáze jsou nastaveny (pokud chcete zálohovat DB z config)
- [ ] `zip_password` je nastaveno (volitelné, ale doporučeno)

### 2. IP Whitelist (.htaccess)
- [ ] **Přidány vaše IP adresy** do sekce `<RequireAny>`
- [ ] Formát: `Require ip VAŠE_IP_ADRESA`
- [ ] Testovací IP adresy jsou odstraněny
- [ ] Zkontrolováno, že whitelist funguje

**Jak přidat IP:**
```apache
<RequireAny>
    Require ip VAŠE_IP_IPv4
    Require ip VAŠE_IP_IPv6
</RequireAny>
```

### 3. Setup adresář
- [ ] Token je vygenerován a uložen
- [ ] Adresář `setup/` je **SMAZÁN** nebo zablokován v `setup/.htaccess`

### 4. Oprávnění souborů
```bash
chmod 644 *.php
chmod 640 config.php
chmod 750 backups/
chmod 644 .htaccess
```

### 5. Bezpečnostní kontrola
- [ ] `.htaccess` blokuje přístup k `config.php`
- [ ] Adresář `backups/` má vlastní `.htaccess` s `Require all denied`
- [ ] `generate-token.php` je zablokován v `.htaccess` (nebo smazán)

### 6. Testování
- [ ] Přístup z povolené IP funguje
- [ ] Přístup z nepovolené IP je zamítnut (403)
- [ ] Vytvoření zálohy funguje
- [ ] Stažení zálohy funguje
- [ ] Hromadné mazání záloh funguje

## 🚨 DŮLEŽITÉ VAROVÁNÍ

**NIKDY:**
- ❌ Neodstraňujte IP whitelist z `.htaccess`
- ❌ Nesdílejte API token
- ❌ Nenechávejte setup adresář přístupný
- ❌ Nepoužívejte HTTP místo HTTPS

---

**Po dokončení všech kontrol je projekt připraven k nasazení!**

