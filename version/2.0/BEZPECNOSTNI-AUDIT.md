# 🔒 Bezpečnostní audit - PHP Backup Tool v2.0

## ✅ Implementované bezpečnostní opatření

### 1. Autentizace a autorizace
- ✅ **API Token autentizace** - Všechny požadavky vyžadují platný token
- ✅ **IP Whitelist** - Přístup pouze z povolených IP adres (nastaveno v .htaccess)
- ✅ **CSRF ochrana** - Ochrana proti Cross-Site Request Forgery útokům
- ✅ **Hash comparison** - Použití `hash_equals()` pro bezpečné porovnání tokenů

### 2. Validace vstupů
- ✅ **Validace názvů souborů** - Regex kontrola formátu názvů záloh
- ✅ **Escapování shell příkazů** - Použití `escapeshellarg()` pro všechny shell příkazy
- ✅ **Validace databázových přístupů** - Kontrola povinných polí před použitím
- ✅ **Validace režimů zálohy** - Whitelist povolených hodnot

### 3. Ochrana souborů
- ✅ **.htaccess ochrana** - Citlivé soubory jsou blokovány
- ✅ **Adresář backups/** - Chráněn samostatným .htaccess
- ✅ **Dočasné soubory** - Automatické mazání po použití
- ✅ **Log soubory** - Nejsou přístupné přes web

### 4. Rate limiting a zámky
- ✅ **Rate limiting** - Omezení četnosti záloh (nastavitelný)
- ✅ **File locking** - Zámek proti souběžným zálohám
- ✅ **Timeout ochrana** - Automatické uvolnění zámků

### 5. Bezpečnost databázových přístupů
- ✅ **Nevyžadují se v config.php** - Přístupy se zadávají dynamicky
- ✅ **Mazání z paměti** - Přístupy se vymažou po použití
- ✅ **Proměnné prostředí** - Hesla se předávají přes MYSQL_PWD (ne v příkazové řádce)
- ✅ **Fallback na mysqli** - Pokud mysqldump není dostupný, použije se bezpečná mysqli metoda

## ⚠️ Potenciální bezpečnostní rizika a řešení

### 1. Shell příkazy (exec, proc_open)
**Riziko:** Command injection při nesprávném escapování

**Ochrana:**
- ✅ Všechny parametry jsou escapovány pomocí `escapeshellarg()`
- ✅ Používá se whitelist povolených příkazů (mysqldump, gzip)
- ✅ Fallback na mysqli metodu (bez shell příkazů)

**Doporučení:**
- Pravidelně kontrolovat logy na podezřelé příkazy
- Pokud není potřeba mysqldump, použít pouze mysqli metodu

### 2. IP Whitelist
**Riziko:** Pokud IP whitelist není nastaven, nástroj je přístupný všem

**Ochrana:**
- ✅ .htaccess obsahuje IP whitelist s testovacími IP
- ✅ Uživatel musí přidat své IP adresy

**Doporučení:**
- **VŽDY** nastavte IP whitelist před nasazením na produkci
- Pravidelně kontrolujte, že whitelist obsahuje pouze oprávněné IP
- Pokud máte dynamickou IP, zvažte VPN nebo jiné řešení

### 3. API Token
**Riziko:** Slabý nebo uniknutý token

**Ochrana:**
- ✅ Token je generován pomocí `random_bytes(32)` (64 znaků hex)
- ✅ Token se ukládá v config.php (chráněn .htaccess)
- ✅ Token se předává v hlavičce nebo GET parametru

**Doporučení:**
- Pravidelně rotujte tokeny
- Používejte HTTPS pro přenos tokenů
- Nikdy nesdílejte token v logách nebo chybových hlášeních

### 4. CSRF ochrana
**Riziko:** Cross-Site Request Forgery útoky

**Ochrana:**
- ✅ CSRF token se generuje pro každou session
- ✅ Token se ověřuje pomocí `hash_equals()`
- ✅ GET požadavky pro download/list nepotřebují CSRF

**Doporučení:**
- Ujistěte se, že session cookies jsou nastaveny jako Secure a HttpOnly

### 5. Path traversal
**Riziko:** Přístup k souborům mimo povolený adresář

**Ochrana:**
- ✅ Použití `realpath()` pro normalizaci cest
- ✅ Validace názvů souborů pomocí regex
- ✅ `basename()` pro odstranění path traversal sekvencí

**Doporučení:**
- Pravidelně kontrolovat, že `backup_root` neumožňuje přístup k citlivým adresářům

### 6. SQL Injection (při mysqli metodě)
**Riziko:** SQL injection při dumpu přes mysqli

**Ochrana:**
- ✅ Použití `real_escape_string()` pro všechny hodnoty
- ✅ Backtick escapování názvů tabulek a sloupců
- ✅ Prepared statements nejsou potřeba (pouze SELECT pro čtení)

**Doporučení:**
- Pravidelně testovat dumpy na správnost dat

### 7. ZIP bomb / DoS
**Riziko:** Velké ZIP soubory mohou způsobit DoS

**Ochrana:**
- ✅ Nastavitelný limit velikosti ZIP (`max_zip_size_mb`)
- ✅ Rate limiting proti zneužití

**Doporučení:**
- Nastavte rozumný limit velikosti ZIP
- Monitorujte využití diskového prostoru

## 🔐 Doporučená bezpečnostní nastavení

### Před nasazením na produkci:

1. **IP Whitelist**
   ```apache
   # V .htaccess přidejte pouze své IP adresy
   Require ip VAŠE_IP_ADRESA
   ```

2. **API Token**
   ```php
   // Vygenerujte silný token
   'api_token' => bin2hex(random_bytes(32))
   ```

3. **Oprávnění souborů**
   ```bash
   chmod 750 backups/
   chmod 640 config.php
   chmod 644 *.php
   ```

4. **HTTPS**
   - Používejte pouze HTTPS pro přístup k nástroji
   - Nastavte HSTS hlavičky

5. **Setup adresář**
   - Po dokončení nastavení **SMAŽTE** adresář `setup/`
   - Nebo zablokujte přístup v `setup/.htaccess`

## 📋 Checklist bezpečnosti

- [ ] IP whitelist je nastaven a obsahuje pouze oprávněné IP
- [ ] API token je silný a jedinečný
- [ ] `config.php` není přístupný přes web
- [ ] Adresář `backups/` není přístupný přes web
- [ ] Setup adresář je smazán nebo zablokován
- [ ] Používáte HTTPS
- [ ] Oprávnění souborů jsou správně nastavena
- [ ] Rate limiting je aktivní (nebo vypnutý záměrně)
- [ ] Logy jsou pravidelně kontrolovány
- [ ] Tokeny jsou pravidelně rotovány

## 🚨 Varování

**NIKDY:**
- ❌ Nesdílejte API token
- ❌ Neukládejte `config.php` s reálnými hesly do GIT
- ❌ Nenechávejte setup adresář přístupný
- ❌ Nepoužívejte HTTP místo HTTPS
- ❌ Neodstraňujte IP whitelist z .htaccess

## 📞 Reportování bezpečnostních problémů

Pokud najdete bezpečnostní chybu, kontaktujte vývojáře okamžitě.

---

**Poslední aktualizace:** 2024
**Verze:** 2.0

