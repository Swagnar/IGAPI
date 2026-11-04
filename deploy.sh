#!/usr/bin/env bash
# Deploy na hosting: wysyła bieżący kod (z working tree) do ~/IGAPI i instaluje zależności.
# Dane produkcyjne — .env, btc_data.txt, wygenerowane obrazki — zostają nietknięte.
#
#   ./deploy.sh            # deploy
#   ./deploy.sh --run      # deploy + próbne uruchomienie w trybie debug (bez publikacji)
#
# Harmonogram (CRON 06:00) jest ustawiony w cPanelu i wskazuje na $REMOTE_DIR/run.php.
set -euo pipefail

SSH_HOST="${SSH_HOST:-vh13519@v102.vh.net.pl}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
REMOTE_DIR="${REMOTE_DIR:-IGAPI}"                       # względem katalogu domowego
PUBLIC_DIR="${PUBLIC_DIR:-swagnar.dev/ig-stories}"      # document root swagnar.dev
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"

cd "$(dirname "$0")"

# Tylko to, czego skrypt potrzebuje w runtime
FILES=(run.php token.php composer.json composer.lock Inter-Regular.ttf Inter-Bold.ttf .env.example src geo)

ssh_remote() { ssh -o BatchMode=yes -i "$SSH_KEY" "$SSH_HOST" "$@"; }

echo "==> Wysyłam kod do $SSH_HOST:~/$REMOTE_DIR"
# src/ i geo/ podmieniamy w całości, żeby usunięte lokalnie pliki nie zostawały na serwerze
tar -czf - "${FILES[@]}" | ssh_remote "
    set -e
    mkdir -p ~/$REMOTE_DIR
    cd ~/$REMOTE_DIR
    rm -rf src geo
    tar -xzf -
"

echo "==> Instaluję zależności i przygotowuję katalogi"
ssh_remote "
    set -e
    cd ~/$REMOTE_DIR
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    mkdir -p debug/images ig-stories ~/$PUBLIC_DIR

    if [ ! -f .env ]; then
        echo 'BŁĄD: brak ~/$REMOTE_DIR/.env — skopiuj .env.example i uzupełnij (DEBUG=false na produkcji).' >&2
        exit 1
    fi
    if ! grep -qE '^DEBUG=(false|0)' .env; then
        echo 'UWAGA: .env nie ma DEBUG=false — CRON będzie działał w trybie debug (bez publikacji).' >&2
    fi
    touch btc_data.txt
"

if [[ "${1:-}" == "--run" ]]; then
    echo "==> Próbne uruchomienie (DEBUG=true, bez publikacji)"
    ssh_remote "cd ~/$REMOTE_DIR && DEBUG=true $PHP_BIN run.php"
fi

echo "==> Gotowe"
