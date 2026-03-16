'use strict';

// =====================================================================
// Italian Address Parser
// =====================================================================

const PROVINCE_CODES = new Set([
  'AG','AL','AN','AO','AP','AQ','AR','AT','AV','BA','BG','BI','BL','BN','BO',
  'BR','BS','BT','BZ','CA','CB','CE','CH','CL','CN','CO','CR','CS','CT','CZ',
  'EN','FC','FE','FG','FI','FM','FR','GE','GO','GR','IM','IS','KR','LC','LE',
  'LI','LO','LT','LU','MB','MC','ME','MI','MN','MO','MS','MT','NA','NO','NU',
  'OR','PA','PC','PD','PE','PG','PI','PN','PO','PR','PT','PU','PV','PZ','RA',
  'RC','RE','RG','RI','RM','RN','RO','SA','SI','SO','SP','SR','SS','SU','SV',
  'TA','TE','TN','TO','TP','TR','TS','TV','UD','VA','VB','VC','VE','VI','VR',
  'VT','VV'
]);

/**
 * Parse a raw Italian address string into structured fields.
 * Supports formats like:
 *   "Via Roma 1, 20121 Milano MI"
 *   "Piazza della Repubblica 10, 00185 Roma RM"
 *   "Corso Buenos Aires 77 - 20124 Milano (MI)"
 */
