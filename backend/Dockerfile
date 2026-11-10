# syntax=docker/dockerfile:1
# Immagine unica per dev e prod: FrankenPHP (Caddy + PHP) serve /app/public.
# Pensata per Docker Compose su VM, ma senza stato locale, quindi portabile su AKS.

FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql intl opcache zip apcu

# OCR: Tesseract (italiano + inglese), poppler per i PDF, ImageMagick (+libheif) per HEIC.
# Tutti usati via pipe stdin/stdout: i contenuti decifrati non toccano il disco.
RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-ita tesseract-ocr-eng poppler-utils imagemagick libheif1 fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

# Cartella dei documenti (montata come volume): proprietà dell'utente applicativo, non leggibile da altri.
RUN mkdir -p /var/lib/cartellaviva/documents && chown 1000:1000 /var/lib/cartellaviva/documents && chmod 750 /var/lib/cartellaviva/documents

# Limiti di PHP per gli upload allineati ad app.document_max_bytes (20 MB): con i valori
# predefiniti (2 MB a file, 8 MB a richiesta) PHP scarta il file prima che arrivi all'app.
# Un po' di margine, così oltre i 20 MB risponde il controllo dell'app con il suo messaggio.
RUN printf 'upload_max_filesize = 21M\npost_max_size = 22M\n' > "$PHP_INI_DIR/conf.d/zz-cartellaviva-upload.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Porta > 1024 e directory Caddy in /tmp: il container gira come utente non root.
ENV SERVER_NAME=":8080" \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data \
    COMPOSER_HOME=/tmp/composer

WORKDIR /app

FROM base AS dev
ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
# Solo in sviluppo: copertura dei test, SPENTA di default. Accesa, pcov strumenta ogni
# richiesta e con i thread di FrankenPHP manda in segfault il server sotto carico (bastano
# poche richieste in parallelo). Per la copertura:
#   php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
RUN install-php-extensions pcov && printf 'pcov.enabled = 0\n' > "$PHP_INI_DIR/conf.d/zz-pcov.ini"

# App web (Flutter) compilata per l'immagine di produzione. Il codice arriva dal contesto
# "frontend" (compose.prod.yaml: additional_contexts), non da questa cartella.
# L'indirizzo dell'API entra nell'app al momento della build: un'immagine per dominio.
FROM ghcr.io/cirruslabs/flutter:3.44.0 AS web
WORKDIR /src
COPY --from=frontend pubspec.yaml pubspec.lock ./
RUN flutter pub get
COPY --from=frontend . .
ARG API_BASE_URL
ARG GOOGLE_WEB_CLIENT_ID=""
RUN test -n "$API_BASE_URL" || { echo "Manca API_BASE_URL (es. https://app.example.org/api)" >&2; exit 1; } \
    && flutter build web --release \
        --dart-define=API_BASE_URL="$API_BASE_URL" \
        --dart-define=GOOGLE_WEB_CLIENT_ID="$GOOGLE_WEB_CLIENT_ID"

FROM base AS prod
# Certificati e configurazione di Caddy in /data e /config (volumi), non in /tmp:
# in /tmp (tmpfs) si perderebbero a ogni riavvio e Let's Encrypt va richiesto da capo.
ENV APP_ENV=prod \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY composer.json composer.lock symfony.lock ./
# --ignore-platform-req=php: devnix/belfiore-code 0.1 (solo dati) dichiara PHP 7.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-req=php
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && chmod -R a+rwX var
COPY frankenphp/Caddyfile.prod /etc/frankenphp/Caddyfile
COPY --from=web /src/build/web /srv/web
# Porte 80 e 443 senza root. Cartelle dei volumi (certificati, chiavi JWT) dell'utente 1000:
# un volume nuovo prende proprietario e permessi dalla cartella dell'immagine.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy config/jwt \
    && chown -R 1000:1000 /data /config config/jwt \
    && chmod 700 config/jwt
USER 1000:1000
