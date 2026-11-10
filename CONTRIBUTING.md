# Contribuire a CartellaViva

Grazie dell'interesse. Questa pagina dice cosa si può fare oggi e cosa non ancora.

## Le pull request esterne non sono ancora accettate

Per accettare codice scritto da altri serve un accordo di licenza del contributore (CLA), che è in preparazione. Finché non è pubblicato, le pull request di chi non mantiene il progetto vengono chiuse senza essere unite, anche quando sono buone: non è un giudizio sul lavoro.

Quando il CLA sarà pronto, questa pagina spiegherà come firmarlo e come proporre modifiche.

## Cosa puoi fare adesso

- **Segnalare un difetto**: apri una issue con i passi per riprodurlo, cosa ti aspettavi e cosa è successo, la versione o il commit, e dove gira l'app (web, Android, iOS).
- **Proporre un'idea**: apri una issue che descriva il bisogno prima della soluzione.
- **Segnalare una vulnerabilità**: mai con una issue pubblica. Segui [SECURITY.md](SECURITY.md).
- **Provare il progetto** e dire dove l'installazione o la documentazione non tornano: anche questo è un contributo.

Nelle issue non incollare documenti, schermate o log con dati personali reali, tuoi o di altri. Usa gli utenti di demo.

## Provare il progetto in locale

I passi sono nel [README](README.md#avvio-sviluppo) e, in dettaglio, in [docs/avvio-sviluppo.md](docs/avvio-sviluppo.md). In breve:

```bash
bash scripts/setup-sviluppo.sh     # container, dipendenze, database e dati di demo
cd frontend && flutter run -d chrome --web-port 5000 \
  --dart-define=API_BASE_URL=http://localhost:8090/api
```

## Controlli

Si lanciano così:

```bash
docker compose exec php php bin/phpunit           # test del backend
docker compose exec php vendor/bin/phpstan        # analisi statica del backend
cd frontend && flutter analyze && flutter test    # analisi e test del frontend
```

## Come è scritto il codice

Serve a leggere il codice oggi e varrà per i contributi quando saranno aperti.

- **Lingua**: nomi del dominio, commenti e testi dell'interfaccia sono in italiano.
- **Backend**: un modulo per area (`Account`, `Documenti`, `Ente`, `Scadenze`, `Tenancy`...). I controller restano sottili: la logica sta nei servizi, le query nei repository. Gli errori di dominio sono in `Shared\Errore`.
- **Frontend**: una cartella per funzione in `lib/features`, con `api`, `controller`, `model` e `view`. I colori si leggono dal tema (`context.colori`), non si scrivono nei widget.
- **Test**: una modifica al comportamento arriva con il suo test.
- **Missione**: nessuna chiamata a servizi di AI esterni e nessun documento che esce dal server. I quattro impegni del [README](README.md#i-quattro-impegni) valgono per ogni modifica.

## Licenza

Il codice è distribuito con licenza [AGPL-3.0-or-later](LICENSE). Il nome CartellaViva non è coperto dalla licenza del codice: vedi "Licenza e marchio" nel README.