function parseItalianAddress(raw) {
  const cleaned = raw.trim().replace(/\s+/g, ' ');

  // Split on comma or dash used as separator between street and location part
  // Try to find where the CAP starts (5 consecutive digits)
  const capMatch = cleaned.match(/(\d{5})\s+([A-Za-zÀ-ú\s']+?)\s*[\(\[]?([A-Z]{2})[\)\]]?\s*$/);

  let streetPart = '';
  let cap = '';
  let city = '';
  let province = '';

  if (capMatch) {
    cap = capMatch[1];
    city = capMatch[2].trim();
    province = capMatch[3].trim().toUpperCase();

    // Everything before the CAP is the street
    const capIndex = cleaned.indexOf(capMatch[0]);
    streetPart = cleaned.slice(0, capIndex).replace(/[,\-–]+$/, '').trim();
  } else {
    // Fallback: try splitting on last comma
    const parts = cleaned.split(',');
    if (parts.length >= 2) {
      streetPart = parts.slice(0, -1).join(',').trim();
      const loc = parts[parts.length - 1].trim();
      const locParts = loc.split(/\s+/);
      if (locParts[0] && /^\d{5}$/.test(locParts[0])) {
        cap = locParts[0];
        const last = locParts[locParts.length - 1].toUpperCase();
        if (PROVINCE_CODES.has(last) && locParts.length > 2) {
          province = last;
          city = locParts.slice(1, -1).join(' ');
        } else {
          city = locParts.slice(1).join(' ');
        }
      } else {
        streetPart = cleaned;
      }
    } else {
      streetPart = cleaned;
    }
  }

  // Parse street name and civic number
  const streetMatch = streetPart.match(/^(.+?)\s+(\d+[\w/]*)$/);
  let streetName = streetPart;
  let civicNumber = '';
  if (streetMatch) {
    streetName = streetMatch[1].trim();
    civicNumber = streetMatch[2].trim();
  }

  // Validate province
  if (province && !PROVINCE_CODES.has(province)) province = '';

  return {
    raw: cleaned,
    streetName,
    civicNumber,
    cap,
    city,
    province,
    formatted: [
      [streetName, civicNumber].filter(Boolean).join(' '),
      cap,
      city,
      province
    ].filter(Boolean).join(', ')
  };
}

// =====================================================================
// API Keys storage (sessionStorage only - never persisted to server)
// =====================================================================

const KEYS = {
  google: () => sessionStorage.getItem('avc_google_key') || '',
  here:   () => sessionStorage.getItem('avc_here_key') || '',
};

function saveKey(provider) {
  const input = document.getElementById(`${provider}-key`);
  if (input && input.value.trim()) {
    sessionStorage.setItem(`avc_${provider}_key`, input.value.trim());
    input.value = '';
    showToast(`Chiave ${provider} salvata per questa sessione`);
  }
}

function showToast(msg) {
  const t = document.createElement('div');
  t.textContent = msg;
  Object.assign(t.style, {
    position: 'fixed', bottom: '20px', left: '50%', transform: 'translateX(-50%)',
    background: '#1e293b', color: '#fff', padding: '10px 20px',
    borderRadius: '8px', fontSize: '0.88rem', zIndex: 9999,
    boxShadow: '0 4px 12px rgba(0,0,0,.2)'
  });
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2500);
}

function toggleConfig() {
  const body = document.getElementById('config-body');
  const arrow = document.getElementById('config-arrow');
  const visible = body.style.display !== 'none';
  body.style.display = visible ? 'none' : 'block';
  arrow.textContent = visible ? '▼' : '▲';
}

// =====================================================================
// Validators — each returns a Promise<ValidatorResult>
// ValidatorResult = { provider, address, lat, lon, confidence, mapUrl, error }
// =====================================================================

async function validateNominatim(parsed) {
  const q = encodeURIComponent(parsed.formatted || parsed.raw);
  const url = `https://nominatim.openstreetmap.org/search?q=${q}&format=json&addressdetails=1&countrycodes=it&limit=3&accept-language=it`;

  const res = await fetch(url, {
    headers: { 'Accept-Language': 'it', 'User-Agent': 'AddressValidationChecker/1.0' }
  });
  const data = await res.json();

  if (!data || data.length === 0) {
    return { provider: 'Nominatim', error: 'Nessun risultato trovato' };
  }

  const best = data[0];
  const addr = best.address || {};
  const parts = [
    (addr.road || '') + (addr.house_number ? ' ' + addr.house_number : ''),
    addr.postcode || '',
    addr.city || addr.town || addr.village || addr.municipality || '',
    addr.state_district || addr.county || ''
  ].filter(Boolean);

  return {
    provider: 'Nominatim',
    address: parts.join(', '),
    fullAddress: best.display_name,
    lat: parseFloat(best.lat),
    lon: parseFloat(best.lon),
    confidence: best.importance ? Math.round(best.importance * 100) : null,
    mapUrl: `https://www.openstreetmap.org/?mlat=${best.lat}&mlon=${best.lon}&zoom=17`,
  };
}

async function validateGoogleMaps(parsed) {
  const key = KEYS.google();
  if (!key) return { provider: 'Google Maps', error: 'Chiave API non configurata' };

  const q = encodeURIComponent(parsed.formatted || parsed.raw);
  // We use a CORS-friendly approach via the Places API (geocoding)
  const url = `https://maps.googleapis.com/maps/api/geocode/json?address=${q}&region=it&language=it&key=${key}`;

  const res = await fetch(url);
  const data = await res.json();

  if (data.status !== 'OK' || !data.results || data.results.length === 0) {
    return { provider: 'Google Maps', error: data.error_message || data.status || 'Nessun risultato' };
  }

  const result = data.results[0];
  const loc = result.geometry.location;

  // Extract components
  const getComp = (type) => {
    const c = (result.address_components || []).find(c => c.types.includes(type));
    return c ? c.long_name : '';
  };
  const getCompShort = (type) => {
    const c = (result.address_components || []).find(c => c.types.includes(type));
    return c ? c.short_name : '';
  };

  const streetNumber = getComp('street_number');
  const route = getComp('route');
  const cap = getComp('postal_code');
  const city = getComp('locality') || getComp('administrative_area_level_3');
  const province = getCompShort('administrative_area_level_2');

  const address = [
    [route, streetNumber].filter(Boolean).join(' '),
    cap, city, province
  ].filter(Boolean).join(', ');

  return {
    provider: 'Google Maps',
    address,
    fullAddress: result.formatted_address,
    lat: loc.lat,
    lon: loc.lng,
    confidence: Math.round((result.geometry.location_type === 'ROOFTOP' ? 1 :
      result.geometry.location_type === 'RANGE_INTERPOLATED' ? 0.8 :
      result.geometry.location_type === 'GEOMETRIC_CENTER' ? 0.6 : 0.4) * 100),
    mapUrl: `https://www.google.com/maps/search/?api=1&query=${loc.lat},${loc.lng}`,
  };
}

async function validateHERE(parsed) {
  const key = KEYS.here();
  if (!key) return { provider: 'HERE Maps', error: 'Chiave API non configurata' };

  const q = encodeURIComponent(parsed.formatted || parsed.raw);
  const url = `https://geocode.search.hereapi.com/v1/geocode?q=${q}&in=countryCode:ITA&lang=it&limit=3&apiKey=${key}`;

  const res = await fetch(url);
  const data = await res.json();

  if (!data.items || data.items.length === 0) {
    return { provider: 'HERE Maps', error: 'Nessun risultato trovato' };
  }

  const item = data.items[0];
  const addr = item.address || {};
  const pos = item.position || {};

  const address = [
    [addr.street, addr.houseNumber].filter(Boolean).join(' '),
    addr.postalCode, addr.city, addr.county
  ].filter(Boolean).join(', ');

  return {
    provider: 'HERE Maps',
    address,
    fullAddress: item.title,
    lat: pos.lat,
    lon: pos.lng,
    confidence: item.scoring ? Math.round(item.scoring.queryScore * 100) : null,
    mapUrl: `https://www.google.com/maps/search/?api=1&query=${pos.lat},${pos.lng}`,
  };
}

async function validatePhoton(parsed) {
  // Photon is Komoot's geocoder based on OpenStreetMap
  const q = encodeURIComponent(parsed.formatted || parsed.raw);
  const url = `https://photon.komoot.io/api/?q=${q}&limit=3&lang=it&bbox=6.6,35.5,18.5,47.2`;

  const res = await fetch(url);
  const data = await res.json();

  if (!data.features || data.features.length === 0) {
    return { provider: 'Photon (Komoot)', error: 'Nessun risultato trovato' };
  }

  const feat = data.features[0];
  const props = feat.properties || {};
  const coords = feat.geometry ? feat.geometry.coordinates : [];

  const address = [
    [props.name || props.street, props.housenumber].filter(Boolean).join(' '),
    props.postcode, props.city || props.town || props.village, props.state
  ].filter(Boolean).join(', ');

  return {
    provider: 'Photon (Komoot)',
    address: address || props.name || '',
    fullAddress: [props.name, props.street, props.housenumber, props.postcode, props.city, props.country].filter(Boolean).join(', '),
    lat: coords[1],
    lon: coords[0],
    confidence: null,
    mapUrl: coords.length >= 2
      ? `https://www.openstreetmap.org/?mlat=${coords[1]}&mlon=${coords[0]}&zoom=17`
      : null,
  };
}

async function validateGovIT(parsed) {
  // Ufficio open data del Ministero dell'Interno / comuni italiani
  // We query the Istat Linked Open Data for comuni, and use a public postal API
  // Here we use the api.address.gov.it style endpoint if available, or fallback to
  // a direct check of the CAP against the free postcodeapi for Italy
  if (!parsed.cap || !parsed.city) {
    return { provider: 'Verifica CAP Italia', error: 'CAP o città mancante' };
  }

  // Use: https://api.zippopotam.us/it/<cap>
  const url = `https://api.zippopotam.us/it/${parsed.cap}`;
  const res = await fetch(url);
  if (!res.ok) {
    return { provider: 'Verifica CAP Italia', error: `CAP ${parsed.cap} non trovato nel database italiano` };
  }

  const data = await res.json();
  const places = data.places || [];
  if (places.length === 0) {
    return { provider: 'Verifica CAP Italia', error: 'Nessun comune trovato per questo CAP' };
  }

  const expectedCity = parsed.city.toLowerCase().trim();
  const match = places.find(p =>
    p['place name'].toLowerCase().includes(expectedCity) ||
    expectedCity.includes(p['place name'].toLowerCase())
  );

  const placeNames = places.map(p => p['place name']).join(' / ');
  const province = places[0]['state abbreviation'] || '';

  return {
    provider: 'Verifica CAP Italia',
    address: match
      ? `${parsed.cap} ${places[0]['place name']} (${province})`
      : null,
    fullAddress: `CAP ${parsed.cap} → ${placeNames} (${data['country abbreviation']})`,
    lat: parseFloat(places[0].latitude),
    lon: parseFloat(places[0].longitude),
    confidence: match ? 95 : 40,
    note: match
      ? `CAP corretto per ${places[0]['place name']}`
      : `Attenzione: il CAP ${parsed.cap} corrisponde a "${placeNames}", non a "${parsed.city}"`,
    mapUrl: null,
    error: match ? null : `Il CAP ${parsed.cap} non corrisponde a "${parsed.city}" ma a "${placeNames}"`,
  };
}

// =====================================================================
// Diff helper — highlights differing tokens
// =====================================================================

function diffAddresses(original, result) {
  if (!result) return '';
  const normO = original.toLowerCase().replace(/[^\w\d]/g, ' ').split(/\s+/).filter(Boolean);
  const normR = result.toLowerCase().replace(/[^\w\d]/g, ' ').split(/\s+/).filter(Boolean);
  const tokens = result.split(/(\s+)/);
  return tokens.map(tok => {
    const t = tok.toLowerCase().replace(/[^\w\d]/g, '');
    if (!t) return tok;
    if (!normO.includes(t)) return `<span class="diff-highlight">${tok}</span>`;
    return tok;
  }).join('');
}

// =====================================================================
// Render helpers
// =====================================================================

const PROVIDER_META = {
  'Nominatim':          { emoji: '🗺️', color: '#3ddc84', bg: '#e8f5e9' },
  'Google Maps':        { emoji: '📍', color: '#EA4335', bg: '#fce8e6' },
  'HERE Maps':          { emoji: '🔵', color: '#0070F3', bg: '#e3f0ff' },
  'Photon (Komoot)':    { emoji: '🌄', color: '#ff6c37', bg: '#fff0eb' },
  'Verifica CAP Italia':{ emoji: '🇮🇹', color: '#009246', bg: '#e8f8ef' },
};

function renderCard(result, parsedInput) {
  const meta = PROVIDER_META[result.provider] || { emoji: '🔍', color: '#6b7280', bg: '#f3f4f6' };
  const hasError = !!result.error;
  const statusClass = hasError ? 'status-error' : (result.confidence && result.confidence < 60 ? 'status-warn' : 'status-ok');
  const statusText  = hasError ? 'Errore' : (result.confidence && result.confidence < 60 ? 'Incerto' : 'OK');

  const diffed = result.address ? diffAddresses(parsedInput.formatted, result.address) : '';

  const confText = result.confidence != null ? `${result.confidence}%` : 'N/A';
  const coordsText = (result.lat && result.lon) ? `${result.lat.toFixed(5)}, ${result.lon.toFixed(5)}` : '—';

  const mapLink = result.mapUrl
    ? `<a href="${result.mapUrl}" target="_blank" rel="noopener">Vedi su mappa</a>`
    : '';

  const noteHtml = result.note
    ? `<div class="error-msg" style="color:#d97706">⚠️ ${result.note}</div>`
    : '';
  const errHtml = (hasError && !result.note)
    ? `<div class="error-msg">❌ ${result.error}</div>`
    : '';

  return `
    <div class="result-card">
      <div class="card-header">
        <div class="provider-info">
          <div class="provider-logo" style="background:${meta.bg}; font-size:1.1rem">${meta.emoji}</div>
          <span class="provider-name">${result.provider}</span>
        </div>
        <span class="status-badge ${statusClass}">${statusText}</span>
      </div>
      <div class="card-body">
        ${result.address
          ? `<div class="result-address">${diffed || result.address}</div>`
          : ''}
        ${result.fullAddress && result.fullAddress !== result.address
          ? `<div class="result-details"><div class="detail-row"><span class="detail-label">Indirizzo completo</span></div><div style="font-size:.8rem;color:#374151;margin-top:2px">${result.fullAddress}</div></div>`
          : ''}
        <div class="result-details" style="margin-top:8px">
          <div class="detail-row">
            <span class="detail-label">Confidenza</span>
            <span class="detail-val">${confText}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Coordinate</span>
            <span class="detail-val">${coordsText}</span>
          </div>
        </div>
        ${noteHtml}
        ${errHtml}
      </div>
      ${mapLink ? `<div class="card-footer">${mapLink}</div>` : ''}
    </div>
  `;
}

function renderParsed(parsed) {
  const fields = [
    { key: 'Strada', val: parsed.streetName },
    { key: 'Civico', val: parsed.civicNumber || '—' },
    { key: 'CAP', val: parsed.cap || '—' },
    { key: 'Comune', val: parsed.city || '—' },
    { key: 'Provincia', val: parsed.province || '—' },
  ];
  return `
    <h3>Indirizzo analizzato</h3>
    <div class="parsed-fields">
      ${fields.map(f => `
        <div class="parsed-field">
          <span class="key">${f.key}</span>
          <span class="val">${f.val}</span>
        </div>
      `).join('')}
    </div>
  `;
}

function renderSummary(results, parsedInput) {
  const valid = results.filter(r => r.address && !r.error);
  if (valid.length === 0) {
    return `<h3>Riepilogo</h3><p style="color:#dc2626">Nessun servizio ha restituito un risultato valido.</p>`;
  }

  // Find most common address (simple majority)
  const freq = {};
  valid.forEach(r => {
    const k = (r.address || '').toLowerCase().replace(/\s+/g, ' ').trim();
    freq[k] = (freq[k] || 0) + 1;
  });
  const topKey = Object.entries(freq).sort((a, b) => b[1] - a[1])[0][0];
  const topResult = valid.find(r => r.address.toLowerCase().replace(/\s+/g, ' ').trim() === topKey);
  const consensusAddress = topResult ? topResult.address : valid[0].address;

  const okCount    = results.filter(r => r.address && !r.error && (r.confidence == null || r.confidence >= 60)).length;
  const warnCount  = results.filter(r => r.address && !r.error && r.confidence != null && r.confidence < 60).length;
  const errorCount = results.filter(r => r.error).length;

  return `
    <h3>Riepilogo</h3>
    <div class="consensus-address">
      <span class="label">Indirizzo più probabile</span>
      ${consensusAddress}
    </div>
    <button class="copy-btn" onclick="copyAddress('${consensusAddress.replace(/'/g, "\\'")}', this)">
      Copia indirizzo
    </button>
    <div class="score-bar">
      <span class="score-item"><span class="dot dot-ok"></span> ${okCount} validato</span>
      <span class="score-item"><span class="dot dot-warn"></span> ${warnCount} incerto</span>
      <span class="score-item"><span class="dot dot-error"></span> ${errorCount} errore/no chiave</span>
    </div>
  `;
}

function copyAddress(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = '✓ Copiato!';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.textContent = 'Copia indirizzo';
      btn.classList.remove('copied');
    }, 2000);
  });
}

