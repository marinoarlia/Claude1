// popup.js

const DEFAULT_CONFIG = {
  enabled: false,
  provider: 'claude',
  claudeApiKey: '',
  claudeModel: 'claude-haiku-4-5-20251001',
  openaiApiKey: '',
  openaiModel: 'gpt-4o-mini',
  minDelay: 2,
  maxDelay: 7,

  // Profilo negozio
  businessName: '',
  businessSector: '',
  productsDescription: '',
  shippingPolicy: '',
  discountPolicy: '',
  extraInfo: '',

  // Comportamento
  escalationMessage: 'Ti ricontatteremo al più presto con tutte le informazioni. Grazie per la pazienza!',
  useManualPrompt: false,
  manualSystemPrompt: ''
};

// ─── Elementi DOM ─────────────────────────────────────────────────────────────
const $ = id => document.getElementById(id);

const el = {
  toggleEnabled:      $('toggleEnabled'),
  statusBar:          $('statusBar'),
  statusText:         $('statusText'),
  provider:           $('provider'),
  claudeSection:      $('claudeSection'),
  openaiSection:      $('openaiSection'),
  claudeApiKey:       $('claudeApiKey'),
  claudeModel:        $('claudeModel'),
  openaiApiKey:       $('openaiApiKey'),
  openaiModel:        $('openaiModel'),
  minDelay:           $('minDelay'),
  maxDelay:           $('maxDelay'),
  businessName:       $('businessName'),
  businessSector:     $('businessSector'),
  productsDescription:$('productsDescription'),
  shippingPolicy:     $('shippingPolicy'),
  discountPolicy:     $('discountPolicy'),
  extraInfo:          $('extraInfo'),
  escalationMessage:  $('escalationMessage'),
  useManualPrompt:    $('useManualPrompt'),
  manualPromptArea:   $('manualPromptArea'),
  manualSystemPrompt: $('manualSystemPrompt'),
  promptPreview:      $('promptPreview'),
  saveBtn:            $('saveBtn'),
  resetBtn:           $('resetBtn'),
  toast:              $('toast'),
};

// ─── Carica configurazione ────────────────────────────────────────────────────
chrome.storage.sync.get(['config'], (result) => {
  const cfg = result.config ? { ...DEFAULT_CONFIG, ...result.config } : DEFAULT_CONFIG;
  applyToUI(cfg);
  updatePromptPreview(cfg);
});

// ─── Applica config all'UI ────────────────────────────────────────────────────
function applyToUI(cfg) {
  el.toggleEnabled.checked        = cfg.enabled;
  el.provider.value               = cfg.provider;
  el.claudeApiKey.value           = cfg.claudeApiKey;
  el.claudeModel.value            = cfg.claudeModel;
  el.openaiApiKey.value           = cfg.openaiApiKey;
  el.openaiModel.value            = cfg.openaiModel;
  el.minDelay.value               = cfg.minDelay;
  el.maxDelay.value               = cfg.maxDelay;
  el.businessName.value           = cfg.businessName;
  el.businessSector.value         = cfg.businessSector;
  el.productsDescription.value    = cfg.productsDescription;
  el.shippingPolicy.value         = cfg.shippingPolicy;
  el.discountPolicy.value         = cfg.discountPolicy;
  el.extraInfo.value              = cfg.extraInfo;
  el.escalationMessage.value      = cfg.escalationMessage;
  el.useManualPrompt.checked      = cfg.useManualPrompt;
  el.manualSystemPrompt.value     = cfg.manualSystemPrompt;

  updateStatusUI(cfg.enabled);
  updateProviderUI(cfg.provider);
  updateManualPromptUI(cfg.useManualPrompt);
}

// ─── Aggiornamenti UI ─────────────────────────────────────────────────────────
function updateStatusUI(enabled) {
  if (enabled) {
    el.statusBar.className = 'status-bar on';
    el.statusText.textContent = '✅ Attivo — risponde automaticamente a tutti i messaggi';
  } else {
    el.statusBar.className = 'status-bar off';
    el.statusText.textContent = '⏸ Disattivato';
  }
}

function updateProviderUI(provider) {
  el.claudeSection.classList.toggle('hidden', provider !== 'claude');
  el.openaiSection.classList.toggle('hidden', provider !== 'openai');
}

function updateManualPromptUI(enabled) {
  el.manualPromptArea.classList.toggle('hidden', !enabled);
}

// ─── Generazione anteprima prompt ────────────────────────────────────────────
function buildSystemPromptPreview(cfg) {
  if (cfg.useManualPrompt && cfg.manualSystemPrompt?.trim()) {
    return cfg.manualSystemPrompt.trim();
  }

  const name     = cfg.businessName?.trim()       || 'questo negozio';
  const sector   = cfg.businessSector?.trim()     || 'e-commerce';
  const products = cfg.productsDescription?.trim();
  const shipping = cfg.shippingPolicy?.trim();
  const discount = cfg.discountPolicy?.trim();
  const extra    = cfg.extraInfo?.trim();
  const escalation = cfg.escalationMessage?.trim()
    || 'Ti ricontatteremo al più presto. Grazie per la pazienza!';

  let prompt = `Sei l'assistente virtuale di "${name}", ${sector}.\n`;
  prompt += `Rispondi ai messaggi WhatsApp dei clienti in modo professionale e conciso.\n\n`;
  if (products) prompt += `PRODOTTI:\n${products}\n\n`;
  if (shipping) prompt += `SPEDIZIONI:\n${shipping}\n\n`;
  if (discount) prompt += `SCONTI:\n${discount}\n\n`;
  if (extra)    prompt += `ALTRE INFO:\n${extra}\n\n`;
  prompt += `ESCALATION: "${escalation}"`;
  return prompt;
}

