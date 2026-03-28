// content.js — Iniettato su WhatsApp Web

const respondedMessages = new Set();
let mainObserver = null;
let appObserver = null;
let statusBadge = null;

let config = {
  enabled: false,
  provider: 'claude',
  claudeApiKey: '',
  claudeModel: 'claude-haiku-4-5-20251001',
  openaiApiKey: '',
  openaiModel: 'gpt-4o-mini',
  systemPrompt: 'Sei un assistente virtuale che risponde per conto del proprietario di questo numero WhatsApp. Rispondi in modo naturale, conciso e amichevole. Adatta il tono alla conversazione. Non rivelare di essere un\'intelligenza artificiale a meno che non sia strettamente necessario.',
  minDelay: 2000,
  maxDelay: 7000
};

// ─── Inizializzazione ────────────────────────────────────────────────────────

chrome.storage.sync.get(['config'], (result) => {
  if (result.config) config = { ...config, ...result.config };
  init();
});

chrome.runtime.onMessage.addListener((message) => {
  if (message.type === 'CONFIG_UPDATE') {
    config = { ...config, ...message.config };
    if (config.enabled) {
      startObservingApp();
      updateBadge();
    } else {
      stopMainObserver();
      updateBadge();
    }
  }
});

function init() {
  injectBadge();
  if (config.enabled) startObservingApp();
  updateBadge();
}

// ─── Badge visivo ────────────────────────────────────────────────────────────

function injectBadge() {
  if (document.getElementById('war-badge')) return;

  statusBadge = document.createElement('div');
  statusBadge.id = 'war-badge';
  statusBadge.style.cssText = `
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 99999;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    font-family: sans-serif;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    transition: all 0.3s ease;
    user-select: none;
  `;
  statusBadge.title = 'WhatsApp Auto Responder';
  document.body.appendChild(statusBadge);
  updateBadge();
}

function updateBadge() {
  if (!statusBadge) return;
  if (config.enabled) {
    statusBadge.textContent = '🤖 Auto ON';
    statusBadge.style.background = '#25D366';
    statusBadge.style.color = '#fff';
  } else {
    statusBadge.textContent = '🤖 Auto OFF';
    statusBadge.style.background = '#ccc';
    statusBadge.style.color = '#333';
  }
}

// ─── Osservazione del DOM ────────────────────────────────────────────────────

function startObservingApp() {
  // Osserva il body per aspettare che #main (la chat) appaia
  if (appObserver) return;

  appObserver = new MutationObserver(() => {
    const main = document.getElementById('main');
    if (main && !mainObserver) {
      markExistingMessages();
      startMainObserver(main);
    }
  });
  appObserver.observe(document.body, { childList: true, subtree: false });

  // Prova subito nel caso in cui #main esista già
  const main = document.getElementById('main');
  if (main) {
    markExistingMessages();
    startMainObserver(main);
  }
}

function startMainObserver(mainEl) {
  if (mainObserver) mainObserver.disconnect();

  mainObserver = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node.nodeType !== Node.ELEMENT_NODE) continue;
        checkForIncomingMessages(node);
      }
    }
  });

  mainObserver.observe(mainEl, { childList: true, subtree: true });
}

function stopMainObserver() {
  if (mainObserver) {
    mainObserver.disconnect();
    mainObserver = null;
  }
}

// ─── Gestione messaggi ───────────────────────────────────────────────────────

/**
 * Marca tutti i messaggi già presenti come "già visti" al momento
 * dell'attivazione, per non rispondere a messaggi vecchi.
 */
function markExistingMessages() {
  document.querySelectorAll('.message-in').forEach(el => {
    const id = extractMessageId(el);
    if (id) respondedMessages.add(id);
  });
}

function checkForIncomingMessages(node) {
  if (!config.enabled) return;

  const incoming = [];

  if (node.classList?.contains('message-in')) {
    incoming.push(node);
  } else {
    node.querySelectorAll?.('.message-in').forEach(el => incoming.push(el));
  }

  for (const msgEl of incoming) {
    processIncomingMessage(msgEl);
  }
}

function processIncomingMessage(msgEl) {
  const id = extractMessageId(msgEl);
  if (!id || respondedMessages.has(id)) return;

  const text = extractMessageText(msgEl);
  if (!text) return; // Salta messaggi senza testo (audio, immagini, ecc.)

  respondedMessages.add(id);

  const delay = config.minDelay + Math.random() * (config.maxDelay - config.minDelay);

  setTimeout(() => {
    if (!config.enabled) return; // Controllo finale: l'utente potrebbe aver disattivato
    requestAIResponse(text);
  }, delay);
}