// =====================================================================
// Main validator
// =====================================================================

async function validateAddress() {
  const raw = document.getElementById('address-input').value.trim();
  if (!raw) {
    showToast('Inserisci un indirizzo da verificare');
    return;
  }

  const parsed = parseItalianAddress(raw);

  // Show loading
  document.getElementById('loading').style.display = 'flex';
  document.getElementById('results-section').style.display = 'none';

  // Render parsed address immediately
  document.getElementById('parsed-address').innerHTML = renderParsed(parsed);

  // Show loading cards
  const grid = document.getElementById('results-grid');
  const providers = ['Nominatim', 'Google Maps', 'HERE Maps', 'Photon (Komoot)', 'Verifica CAP Italia'];
  grid.innerHTML = providers.map(p => `
    <div class="result-card" id="card-${p.replace(/\s+/g,'-')}">
      <div class="card-header">
        <div class="provider-info">
          <div class="provider-logo">${(PROVIDER_META[p] || {}).emoji || '🔍'}</div>
          <span class="provider-name">${p}</span>
        </div>
        <span class="status-badge status-loading">In corso...</span>
      </div>
      <div class="card-body" style="color:#6b7280;font-size:.88rem">Interrogazione in corso...</div>
    </div>
  `).join('');

  document.getElementById('results-section').style.display = 'block';
  document.getElementById('loading').style.display = 'none';

  // Run all validators concurrently
  const validators = [
    validateNominatim(parsed),
    validateGoogleMaps(parsed),
    validateHERE(parsed),
    validatePhoton(parsed),
    validateGovIT(parsed),
  ];

  const allResults = [];

  // Update each card as results come in
  const settled = await Promise.allSettled(validators);
  settled.forEach((s, i) => {
    const result = s.status === 'fulfilled'
      ? s.value
      : { provider: providers[i], error: s.reason?.message || 'Errore sconosciuto' };

    allResults.push(result);

    const cardId = `card-${result.provider.replace(/\s+/g, '-')}`;
    const card = document.getElementById(cardId);
    if (card) {
      card.outerHTML = renderCard(result, parsed);
    }
  });

  // Render summary
  document.getElementById('summary-box').innerHTML = renderSummary(allResults, parsed);
}

// Allow pressing Enter (Ctrl+Enter) in the textarea
document.addEventListener('DOMContentLoaded', () => {
  const ta = document.getElementById('address-input');
  if (ta) {
    ta.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        validateAddress();
      }
    });
  }

  // Load saved keys hint
  if (KEYS.google()) showToast('Chiave Google già configurata per questa sessione');
  if (KEYS.here())   showToast('Chiave HERE già configurata per questa sessione');
});
