# Sicurezza

CartellaViva custodisce documenti personali, anche sensibili. Una vulnerabilità segnalata bene vale più di molte righe di codice: grazie se ne hai trovata una.

## Come segnalare una vulnerabilità

**Non aprire una issue pubblica** e non descrivere il problema in una pull request o in una discussione: resterebbe visibile a tutti prima che ci sia una correzione.

Usa la segnalazione privata di GitHub:

1. apri la scheda **Security** del repository;
2. scegli **Report a vulnerability**;
3. compila il modulo. Lo legge solo chi mantiene il progetto.

Link diretto: <https://github.com/fabiogiorgione/cartellaviva/security/advisories/new>

Se il pulsante non compare, apri una issue che chieda soltanto un contatto privato per una segnalazione di sicurezza, **senza alcun dettaglio** del problema.

## Cosa scrivere

- cosa permette di fare il problema e a chi (utente anonimo, utente di un'altra famiglia, operatore di un ente, amministratore);
- i passi per riprodurlo sull'ambiente di sviluppo, con gli utenti di demo;
- il commit o la versione su cui l'hai provato;
- se la conosci, una proposta di correzione.

## Cosa interessa di più

- lettura o modifica dei documenti di un'altra famiglia o di un altro ente (isolamento tra tenant, permessi);
- cifratura dei documenti sensibili e gestione delle chiavi ([docs/gestione-chiavi.md](docs/gestione-chiavi.md));
- accesso: password, verifica in due passaggi, token, recupero dell'account, accesso d'emergenza;
- link pubblici con token: condivisioni esterne, risposte senza account, esportazioni, firma con codice;
- documenti o testo estratto che escono dal server verso servizi esterni.

I limiti già noti sono elencati nel [README](README.md#limiti-noti-e-da-fare): non serve segnalarli di nuovo.

## Come provare

- Prova solo su una tua installazione, con i dati di demo. Non usare dati reali di altre persone.
- Non provare su installazioni di altri senza il loro permesso.
- Niente prove che degradano il servizio altrui (carico, invio massivo di email).

## Cosa aspettarsi

Il progetto è mantenuto da una persona sola: non ci sono tempi di risposta garantiti. Le segnalazioni vengono lette tutte.

## Versioni seguite

Le correzioni di sicurezza si applicano al ramo `main`. Non ci sono versioni precedenti mantenute.
