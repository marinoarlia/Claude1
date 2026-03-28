// background.js — Service Worker per le chiamate API AI

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message.type === 'GET_AI_RESPONSE') {
    handleAIRequest(message)
      .then(sendResponse)
      .catch(err => sendResponse({ error: err.message }));
    return true; // Mantiene aperto il canale per la risposta asincrona
  }
});

async function handleAIRequest({ config, messageText, context }) {
  if (!messageText || !messageText.trim()) {
    return { error: 'Testo messaggio vuoto' };
  }

  if (config.provider === 'claude') {
    if (!config.claudeApiKey) return { error: 'API key Claude mancante' };
    return await callClaude(config, messageText, context);
  } else if (config.provider === 'openai') {
    if (!config.openaiApiKey) return { error: 'API key OpenAI mancante' };
    return await callOpenAI(config, messageText, context);
  }

  return { error: 'Provider AI non configurato' };
}

async function callClaude(config, messageText, context) {
  // Costruisce la cronologia messaggi filtrando eventuali vuoti
  const messages = [
    ...context
      .filter(m => m.content && m.content.trim())
      .map(m => ({ role: m.role, content: m.content })),
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
      max_tokens: 500,
      system: config.systemPrompt,
      messages
    })
  });

  if (!response.ok) {
    const errBody = await response.text();
    throw new Error(`Claude API ${response.status}: ${errBody}`);
  }

  const data = await response.json();
  const text = data.content?.[0]?.text;
  if (!text) throw new Error('Risposta Claude vuota');
  return { text };
}

async function callOpenAI(config, messageText, context) {
  const messages = [
    { role: 'system', content: config.systemPrompt },
    ...context
      .filter(m => m.content && m.content.trim())
      .map(m => ({ role: m.role, content: m.content })),
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
      max_tokens: 500,
      messages
    })
  });

  if (!response.ok) {
    const errBody = await response.text();
    throw new Error(`OpenAI API ${response.status}: ${errBody}`);
  }

  const data = await response.json();
  const text = data.choices?.[0]?.message?.content;
  if (!text) throw new Error('Risposta OpenAI vuota');
  return { text };
}
