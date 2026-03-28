// popup.js

const DEFAULT_CONFIG = {
  enabled: false,
  provider: 'claude',
  claudeApiKey: '',
  claudeModel: 'claude-haiku-4-5-20251001',
  openaiApiKey: '',
  openaiModel: 'gpt-4o-mini',
  systemPrompt: 'Sei un assistente virtuale che risponde per conto del proprietario di questo numero WhatsApp. Rispondi in modo naturale, conciso e amichevole. Adatta il tono alla conversazione. Non rivelare di essere un\'intelligenza artificiale a meno che non sia strettamente necessario.',
  minDelay: 2,
  maxDelay: 7
};

// ─── Elementi DOM ────────────────────────────────────────────────────────────
const toggleEnabled = document.getElementById('toggleEnabled');
const statusBar     = document.getElementById('statusBar');
const statusText    = document.getElementById('statusText');
const providerSel   = document.getElementById('provider');
const claudeSection = document.getElementById('claudeSection');
const openaiSection = document.getElementById('openaiSection');
const claudeApiKey  = document.getElementById('claudeApiKey');
const claudeModel   = document.getElementById('claudeModel');
const openaiApiKey  = document.getElementById('openaiApiKey');
const openaiModel   = document.getElementById('openaiModel');
const systemPrompt  = document.getElementById('systemPrompt');
const minDelay      = document.getElementById('minDelay');
const maxDelay      = document.getElementById('maxDelay');
const saveBtn       = document.getElementById('saveBtn');
const resetBtn      = document.getElementById('resetBtn');
const toast         = document.getElementById('toast');

// ─── Carica configurazione ───────────────────────────────────────────────────
chrome.storage.sync.get(['config'], (result) => {
  const cfg = result.config ? { ...DEFAULT_CONFIG, ...result.config } : DEFAULT_CONFIG;
  applyConfigToUI(cfg);
});

function applyConfigToUI(cfg) {
  toggleEnabled.checked   = cfg.enabled;
  providerSel.value       = cfg.provider;
  claudeApiKey.value      = cfg.claudeApiKey;
  claudeModel.value       = cfg.claudeModel;
  openaiApiKey.value      = cfg.openaiApiKey;
  openaiModel.value       = cfg.openaiModel;
  systemPrompt.value      = cfg.systemPrompt;
  minDelay.value          = cfg.minDelay;
  maxDelay.value          = cfg.maxDelay;

  updateStatusUI(cfg.enabled);
  updateProviderUI(cfg.provider);
}

// ─── Aggiornamenti UI ────────────────────────────────────────────────────────
function updateStatusUI(enabled) {
  if (enabled) {
    statusBar.className = 'status-bar on';
    statusText.textContent = '✅ Attivo — risponde a tutti i messaggi';
  } else {
    statusBar.className = 'status-bar off';
    statusText.textContent = '⏸ Disattivato';
  }
}

function updateProviderUI(provider) {
  if (provider === 'claude') {
    claudeSection.classList.remove('hidden');
    openaiSection.classList.add('hidden');
  } else {
    claudeSection.classList.add('hidden');
    openaiSection.classList.remove('hidden');
  }
}

// ─── Event listeners ─────────────────────────────────────────────────────────
toggleEnabled.addEventListener('change', () => {
  updateStatusUI(toggleEnabled.checked);
});

providerSel.addEventListener('change', () => {
  updateProviderUI(providerSel.value);
});

// Bottoni mostra/nascondi password
document.querySelectorAll('.eye-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const targetId = btn.getAttribute('data-target');
    const input = document.getElementById(targetId);
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});

// Salva
saveBtn.addEventListener('click', () => {
  const min = parseFloat(minDelay.value);
  const max = parseFloat(maxDelay.value);

  if (isNaN(min) || isNaN(max) || min < 0 || max < min) {
    showToast('Controlla i valori del ritardo (min ≤ max, valori positivi)', true);
    return;
  }

  const provider = providerSel.value;
  if (toggleEnabled.checked) {
    if (provider === 'claude' && !claudeApiKey.value.trim()) {
      showToast('Inserisci la API Key di Claude per continuare', true);
      return;
    }
    if (provider === 'openai' && !openaiApiKey.value.trim()) {
      showToast('Inserisci la API Key di OpenAI per continuare', true);
      return;
    }
  }

  const cfg = {
    enabled: toggleEnabled.checked,
    provider,
    claudeApiKey: claudeApiKey.value.trim(),
    claudeModel: claudeModel.value,
    openaiApiKey: openaiApiKey.value.trim(),
    openaiModel: openaiModel.value,
    systemPrompt: systemPrompt.value.trim() || DEFAULT_CONFIG.systemPrompt,
    minDelay: min * 1000,   // Converte in millisecondi
    maxDelay: max * 1000
  };

  chrome.storage.sync.set({ config: cfg }, () => {
    // Notifica il content script della modifica
    chrome.tabs.query({ url: 'https://web.whatsapp.com/*' }, (tabs) => {
      for (const tab of tabs) {
        chrome.tabs.sendMessage(tab.id, { type: 'CONFIG_UPDATE', config: cfg })
          .catch(() => {}); // Ignora errori se la tab non ha il content script
      }
    });
    showToast(cfg.enabled ? '✅ Salvato — risponditore attivo!' : '💾 Impostazioni salvate');
  });
});

// Ripristina default
resetBtn.addEventListener('click', () => {
  if (confirm('Ripristinare tutte le impostazioni predefinite? Le API key verranno cancellate.')) {
    applyConfigToUI(DEFAULT_CONFIG);
    showToast('Impostazioni ripristinate');
  }
});

// ─── Toast ───────────────────────────────────────────────────────────────────
let toastTimer = null;

function showToast(message, isError = false) {
  toast.textContent = message;
  toast.className = `toast${isError ? ' error' : ''}`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    toast.classList.add('hidden');
  }, 3000);
}