function updatePromptPreview(cfg) {
  el.promptPreview.textContent = buildSystemPromptPreview(cfg);
}

function currentConfig() {
  return {
    enabled:             el.toggleEnabled.checked,
    provider:            el.provider.value,
    claudeApiKey:        el.claudeApiKey.value.trim(),
    claudeModel:         el.claudeModel.value,
    openaiApiKey:        el.openaiApiKey.value.trim(),
    openaiModel:         el.openaiModel.value,
    minDelay:            parseFloat(el.minDelay.value),
    maxDelay:            parseFloat(el.maxDelay.value),
    businessName:        el.businessName.value.trim(),
    businessSector:      el.businessSector.value.trim(),
    productsDescription: el.productsDescription.value.trim(),
    shippingPolicy:      el.shippingPolicy.value.trim(),
    discountPolicy:      el.discountPolicy.value.trim(),
    extraInfo:           el.extraInfo.value.trim(),
    escalationMessage:   el.escalationMessage.value.trim(),
    useManualPrompt:     el.useManualPrompt.checked,
    manualSystemPrompt:  el.manualSystemPrompt.value.trim()
  };
}

// ─── Event listeners ──────────────────────────────────────────────────────────

// Tab navigation
document.querySelectorAll('.tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');

    // Aggiorna anteprima quando si apre la tab Risposte
    if (btn.dataset.tab === 'behavior') {
      updatePromptPreview(currentConfig());
    }
  });
});

// Aggiorna live l'anteprima quando si modifica il profilo negozio
['businessName','businessSector','productsDescription','shippingPolicy',
 'discountPolicy','extraInfo','escalationMessage','manualSystemPrompt'].forEach(id => {
  $(id)?.addEventListener('input', () => updatePromptPreview(currentConfig()));
});

el.toggleEnabled.addEventListener('change', () => updateStatusUI(el.toggleEnabled.checked));
el.provider.addEventListener('change', () => updateProviderUI(el.provider.value));
el.useManualPrompt.addEventListener('change', () => {
  updateManualPromptUI(el.useManualPrompt.checked);
  updatePromptPreview(currentConfig());
});

// Mostra/nascondi password
document.querySelectorAll('.eye-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = $(btn.getAttribute('data-target'));
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});

// ─── Salva ───────────────────────────────────────────────────────────────────
el.saveBtn.addEventListener('click', () => {
  const cfg = currentConfig();

  if (isNaN(cfg.minDelay) || isNaN(cfg.maxDelay) || cfg.minDelay < 0 || cfg.maxDelay < cfg.minDelay) {
    showToast('Controlla il ritardo: min deve essere ≤ max e valori positivi', true);
    return;
  }

  if (cfg.enabled) {
    if (cfg.provider === 'claude' && !cfg.claudeApiKey) {
      showToast('Inserisci la API Key Claude prima di attivare', true);
      return;
    }
    if (cfg.provider === 'openai' && !cfg.openaiApiKey) {
      showToast('Inserisci la API Key OpenAI prima di attivare', true);
      return;
    }
  }

  // Converti secondi → millisecondi per l'uso nel content script
  const cfgMs = { ...cfg, minDelay: cfg.minDelay * 1000, maxDelay: cfg.maxDelay * 1000 };

  chrome.storage.sync.set({ config: cfgMs }, () => {
    // Notifica tutte le tab di WhatsApp Web aperte
    chrome.tabs.query({ url: 'https://web.whatsapp.com/*' }, (tabs) => {
      for (const tab of tabs) {
        chrome.tabs.sendMessage(tab.id, { type: 'CONFIG_UPDATE', config: cfgMs }).catch(() => {});
      }
    });
    showToast(cfg.enabled ? '✅ Salvato — risponditore attivo!' : '💾 Impostazioni salvate');
  });
});

// ─── Reset ───────────────────────────────────────────────────────────────────
el.resetBtn.addEventListener('click', () => {
  if (confirm('Ripristinare tutte le impostazioni di default? Le API key verranno cancellate.')) {
    applyToUI(DEFAULT_CONFIG);
    updatePromptPreview(DEFAULT_CONFIG);
    showToast('Impostazioni ripristinate ai valori predefiniti');
  }
});

// ─── Toast ───────────────────────────────────────────────────────────────────
let toastTimer = null;
function showToast(msg, isError = false) {
  el.toast.textContent = msg;
  el.toast.className = `toast${isError ? ' error' : ''}`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.toast.classList.add('hidden'), 3500);
}
