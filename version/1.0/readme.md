# Zazipování adresáře pomocí PHP



**zip.php** 

zazipuje adresář **/** (root) a poté nabídne stažení zip souboru. Pracuje dávkově po 1000 souborech, aby se počítalo s max_execution_time na 90. Skript běží na základě ajaxového dotazu, na konci po provedení zálohy se stránka reloaduje a nabídne link na stažení zálohy

**delete_backup.php**

slouží pro odstranění zip souboru se zálohou

**index.html**

Obsahuje vstupní stránku a tlačítka na vytvoření zálohy a smazání zálohy

**dump.php**
provede dump SQL databáze v dalším okně. Otevře se formulář pro vložení údajů k db. Po vytvoření zálohy nabídne link na stažení db

**.htaccess**

soubor pro povolení přístupu jen z konkrétní IP

## Bezpečnostní upozornění: 

Doporučuji skripty přidat do složky s náhodným názvem a použít blokování IP pomocí souboru .htaccess přímo v dané složce. Případně hned po stažení zálohy složku s tímto nástrojem smazat. Pro generování náhodného názvu složky slouží bash skript **generuj_nazev.sh** ve složce *generátor* **(tuto složku nekopírujte na FTP!)**

 