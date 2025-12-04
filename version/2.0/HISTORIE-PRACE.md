# Historie práce na PHP Backup Tool v2.0

## Aktuální stav (poslední změna)

**Datum**: 2025-12-04
**Problém**: `Failed to execute 'json' on 'Response': Unexpected end of JSON input`

### Co bylo implementováno:
1. ✅ Základní struktura aplikace (config.php, BackupManager.php, api.php, index.php)
2. ✅ Token generování a setup stránka
3. ✅ Zálohování souborů a databází
4. ✅ Webové rozhraní s progress barem (nyní skrytý, jen text)
5. ✅ Bezpečnostní opatření (CSRF, rate limiting, IP whitelist)
6. ✅ Hromadné mazání záloh
7. ✅ Chunking pro velké zálohy (400 souborů na dávku)

### Aktuální problém:
- **Chyba**: `Failed to execute 'json' on 'Response': Unexpected end of JSON input`
- **Příčina**: JSON odpověď není kompletní nebo je prázdná
- **Kontext**: Záloha se vytváří, ale JSON odpověď se nepošle kompletně

### Co bylo zkoušeno:
1. ❌ Asynchronní zpracování s `fastcgi_finish_request()` - záloha se nedokončila
2. ❌ Worker skript na pozadí - záloha se nedokončila
3. ❌ Status polling s job_id - status se neaktualizoval na "completed"
4. ✅ Synchronní zpracování - aktuální přístup, ale JSON se nepošle kompletně

### Soubory k prozkoumání:
- `/home/parohac4/git/PHP-Backup/version/2.0/api.php` - hlavní API endpoint
- `/home/parohac4/git/PHP-Backup/version/2.0/BackupManager.php` - logika zálohování
- `/home/parohac4/git/PHP-Backup/version/2.0/index.php` - frontend
- `/home/parohac4/git/PHP-Backup/version/2.0/backup-worker.php` - worker skript (možná nepotřebný)

### Možné řešení pro příště:
1. **Zkontrolovat output buffering** - možná se něco vypisuje před JSON
2. **Zkontrolovat error handling** - možná se vyskytuje chyba, která přeruší výstup
3. **Zkontrolovat timeout** - možná se request přeruší před dokončením
4. **Přidat flushování výstupu** - zajistit, že se JSON pošle kompletně
5. **Zkontrolovat logy** - backup.log a PHP error log

### Známé problémy:
1. Záloha obsahuje jen `/www` místo celého `/virtual` (tmp, session, www)
   - V logu jsou adresáře: logs, session, tmp, www
   - Ale soubory jsou jen z www/
   - Možná exclude patterns nebo iterator neprochází všechny adresáře

### Důležité poznámky:
- Server: VEDOS hosting
- Omezení: `proc_open()` není dostupný (používá se mysqli fallback)
- Omezení: `opcache_reset()` je zakázáno
- Omezení: některé `.htaccess` direktivy jsou zakázané

### Další kroky:
1. Zkontrolovat, proč se JSON nepošle kompletně
2. Opravit problém s zálohováním jen www/ místo celého virtual/
3. Otestovat na menší zálohě, zda funguje
4. Přidat lepší error handling a logování

