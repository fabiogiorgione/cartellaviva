# Email

Tutte le email dell'applicazione partono da `App\Shared\Mail\Posta::invia()` e hanno lo stesso aspetto: fascia verde con il logo, testo in un riquadro bianco su fondo verde chiaro, piè di pagina centrato.

## Regola di contenuto

Mai dati sensibili nell'email: solo il fatto e il link per vederlo nell'app. Titoli dei documenti, testi delle richieste, nomi di persone fuori dalla famiglia restano nell'app.

## Come è fatta

- `backend/templates/emails/_layout.html.twig`: il layout comune. Ogni template lo estende e riempie i blocchi:
  - `contenuto`: il testo, in paragrafi `<p>`;
  - `nota` (facoltativo): la riga in piccolo in fondo al riquadro ("Se non sei stato tu…");
  - `perche` (facoltativo): perché si riceve l'email. Di base: "hai un account su CartellaViva". Va cambiato per chi può non avere un account (inviti, contatti d'emergenza, enti, referenti che rispondono dal link);
  - `titolo` (facoltativo): di base è l'oggetto dell'email, che `Posta` passa al layout come `oggetto`.
- `backend/templates/emails/_componenti.html.twig`: le macro `bottone(url, testo, pericolo)`, `codice(codice)` e `riquadro(testo, pericolo)`. "Pericolo" è il rosso, per le azioni che fermano qualcosa ("Mi oppongo", "Annulla").
- Il piè di pagina ripete sempre "Nessuno di CartellaViva ti chiederà mai la password o i codici." e il link all'app (`FRONTEND_URL`).

Un template nuovo:

```twig
{% extends 'emails/_layout.html.twig' %}
{% import 'emails/_componenti.html.twig' as cv %}

{% block contenuto %}
<p>Ciao {{ nome }},</p>
<p>…</p>
{{ cv.bottone(url, 'Apri CartellaViva') }}
{% endblock %}

{% block nota %}Se non te lo aspettavi, …{% endblock %}
```

`EmailLayoutTest` controlla ogni template di `templates/emails/` (logo, piè di pagina, niente risorse da internet), quindi un template nuovo va aggiunto anche al contesto di prova se usa variabili nuove.

## Scelte per la compatibilità

- Tabelle e stili in linea, larghezza 600 px: Outlook e molte app ignorano il CSS moderno.
- Il logo è un PNG **allegato** all'email (`templates/emails/immagini/`, richiamato con `cid:`), non scaricato da internet: si vede anche dove le immagini remote sono bloccate e l'apertura dell'email non avvisa nessun server. Il nome "CartellaViva" accanto al logo è testo, quindi si legge comunque.
- Nessun carattere da internet: si usa Plus Jakarta Sans solo se è installato sul dispositivo, altrimenti Helvetica o Arial.
- Il blocco `<style>` aggiunge solo il tema scuro (Apple Mail, Mail di iPhone, Outlook per Mac) e i margini ridotti sul telefono. Dove non è supportato l'email resta chiara e leggibile.
- La versione solo testo è generata da Symfony dall'HTML (senza il CSS).

## Provare

In sviluppo le email arrivano a Mailpit: <http://localhost:8025>.

I PNG del logo vengono da `frontend/assets/brand/mark.svg`: quello in fascia è la versione bianca (cartella bianca, germoglio giallo, tasca `#CFE3DA`) a 120×100, quello nel piè di pagina è a colori a 66×55 (3× la misura mostrata).