// ─── Estrazione dati messaggi ────────────────────────────────────────────────

function extractMessageId(msgEl) {
  // Cerca data-id sull'elemento o nei figli diretti
  if (msgEl.hasAttribute('data-id')) return msgEl.getAttribute('data-id');

  const child = msgEl.querySelector('[data-id]');
  if (child) return child.getAttribute('data-id');

  // Fallback: hash del testo + timestamp visibile
  const text = extractMessageText(msgEl);
  const timestamp = msgEl.querySelector('[data-pre-plain-text]')?.getAttribute('data-pre-plain-text') || '';
  if (text) return btoa(unescape(encodeURIComponent(text.slice(0, 60) + timestamp)));

  return null;
}

function extractMessageText(msgEl) {
  // Tenta diversi selettori che WhatsApp usa per il testo
  const selectors = [
    'span.selectable-text.copyable-text',
    '.copyable-text',
    '[data-testid="msg-container"] span',
    'span[dir="ltr"]',
    'span[dir="rtl"]'
  ];

  for (const sel of selectors) {
    const el = msgEl.querySelector(sel);
    const text = el?.innerText?.trim();
    if (text) return text;
  }
  return null;
}

// ─── Chiamata AI e invio risposta ────────────────────────────────────────────

function requestAIResponse(messageText) {
  const context = buildChatContext();

  chrome.runtime.sendMessage(
    { type: 'GET_AI_RESPONSE', config, messageText, context },
    (response) => {
      if (chrome.runtime.lastError) {
        console.error('[WAR] Errore runtime:', chrome.runtime.lastError.message);
        return;
      }
      if (response?.text) {
        injectAndSendResponse(response.text);
      } else if (response?.error) {
        console.error('[WAR] Errore AI:', response.error);
      }
    }
  );
}

/**
 * Costruisce il contesto della conversazione dagli ultimi messaggi visibili.
 * Utile per dare contesto all'AI.
 */
function buildChatContext() {
  const messages = [];
  const all = document.querySelectorAll('.message-in, .message-out');
  const recent = Array.from(all).slice(-12); // Ultimi 12 messaggi

  for (const el of recent) {
    const text = extractMessageText(el);
    if (!text) continue;
    const role = el.classList.contains('message-out') ? 'assistant' : 'user';
    messages.push({ role, content: text });
  }

  return messages;
}

/**
 * Inietta il testo nella casella di input di WhatsApp e lo invia.
 */
function injectAndSendResponse(text) {
  // Selettori per la casella di testo (WhatsApp cambia spesso i nomi)
  const inputSelectors = [
    '[data-testid="conversation-compose-box-input"]',
    'div[contenteditable="true"][data-tab="10"]',
    'div[contenteditable="true"][title="Scrivi un messaggio"]',
    'div[contenteditable="true"][title="Type a message"]',
    'footer div[contenteditable="true"]'
  ];

  let inputBox = null;
  for (const sel of inputSelectors) {
    inputBox = document.querySelector(sel);
    if (inputBox) break;
  }

  if (!inputBox) {
    console.error('[WAR] Casella di input non trovata');
    return;
  }

  // Focus e inserimento testo
  inputBox.focus();
  document.execCommand('selectAll', false, null);
  document.execCommand('delete', false, null);
  document.execCommand('insertText', false, text);

  // Notifica React/WhatsApp dell'aggiornamento
  inputBox.dispatchEvent(new InputEvent('input', { bubbles: true, cancelable: true }));

  // Piccola pausa prima di inviare per sicurezza
  setTimeout(() => {
    const sendSelectors = [
      '[data-testid="send"]',
      '[data-testid="compose-btn-send"]',
      'button[aria-label="Invia"]',
      'button[aria-label="Send"]',
      'span[data-icon="send"]'
    ];

    let sendBtn = null;
    for (const sel of sendSelectors) {
      sendBtn = document.querySelector(sel);
      if (sendBtn) break;
    }

    if (sendBtn) {
      // Cerca il bottone cliccabile risalendo il DOM se necessario
      let clickTarget = sendBtn;
      if (clickTarget.tagName !== 'BUTTON') {
        clickTarget = sendBtn.closest('button') || sendBtn;
      }
      clickTarget.click();
    } else {
      // Fallback: simula il tasto Invio
      inputBox.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'Enter', code: 'Enter', keyCode: 13, bubbles: true
      }));
    }
  }, 400);
}
