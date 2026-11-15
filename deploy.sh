#!/usr/bin/env bash
# Deploy na hosting: wysyła bieżący kod (z working tree) do ~/IGAPI, instaluje zależności
# i wgrywa stronę web/ do document root swagnar.dev (WEB_DIR z serwerowego .env, domyślnie ~/swagnar.dev).
# Dane produkcyjne — .env, btc_data.txt, wygenerowane obrazki, web/data/ z porannego runu — zostają nietknięte.
#
#   ./deploy.sh            # deploy
#   ./deploy.sh --run      # deploy + próbne uruchomienie w trybie debug (bez publikacji)
#
# Harmonogram (CRON 06:00) jest ustawiony w cPanelu i wskazuje na $REMOTE_DIR/run.php.
set -euo pipefail

SSH_HOST="${SSH_HOST:-vh13519@v102.vh.net.pl}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
REMOTE_DIR="${REMOTE_DIR:-IGAPI}"                       # względem katalogu domowego
PUBLIC_DIR="${PUBLIC_DIR:-swagnar.dev/ig-stories}"      # obrazki story w document root swagnar.dev
WEB_DIR="${WEB_DIR:-swagnar.dev}"                       # document root swagnar.dev — gdy .env na serwerze nie ma WEB_DIR
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"

cd "$(dirname "$0")"

# Tylko to, czego skrypt potrzebuje w runtime
FILES=(run.php collect-planes.php bootstrap.php stories.json token.php composer.json composer.lock Inter-Regular.ttf Inter-Bold.ttf .env.example src geo slides data sources plugins)

ssh_remote() { ssh -o BatchMode=yes -i "$SSH_KEY" "$SSH_HOST" "$@"; }

echo "==> Wysyłam kod do $SSH_HOST:~/$REMOTE_DIR"
# Najpierw całe archiwum rozpakowuje się do .deploy-new/ — zerwane połączenie nie zostawi serwera z połową kodu.
# Dopiero potem podmiana przez mv (chwila): katalogi w całości, żeby usunięte lokalnie pliki nie zostawały na serwerze.
# Poprzednia wersja zostaje w .deploy-prev/ (ręczny powrót: przenieść jej zawartość z powrotem).
DIRS="src geo slides data sources plugins"
tar -czf - "${FILES[@]}" | ssh_remote "
    set -e
    mkdir -p ~/$REMOTE_DIR
    cd ~/$REMOTE_DIR
    rm -rf .deploy-new
    mkdir .deploy-new
    tar -xzf - -C .deploy-new
    rm -rf .deploy-prev
    mkdir .deploy-prev
    for d in $DIRS; do
        if [ -e \"\$d\" ]; then mv \"\$d\" .deploy-prev/; fi
        mv \".deploy-new/\$d\" .
    done
    for f in \$(ls -A .deploy-new); do
        if [ -e \"\$f\" ]; then cp -p \"\$f\" .deploy-prev/; fi
        mv -f \".deploy-new/\$f\" .
    done
    rmdir .deploy-new
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

# Ta sama ścieżka, której używa run.php: WEB_DIR z .env na serwerze, a bez niego ~/swagnar.dev (= ROOT_DIR/../swagnar.dev)
WEB_PATH=$(ssh_remote "grep -E '^WEB_DIR=' ~/$REMOTE_DIR/.env | tail -n1 | cut -d= -f2- | tr -d \"'\\\"\r \"" || true)
WEB_PATH="${WEB_PATH:-~/$WEB_DIR}"

echo "==> Wysyłam stronę WWW do $SSH_HOST:$WEB_PATH"
# Bez web/data/ — to eksport porannego runu na serwerze. Pliki nadpisujemy, nic nie kasujemy (w document root jest też ig-stories/)
tar -czf - -C web --exclude=./data . | ssh_remote "
    set -e
    mkdir -p $WEB_PATH
    cd $WEB_PATH
    tar -xzf -
"

if [[ "${1:-}" == "--run" ]]; then
    echo "==> Próbne uruchomienie (DEBUG=true, bez publikacji)"
    # Eksport strony idzie do prawdziwego document root, żeby wynik próbnego runu był widoczny na swagnar.dev
    ssh_remote "cd ~/$REMOTE_DIR && DEBUG=true IGAPI_WEB_DIR=$WEB_PATH $PHP_BIN run.php"
fi

# Ślad dla paska statusu edytora: kiedy i z którego commita poszedł deploy (i czy z niezacommitowanymi zmianami)
mkdir -p .igapi
printf '{"at":%s,"commit":"%s","dirty":%s,"run":%s}\n' \
    "$(date +%s)" "$(git rev-parse --short HEAD 2>/dev/null || true)" \
    "$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')" \
    "$([[ "${1:-}" == "--run" ]] && echo true || echo false)" > .igapi/last-deploy.json

echo "==> Gotowe"
