# Backup nástroje (PHP + Bash)

Tento projekt obsahuje jednoduché nástroje pro **zálohování na sdíleném webhostingu bez SSH přístupu**.  
Najdeš zde jak skripty v PHP, tak i pomocné Bash skripty. Každý nástroj má vlastní složku s detailním návodem v `README.md`.

---

## 📂 [php-zip](php-zip)
Projekt pro **zazipování obsahu na FTP**.  
- PHP skript i Bash skript pro vytvoření zálohy souborů.  
- Možnost generování a použití tokenu pro spuštění.  
- Detailní návod najdeš v [`php-zip/README.md`](php-zip/php/README.md).

---

## 📂 [php-sqldump](php-sqldump)
Nástroj pro **vytvoření SQL dumpu databáze**.  
- Jednoduchý PHP skript, který provede export databáze do `.sql.gz`.  
- Ochrana přístupu přes `.htaccess` (omezení na IP).  
- Přehledná stránka s informací o hotovém dumpu a tlačítkem zpět na hlavní stránku.  
- Detailní návod najdeš v [`php-sqldump/README.md`](php-sqldump/README.md).



---

✍️ Na projektu se částečně podílel i umělý inteligent... totiž **umělá inteligence** 🤖 (což vysvětluje, proč to občas píše lepší komentáře než já).