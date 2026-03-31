# WhatsApp Auto Responder — Estensione Chrome

Risponditore automatico intelligente per WhatsApp Web che usa Claude (Anthropic) o OpenAI per generare risposte.

## Installazione

### 1. Scarica / clona il progetto
Assicurati di avere la cartella `whatsapp-auto-responder/` sul tuo computer.

### 2. Installa in Chrome
1. Apri Chrome e vai a `chrome://extensions/`
2. Attiva la modalità **Sviluppatore** (interruttore in alto a destra)
3. Clicca **"Carica estensione non pacchettizzata"**
4. Seleziona la cartella `whatsapp-auto-responder/`
5. L'estensione apparirà nella barra degli strumenti di Chrome

### 3. Ottieni un'API Key
- **Claude (Anthropic):** https://console.anthropic.com/
- **OpenAI:** https://platform.openai.com/api-keys

### 4. Configura
1. Apri **https://web.whatsapp.com** e scansiona il QR code
2. Clicca sull'icona dell'estensione 🤖 nella toolbar di Chrome
3. Seleziona il provider AI (Claude o OpenAI)
4. Incolla la tua API Key
5. Personalizza le istruzioni per l'AI (opzionale)
6. Imposta il ritardo risposta (consigliato: 2-7 secondi)
7. Clicca **"Salva impostazioni"**
8. Attiva l'interruttore in alto a destra
9. Un badge verde `🤖 Auto ON` apparirà in basso a destra su WhatsApp Web

## Come funziona

- L'estensione monitora i nuovi messaggi in arrivo su WhatsApp Web
- Quando arriva un messaggio, aspetta un tempo casuale (per sembrare umano)
- Invia il messaggio + il contesto della conversazione all'AI
- L'AI genera una risposta in modo naturale
- La risposta viene automaticamente inviata

## Impostazioni

| Parametro | Descrizione |
|-----------|-------------|
| Provider | Claude o OpenAI |
| Modello | Qualità vs costo della risposta |
| Istruzioni AI | Come deve comportarsi l'assistente |
| Ritardo min/max | Secondi di attesa prima di rispondere |

## Modelli consigliati

| Modello | Velocità | Costo | Qualità |
|---------|----------|-------|---------|
| Claude Haiku | ⚡⚡⚡ | $ | ⭐⭐⭐ |
| Claude Sonnet | ⚡⚡ | $$ | ⭐⭐⭐⭐ |
| GPT-4o Mini | ⚡⚡⚡ | $ | ⭐⭐⭐ |
| GPT-4o | ⚡⚡ | $$ | ⭐⭐⭐⭐ |

## Note importanti

- L'estensione risponde **solo ai messaggi in arrivo**, non invia messaggi spontanei
- Funziona solo mentre WhatsApp Web è aperto nel browser
- Le API Key sono salvate localmente nel tuo browser (storage Chrome)
- Il ritardo casuale rende le risposte più naturali e meno identificabili come automatiche

## Aggiunta icone (opzionale)

Per personalizzare l'icona dell'estensione nella toolbar di Chrome:
1. Crea la cartella `icons/` dentro `whatsapp-auto-responder/`
2. Aggiungi file PNG: `icon16.png`, `icon48.png`, `icon128.png`
3. Aggiungi in `manifest.json`:
```json
"icons": {
  "16": "icons/icon16.png",
  "48": "icons/icon48.png",
  "128": "icons/icon128.png"
}
```
