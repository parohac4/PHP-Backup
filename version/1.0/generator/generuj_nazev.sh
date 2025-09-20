#!/bin/bash

# Nastavení délky názvu
delka=10

# Generování náhodného názvu
nazev=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c $delka)

# Výstup vygenerovaného názvu
echo "Vygenerovaný název: $nazev"
