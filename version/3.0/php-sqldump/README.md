
# PHP SQL Dump – jednoduchý export MariaDB/MySQL bez SSH

Krátký nástroj (jediný soubor PHP) pro vytvoření **SQL dumpu** databáze MariaDB/MySQL i na hostinzích, kde není k dispozici **SSH** nebo `mysqldump`. Export se provádí přímo v PHP – nejprve se zapíše do souboru na serveru a až po úspěšném dokončení je nabídnut **ke stažení** (jako `.sql.gz` nebo `.sql`).

> **Bezpečnost:** Tato varianta je bez formulářového hesla. Přístup na stránku proto **omezte v `.htaccess` jen na vaši IP** (viz níže). Doporučujeme dump po stažení smazat.

---

## Co to dělá
- Připojí se k databázi (MariaDB/MySQL) a získá seznam tabulek.
- Zapíše **strukturu** (`CREATE TABLE/VIEW`) a **data** (INSERTy) do souboru v `dumps/`.
- Výstup ukládá průběžně (streamuje do souboru), aby se snížila spotřeba paměti na sdíleném hostingu.
- Po dokončení zobrazí **tlačítko ke stažení** a informace o umístění dumpu a logu.
- Volitelně lze exportovat pouze **strukturu bez dat**, nebo vybrat jen **některé tabulky**.

---

## Požadavky
- PHP 7.4+ (funguje i na novějších verzích).
- Rozšíření **mysqli** (pro připojení k DB).
- Pro komprimovaný výstup `.sql.gz` rozšíření **zlib** (jinak nastavte nekomprimovaný výstup).

---

## Rychlá instalace (PHP SQL dump)
1. Zkopírujte soubor `db_dump_.php` do webového prostoru.
2. Vytvořte složku `dumps/` vedle skriptu a nastavte práva pro zápis (např. 0755; v krajním případě 0777 dle hostingu).
3. (Doporučeno) Do `dumps/` přidejte `.htaccess`, aby dumpy nebyly veřejně dostupné (viz níže).
4. Omezte přístup k `db_dump_.php` přes **`.htaccess`** **jen na vaši veřejnou IP** (viz níže).
5. Otevřete `db_dump_.php` v prohlížeči a vyplňte přístupové údaje k DB.

### Struktura složek (příklad)
```
/public_html/
├─ db_dump_.php
├─ dumps/
│  └─ .htaccess   (volitelné – omezení přístupu k souborům dumpu)
└─ .htaccess      (omezení IP pro samotný skript)
```

---

## Konfigurace
V horní části `db_dump_.php` jsou základní proměnné:
```php
$DUMPS_DIR   = __DIR__ . '/dumps'; // kam ukládat výstup
$GZIP_OUTPUT = true;               // true = .sql.gz, false = .sql
$BATCH_SIZE  = 200;                // kolik řádků na jeden INSERT
$LOG_FILE    = __DIR__ . '/dump_error.log'; // kam logovat chyby
```
- Nastavte `$GZIP_OUTPUT = false;` pokud server **nemá zlib** nebo chcete čisté `.sql`.
- Pokud chcete rychlejší menší dávky INSERTů (méně memory), snižte `$BATCH_SIZE` (např. 100).

---

## Jak se používá (PHP SQL dump – bez SSH)
1. Přejděte na `https://vase-domena.tld/db_dump_.php` (musíte být z povolené IP).
2. Vyplňte **Host**, **Port**, **Uživatel**, **Heslo**, **Databáze**.
3. (Volitelné) Do „Tabulky“ uveďte seznam názvů oddělených čárkami (např. `users,orders`). Necháte-li prázdné, exportují se **všechny**.
4. Zaškrtněte „Pouze struktura“, pokud chcete **bez dat**.
5. Klikněte na **Vytvořit dump**.
6. Po dokončení se zobrazí stránka s informacemi a **tlačítkem ke stažení** a také **tlačítkem „Zpět na hlavní stránku“**.

> Pro rychlou diagnostiku prostředí můžete navštívit `db_dump_.php?diag=1` – vypíše dostupnost složky, zlibu, limity atp.

---

## `.htaccess` – omezení přístupu na IP (doporučeno)

### Omezit přístup k `db_dump_.php` (Apache 2.4+)
V kořeni (vedle `db_dump_.php`) vytvořte/aktualizujte `.htaccess`:
```apache
<Files "db_dump_.php">
    Require ip 203.0.113.45
    Require all denied
</Files>
```
*(Nahraďte `203.0.113.45` vaší veřejnou IP. Pro více IP přidejte další `Require ip`.)*

### Alternativa pro starší Apache 2.2
```apache
<Files "db_dump_.php">
    Order Deny,Allow
    Deny from all
    Allow from 203.0.113.45
</Files>
```

### Omezit přístup ke složce `dumps/`
Zajistí, že výsledné soubory dumpu nejsou volně dostupné z internetu.

**Apache 2.4:**
```apache
# dumps/.htaccess
Require ip 203.0.113.45
Require all denied
```

**Apache 2.2:**
```apache
Order Deny,Allow
Deny from all
Allow from 203.0.113.45
```

> Pokud chcete, aby dump **nebyl veřejný vůbec**, použijte v `dumps/.htaccess` pouze:
> ```
> Deny from all
> ```
> a soubor si stáhněte třeba přes správce souborů hostingu nebo krátkodobě povolte IP.

---

## Tipy a omezení
- Na sdíleném hostingu může export velkých DB narazit na **časové limity** (`max_execution_time`) nebo **paměť** (`memory_limit`). Pak vyberte menší sadu tabulek, snižte `$BATCH_SIZE`, nebo export rozdělte na více běhů.
- Export binárních dat (BLOB) je podporován prostým escapováním. Pokud máte extrémně velké binární sloupce, zvažte export jen struktury nebo jiný postup.
- Po stažení dump **smažte** nebo zabezpečte (např. dočasně zákaz v `dumps/.htaccess`).

---

## Řešení problémů
- „Složka dumps není zapisovatelná“ – nastavte práva (např. 0755/0775/0777 dle hostingu) a vlastníka.
- „Chybí zlib“ – nastavte `$GZIP_OUTPUT = false;` a exportujte do `.sql`.
- „Nelze získat seznam tabulek“ – ověřte přístupová práva DB uživatele (`SELECT`, `SHOW VIEW`).
- Po pádu zkontrolujte `dump_error.log` (cesta je vypsaná i na výsledné stránce).

