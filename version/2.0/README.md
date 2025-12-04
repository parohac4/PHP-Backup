# PHP Backup Tool v2.0

Bezpečný a jednotný nástroj pro zálohování souborů a databází na FTP serveru.

## 🚀 Funkce

- ✅ **Kombinovaná záloha** - Soubory i databáze v jednom ZIP archivu
- ✅ **Bezpečnostní opatření** - IP whitelist, token autentizace, CSRF ochrana
- ✅ **Dynamické zadávání DB** - Přístupy do databáze se zadávají přes web, neukládají se
- ✅ **Automatické čištění** - Mazání starých záloh podle konfigurace
- ✅ **Moderní UI** - Přehledné webové rozhraní
- ✅ **Hromadné mazání** - Možnost smazat více záloh najednou
- ✅ **API endpoint** - Možnost automatizace přes API

## 📋 Požadavky

- PHP 7.4 nebo vyšší
- Rozšíření: `zip`, `mysqli`
- Pro kompresi dumpů: `gzip` v PATH (volitelné)
- Zapisovatelný adresář pro zálohy

## 🔧 Rychlá instalace

### 1. Nastavení tokenu
Otevřete `setup/index.php` v prohlížeči a vygenerujte token.

### 2. Nastavení IP whitelistu
Upravte `.htaccess` a přidejte své IP adresy:
```apache
<RequireAny>
    Require ip VAŠE_IP_ADRESA
</RequireAny>
```

### 3. Nastavení cesty
Upravte `config.php` a nastavte `backup_root` na správnou cestu.

### 4. Hotovo!
Otevřete `index.php` a můžete začít zálohovat.

## 🔐 Bezpečnostní opatření

1. **IP Whitelist** - Přístup pouze z povolených IP adres
2. **API Token** - Autentizace všech požadavků
3. **CSRF ochrana** - Ochrana proti CSRF útokům
4. **Path traversal ochrana** - Bezpečné zpracování cest
5. **SQL escapování** - Bezpečné escapování SQL dotazů
6. **Ochrana souborů** - `.htaccess` blokuje citlivé soubory

## 📚 Dokumentace

- `KONTROLA-PRED-NASAZENIM.md` - Checklist před nasazením
- `BEZPECNOSTNI-AUDIT.md` - Detailní bezpečnostní audit
- `IP-WHITELIST-NASTAVENI.md` - Návod na nastavení IP whitelistu
- `STRUKTURA-PROJEKTU.md` - Struktura projektu

## ⚠️ DŮLEŽITÉ

**Před nasazením:**
1. Nastavte IP whitelist v `.htaccess`
2. Vygenerujte API token pomocí `setup/index.php`
3. Nastavte `backup_root` v `config.php`
4. Po dokončení nastavení **SMAŽTE** adresář `setup/`

---

**Verze:** 2.0
