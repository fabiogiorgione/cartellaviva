# PostgreSQL 16 (Alpine, come prima) + estensione pgvector per la ricerca per significato.
# Si resta su Alpine e non si passa all'immagine pgvector/pgvector (Debian): cambiare
# libreria C sotto un database esistente cambia l'ordinamento del testo e
# invaliderebbe gli indici. pgvector si compila dai sorgenti di una versione fissata.
FROM postgres:16-alpine

ARG PGVECTOR_VERSION=0.8.0

# with_llvm=no: niente bitcode per il JIT (non serve e richiederebbe la stessa versione
# di LLVM usata per compilare PostgreSQL). OPTFLAGS vuoto: niente -march=native, così
# l'immagine gira su qualsiasi CPU della stessa architettura.
RUN apk add --no-cache --virtual .build-deps git build-base \
    && git clone --branch "v${PGVECTOR_VERSION}" --depth 1 https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && make -C /tmp/pgvector with_llvm=no OPTFLAGS="" \
    && make -C /tmp/pgvector with_llvm=no install \
    && rm -rf /tmp/pgvector \
    && apk del .build-deps
