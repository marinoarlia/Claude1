// background.js — Service Worker per le chiamate API AI

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message.type === 'GET_AI_RESPONSE') {
    handleAIRequest(message)
      .then(sendResponse)
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }
});

async function handleAIRequest({ config, messageText, context }) {
  if (!messageText || !messageText.trim()) return { error: 'Testo messaggio vuoto' };

  const systemPrompt = buildSystemPrompt(config);

  if (config.provider === 'claude') {
    if (!config.claudeApiKey) return { error: 'API key Claude mancante' };
    return await callClaude(config, systemPrompt, messageText, context);
  } else if (config.provider === 'openai') {
    if (!config.openaiApiKey) return { error: 'API key OpenAI mancante' };
    return await callOpenAI(config, systemPrompt, messageText, context);
  }

  return { error: 'Provider AI non configurato' };
}

/**
 * Costruisce il system prompt completo partendo dal profilo negozio configurato.
 * Se l'utente ha inserito un prompt manuale, usa quello.
 */
function buildSystemPrompt(config) {
  if (config.useManualPrompt && config.manualSystemPrompt?.trim()) {
    return config.manualSystemPrompt.trim();
  }

  const name     = config.businessName?.trim()      || 'questo negozio';
  const sector   = config.businessSector?.trim()    || 'e-commerce';
  const products = config.productsDescription?.trim();
  const shipping = config.shippingPolicy?.trim();
  const discount = config.discountPolicy?.trim();
  const extra    = config.extraInfo?.trim();
  const escalation = config.escalationMessage?.trim()
    || 'Ti ricontatteremo al più presto con informazioni più precise. Grazie per la pazienza!';

  let prompt = `Sei l'assistente virtuale di "${name}", ${sector}.
Il tuo compito è rispondere ai messaggi WhatsApp dei clienti in modo professionale, cordiale e conciso.

`;

  if (products) prompt += `PRODOTTI E SERVIZI:\n${products}\n\n`;
  if (shipping) prompt += `POLITICA SPEDIZIONI E CONSEGNE:\n${shipping}\n\n`;
  if (discount) prompt += `POLITICA SCONTI E PREZZI:\n${discount}\n\n`;
  if (extra)    prompt += `INFORMAZIONI AGGIUNTIVE:\n${extra}\n\n`;

  prompt += `REGOLE DI COMPORTAMENTO:
- Rispondi SEMPRE in italiano, in modo conciso (max 3-4 righe per risposta).
- Sii cordiale e professionale, usa "tu" con il cliente.
- Per domande sul TRACKING di un ordine: se il cliente non ha fornito il numero d'ordine, chiedilo. Se lo ha fornito, spiega come visualizzare il tracking o prometti di verificare.
- Per domande sulla DISPONIBILITÀ di prodotti specifici che non conosci: non inventare, usa il messaggio di escalation.
- Per richieste di PREVENTIVO complesse o personalizzate: usa il messaggio di escalation.
- Per richieste di SCONTO: attieniti alla politica sconti configurata. Se non c'è una politica chiara, declina gentilmente.
- Per domande su SPECIFICHE TECNICHE o SCHEDE TECNICHE: se non le hai, usa il messaggio di escalation.
- Per domande su DIMENSIONI o QUANTITÀ (es: quanti metri coprono): rispondi solo se hai l'informazione, altrimenti usa l'escalation.
- Per TEMPI DI CONSEGNA: usa la politica spedizioni configurata. Se non è definita, dai una stima generica.
- Non confermare mai disponibilità, prezzi o spedizioni che non conosci con certezza.
- Non rivelare di essere un'intelligenza artificiale.
- Se non sei sicuro di come rispondere, usa SEMPRE il messaggio di escalation piuttosto che inventare informazioni.

MESSAGGIO DI ESCALATION (usalo quando non puoi dare una risposta precisa):
"${escalation}"`;

  return prompt;
}

// ─── Claude API ──────────────────────────────────────────────────────────────

async function callClaude(config, systemPrompt, messageText, context) {
  const messages = [
    ...context.filter(m => m.content?.trim()).map(m => ({ role: m.role, content: m.content })),
    { role: 'user', content: messageText }
  ];

  const response = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': config.claudeApiKey,
      'anthropic-version': '2023-06-01'
    },
    body: JSON.stringify({
      model: config.claudeModel || 'claude-haiku-4-5-20251001',
      max_tokens: 400,
      system: systemPrompt,
      messages
    })
  });

  if (!response.ok) {
    const err = await response.text();
    throw new Error(`Claude API ${response.status}: ${err}`);
  }

  const data = await response.json();
  const text = data.content?.[0]?.text;
  if (!text) throw new Error('Risposta Claude vuota');
  return { text };
}

// ─── OpenAI API ──────────────────────────────────────────────────────────────

async function callOpenAI(config, systemPrompt, messageText, context) {
  const messages = [
    { role: 'system', content: systemPrompt },
    ...context.filter(m => m.content?.trim()).map(m => ({ role: m.role, content: m.content })),
    { role: 'user', content: messageText }
  ];

  const response = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.openaiApiKey}`
    },
    body: JSON.stringify({
      model: config.openaiModel || 'gpt-4o-mini',
      max_tokens: 400,
      messages
    })
  });

  if (!response.ok) {
    const err = await response.text();
    throw new Error(`OpenAI API ${response.status}: ${err}`);
  }

  const data = await response.json();
  const text = data.choices?.[0]?.message?.content;
  if (!text) throw new Error('Risposta OpenAI vuota');
  return { text };
}
