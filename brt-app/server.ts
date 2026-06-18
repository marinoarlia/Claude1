/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import express from "express";
import path from "path";
import { fileURLToPath } from "url";
import { createServer as createViteServer } from "vite";
import { generateFallbackTracking } from "./src/mockTracking.js";
import { BrtTrackingResult, BrtTrackingEvent } from "./src/types.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// --- BRT SOAP TRACKING CLIENT & PARSER ---
async function fetchBrtSoapTracking(shipmentId: string, lingua: string = "it"): Promise<string> {
  const wsdlUrl = "https://wsr.brt.it:10052/web/BRT_TrackingByBRTshipmentIDService/BRT_TrackingByBRTshipmentID?wdsl";
  
  // Use correct SOAP 1.1 namespace for text/xml and avoid VersionMismatch faults
  const soapRequest = `<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:brt="http://brt_trackingbybrtshipmentid.wsbeans.iseries/"><soapenv:Header/><soapenv:Body><brt:brt_trackingbybrtshipmentid><arg0><SPEDIZIONE_ANNO>0</SPEDIZIONE_ANNO><SPEDIZIONE_BRT_ID>${shipmentId}</SPEDIZIONE_BRT_ID><LINGUA_ISO639_ALPHA2>${lingua}</LINGUA_ISO639_ALPHA2></arg0></brt:brt_trackingbybrtshipmentid></soapenv:Body></soapenv:Envelope>`;

  const response = await fetch(wsdlUrl, {
    method: "POST",
    headers: {
      "Content-Type": "text/xml; charset=utf-8"
    },
    body: soapRequest
  });

  // SOAP standards dictate that a SOAP Fault should be returned with HTTP 500 status code.
  // Therefore, we should read and parse the body even if status is 500.
  if (!response.ok && response.status !== 500) {
    throw new Error(`HTTP Error ${response.status} nell'interrogazione SOAP di BRT`);
  }

  return await response.text();
}

function parseSoapResponse(xmlText: string, parcelID: string): BrtTrackingResult {
  const parseTag = (tag: string): string => {
    const match = xmlText.match(new RegExp(`<${tag}[^>]*>([^<]*)</${tag}>`, "i"));
    return match ? match[1].trim() : "";
  };

  const parseTags = (tag: string): string[] => {
    const regex = new RegExp(`<${tag}[^>]*>([^<]*)</${tag}>`, "gi");
    const matches: string[] = [];
    let m;
    while ((m = regex.exec(xmlText)) !== null) {
      matches.push(m[1].trim());
    }
    return matches;
  };

  const stato_sped_parte1 = parseTag("STATO_SPED_PARTE1") || parseTag("STATO_SPED_PARTE_1");
  const descrizione_stato_sped_parte1 = parseTag("DESCRIZIONE_STATO_SPED_PARTE1") || parseTag("DESCRIZIONE_STATO_SPED_PARTE_1");
  const referenceDpd = parseTag("RIFERIMENTO_PARTNER_ESTERO") || parseTag("RIFERIMENTO_PARTNER");

  const descs = parseTags("DESCRIZIONE");
  const dates = parseTags("DATA");
  const times = parseTags("ORA");
  const branches = parseTags("FILIALE");
  const ids = parseTags("ID") || parseTags("ID_EVENTO") || parseTags("CODICE") || [];

  const formattedEvents: BrtTrackingEvent[] = [];
  const maxEvents = Math.max(descs.length, dates.length, times.length, branches.length);

  for (let i = 0; i < maxEvents; i++) {
    const rawData = dates[i] || "";
    const cleanDate = rawData.replace(/\./g, "-");
    formattedEvents.push({
      data: cleanDate,
      ora: times[i] || "",
      id: ids[i] || (i === 0 && descs[0] === "CONSEGNATA" ? "DEL" : "EVT"),
      descrizione: descs[i] || "",
      filiale: branches[i] || ""
    });
  }

  // Fallback metadata tags returned from SOAP if any
  const denominazioneDest = parseTag("RAGIONE_SOC_DEST") || parseTag("RAGIONE_SOCIALE_DEST") || parseTag("RAGIONE_SOCIALE") || parseTag("DESTINATARIO") || "";
  const viaDest = parseTag("VIA_DEST_RICEV") || parseTag("INDIRIZZO_DEST") || parseTag("INDIRIZZO_DESTINATARIO") || parseTag("VIA_DEST") || "";
  const capDest = parseTag("CAP_DEST_RICEV") || parseTag("CAP_DESTINATARIO") || parseTag("CAP_DEST") || "";
  const locDest = parseTag("LOCALITA_DEST_RICEV") || parseTag("LOCALITA_DESTINATARIO") || parseTag("LOCALITA_DEST") || "";
  const provDest = parseTag("PROVINCIA_DEST_RICEV") || parseTag("SIGLA_PROV_DEST_RICEZIONE") || parseTag("PROVINCIA_DEST") || "";
  const nazDest = parseTag("NAZIONE_DEST_RICEV") || parseTag("SIGLA_NAZ_DEST_RICEZIONE") || parseTag("NAZIONE_DEST") || "IT";
  const telDest = parseTag("TELEFONO_DEST") || parseTag("TELEFONO_DESTINATARIO") || parseTag("TELEFONO_REFERENTE") || parseTag("CELLULARE") || "";

  const denominazioneMitt = parseTag("RAGIONE_SOC_MITT") || parseTag("RAGIONE_SOCIALE_MITTENTE") || parseTag("MITT_RAGIONE_SOCIALE") || parseTag("MITTENTE") || "";
  const viaMitt = parseTag("VIA_MITT") || parseTag("INDIRIZZO_MITTENTE") || "";
  const capMitt = parseTag("CAP_MITT") || parseTag("CAP_MITTENTE") || "";
  const locMitt = parseTag("LOCALITA_MITT") || parseTag("LOCALITA_MITTENTE") || "";
  const provMitt = parseTag("PROV_MITT") || parseTag("SIGLA_PROV_MITTENTE") || "";
  const telMitt = parseTag("TELEFONO_MITT") || "";

  const colli = Number(parseTag("NUMERO_COLLI") || parseTag("COLLI") || "1");
  const peso = Number((parseTag("PESO_SPEDIZIONE") || parseTag("PESO_KG") || parseTag("PESO") || "0.0").replace(",", "."));

  const rifNum = Number(parseTag("RIF_MITTENTE_NUM") || parseTag("RIF_MIT_NUM") || "0");
  const rifAlfa = parseTag("RIF_MITTENTE_ALF") || parseTag("RIF_MIT_ALF") || "";

  const dataTeorica = parseTag("DATA_TEORICA_CONSEGNA") || parseTag("DATA_CONSEGNA_TEORICA") || "";
  const oraDa = parseTag("ORA_CONSEGNA_DA") || "";
  const oraA = parseTag("ORA_CONSEGNA_A") || "";
  const dataConsegnaEffettiva = parseTag("DATA_CONSEGNA_EFFETTIVA") || parseTag("DATA_CONSEGNA") || (descs[0] === "CONSEGNATA" ? dates[0] : "");
  const oraConsegnaEffettiva = parseTag("ORA_CONSEGNA_EFFETTIVA") || (descs[0] === "CONSEGNATA" ? times[0] : "");
  const firmatario = parseTag("FIRMATARIO") || parseTag("FIRMATARIO_CONSEGNA") || "";

  const isSuccess = maxEvents > 0 || stato_sped_parte1 !== "";

  return {
    parcelID: parcelID,
    success: isSuccess,
    code: isSuccess ? 0 : -1,
    severity: "INFO",
    codeDesc: isSuccess ? "SOAP Success" : "No tracking found",
    message: descrizione_stato_sped_parte1 || (isSuccess ? "Spedizione tracciata in tempo reale tramite API SOAP di BRT Pública." : "Spedizione non trovata."),

    // Destinatario
    ragione_sociale: denominazioneDest,
    indirizzo: viaDest,
    cap: capDest,
    localita: locDest,
    sigla_provincia: provDest,
    sigla_nazione: nazDest,
    referente_consegna: "",
    telefono_referente: telDest,

    // Mittente
    ragione_sociale_mittente: denominazioneMitt,
    indirizzo_mittente: viaMitt,
    cap_mittente: capMitt,
    localita_mittente: locMitt,
    sigla_provincia_mittente: provMitt,
    sigla_nazione_mittente: "IT",
    telefono_mittente: telMitt,

    // Merce
    colli: colli,
    peso_kg: peso,

    // Riferimenti
    riferimento_mittente_numerico: rifNum,
    riferimento_mittente_alfabetico: referenceDpd || rifAlfa || "",

    // Consegna
    data_cons_richiesta: "",
    ora_cons_richiesta: "",
    tipo_cons_richiesta: "",
    descrizione_cons_richiesta: "",
    data_teorica_consegna: dataTeorica,
    ora_teorica_consegna_da: oraDa,
    ora_teorica_consegna_a: oraA,
    data_consegna_merce: dataConsegnaEffettiva,
    ora_consegna_merce: oraConsegnaEffettiva,
    firmatario_consegna: firmatario,

    // Extra
    lista_eventi: formattedEvents,
    stato_sped_parte1: stato_sped_parte1,
    stato_sped_parte2: parseTag("STATO_SPED_PARTE2") || "",
    descrizione_stato_sped_parte1: descrizione_stato_sped_parte1,
    descrizione_stato_sped_parte2: parseTag("DESCRIZIONE_STATO_SPED_PARTE2") || "",
    originalPayload: { xmlText }
  };
}

async function startServer() {
  const app = express();
  const PORT = 3000;

  // Serve JSON body parsing
  app.use(express.json());

  // API Proxy Endpoint for BRT Tracking
  app.post("/api/brt-tracking", async (req: express.Request, res: express.Response) => {
    try {
      const { parcelID, userID, password, useSandbox } = req.body;

      if (!parcelID) {
        return res.status(403).json({
          success: false,
          message: "Il codice segnacollo (parcelID) è obbligatorio."
        });
      }

      const cleanParcelId = String(parcelID).trim();
      const finalUserId = (userID || process.env.BRT_USERID || "").trim();
      const finalPassword = (password || process.env.BRT_PASSWORD || "").trim();

      // Detection logic for real numeric parcel IDs to route them to the SOAP public tracker as fallback
      const isNumericPattern = /^\d{7,15}$/.test(cleanParcelId);
      const looksLikeRealId = isNumericPattern && !cleanParcelId.startsWith("12345");

      // 1. DEMO / Mock Generator fallback if sandbox is checked or credentials/real ids are lacking
      // (SOAP saltata in sandbox: non restituisce referente_consegna né note_consegna)
      if (useSandbox || !finalUserId || !finalPassword) {
        console.log(`[BRT Proxy] Utilizing demo simulator data for query: ${cleanParcelId}`);
        const mockResult = generateFallbackTracking(cleanParcelId);
        return res.json(mockResult);
      }

      console.log(`[BRT Proxy] Querying BRT REST API for parcel ID: ${cleanParcelId}`);
      
      let response;
      let resJson: any = null;
      let restOk = false;

      try {
        const brtUrl = `https://api.brt.it/rest/v1/tracking/parcelID/${cleanParcelId}`;
        response = await fetch(brtUrl, {
          method: "GET",
          headers: {
            "userID": finalUserId,
            "password": finalPassword,
            "Accept": "application/json"
          },
          signal: AbortSignal.timeout(10000)
        });

        if (response) {
          try {
            resJson = await response.json();
            restOk = true;
          } catch (jsonErr) {
            console.warn("[BRT Proxy] Response was not valid JSON:", jsonErr);
          }
        }
      } catch (restErr: any) {
        console.error("[BRT Proxy REST Query Failed]:", restErr.message || restErr);
      }

      let resBodyForCheck = resJson;
      if (resBodyForCheck && resBodyForCheck.parcelIDResult) {
        resBodyForCheck = resBodyForCheck.parcelIDResult;
      }
      if (resBodyForCheck && resBodyForCheck.ttParcelIdResponse) {
        resBodyForCheck = resBodyForCheck.ttParcelIdResponse;
      }

      let checkCode = 0;
      if (resBodyForCheck) {
        if (resBodyForCheck.executionMessage && typeof resBodyForCheck.executionMessage.code !== "undefined") {
          checkCode = Number(resBodyForCheck.executionMessage.code);
        } else if (typeof resBodyForCheck.code !== "undefined") {
          checkCode = Number(resBodyForCheck.code);
        }
      }

      const responseHasError = resJson ? (checkCode < 0) : true;
      const isHttpError = response ? !response.ok : true;

      // 3. Fallback to SOAP if REST Query fails, is not ok, or returns a parsed negative code, and the ID looks like a real numeric ID
      if ((isHttpError || responseHasError || !resJson) && looksLikeRealId) {
        try {
          console.log(`[BRT Proxy] REST query unsuccessful (or returned error). Attempting public SOAP fallback for: ${cleanParcelId}`);
          const xmlResponse = await fetchBrtSoapTracking(cleanParcelId);
          const soapResult = parseSoapResponse(xmlResponse, cleanParcelId);
          if (soapResult.success && soapResult.lista_eventi.length > 0) {
            console.log(`[BRT Proxy] SOAP fallback successful for ${cleanParcelId}`);
            return res.json(soapResult);
          }
        } catch (soapErr: any) {
          console.error("[BRT Proxy] SOAP fallback also failed:", soapErr.message || soapErr);
        }
      }

      // If we could not even retrieve a JSON response from the server (e.g. timeout, network down)
      if (!resJson) {
        const statusText = response ? `HTTP ${response.status} ${response.statusText}` : "Errore Connessione / Timeout";
        return res.status(200).json({
          success: false,
          code: -1,
          severity: "ERROR",
          codeDesc: "REST API Connection Failure",
          message: `Impossibile connettersi all'API di BRT (${statusText}). Verifica le credenziali o la raggiungibilità di api.brt.it`,
          parcelID: cleanParcelId,
          ragione_sociale: "",
          indirizzo: "",
          cap: "",
          localita: "",
          sigla_provincia: "",
          sigla_nazione: "",
          referente_consegna: "",
          telefono_referente: "",
          colli: 0,
          peso_kg: 0,
          riferimento_mittente_numerico: 0,
          riferimento_mittente_alfabetico: "",
          data_cons_richiesta: "",
          ora_cons_richiesta: "",
          tipo_cons_richiesta: "",
          descrizione_cons_richiesta: "",
          data_teorica_consegna: "",
          ora_teorica_consegna_da: "",
          ora_teorica_consegna_a: "",
          data_consegna_merce: "",
          ora_consegna_merce: "",
          firmatario_consegna: "",
          lista_eventi: []
        });
      }

      // Helper to do case-insensitive and alternative-key lookups on any object
      const getVal = (obj: any, keys: string[]): any => {
        if (!obj || typeof obj !== "object") return undefined;
        for (const k of keys) {
          if (typeof obj[k] !== "undefined") return obj[k];
          const lowerK = k.toLowerCase().replace(/_/g, "");
          for (const realKey of Object.keys(obj)) {
            const normalizedRealKey = realKey.toLowerCase().replace(/_/g, "");
            if (normalizedRealKey === lowerK) {
              return obj[realKey];
            }
          }
        }
        return undefined;
      };

      console.log(`[BRT API PROXY RAW RESPONSE] for ${cleanParcelId}:`, JSON.stringify(resJson, null, 2));
      
      // Map according to BRT Json Schema
      // Schema structure nests under parcelIDResult and ttParcelIdResponse
      let resBody = resJson;
      if (resBody && resBody.parcelIDResult) {
        resBody = resBody.parcelIDResult;
      }
      if (resBody && resBody.ttParcelIdResponse) {
        resBody = resBody.ttParcelIdResponse;
      }
      
      const bolla = resBody.bolla || resBody;
      
      // Extract data safely with fallbacks
      let code = 0;
      if (resBody) {
        if (resBody.executionMessage && typeof resBody.executionMessage.code !== "undefined") {
          code = Number(resBody.executionMessage.code);
        } else if (typeof resBody.code !== "undefined") {
          code = Number(resBody.code);
        }
      }
      const isSuccess = code >= 0;

      const dest = bolla.destinatario || resBody.destinatario || bolla.ClienteDestinatario || {};
      const mit = bolla.mittente || resBody.mittente || bolla.ClienteMittente || {};
      const merce = bolla.merce || resBody.merce || {};
      const references = bolla.riferimenti || resBody.riferimenti || {};
      const deliv = bolla.dati_consegna || resBody.dati_consegna || {};

      // Parse Destinatario (Recipient)
      const ragione_sociale = getVal(dest, ["ragione_sociale", "ragioneSociale", "denominazione", "nome", "ragione_sociale_destinatario"]) || "";
      const indirizzo = getVal(dest, ["indirizzo", "indirizzo_destinatario", "via", "address"]) || "";
      const cap = getVal(dest, ["cap", "cap_destinatario", "postal_code", "zip"]) || "";
      const localita = getVal(dest, ["localita", "localita_destinatario", "citta", "city"]) || "";
      const sigla_provincia = getVal(dest, ["sigla_provincia", "siglaProvincia", "provincia", "prov"]) || "";
      const sigla_nazione = getVal(dest, ["sigla_nazione", "siglaNazione", "nazione", "country"]) || "IT";
      const referente_consegna = getVal(dest, ["referente_consegna", "referenteConsegna", "referente"]) || "";
      const telefono_referente = getVal(dest, ["telefono_referente", "telefonoReferente", "telefono", "phone", "cellulare"]) || "";

      // Parse Mittente (Sender)
      const ragione_sociale_mittente = getVal(mit, ["ragione_sociale", "ragioneSociale", "denominazione", "nome", "ragione_sociale_mittente"]) || "";
      const indirizzo_mittente = getVal(mit, ["indirizzo", "indirizzo_mittente", "via", "address"]) || "";
      const cap_mittente = getVal(mit, ["cap", "cap_mittente", "postal_code", "zip"]) || "";
      const localita_mittente = getVal(mit, ["localita", "localita_mittente", "citta", "city"]) || "";
      const sigla_provincia_mittente = getVal(mit, ["sigla_provincia", "siglaProvincia", "provincia", "prov"]) || "";
      const sigla_nazione_mittente = getVal(mit, ["sigla_nazione", "siglaNazione", "nazione", "country"]) || "IT";
      const telefono_mittente = getVal(mit, ["telefono", "telefono_mittente", "phone", "cellulare"]) || "";

      // Flatten events array
      let rawEvents = bolla.lista_eventi || resBody.lista_eventi || [];
      if (!Array.isArray(rawEvents) && rawEvents) {
        rawEvents = [rawEvents];
      }
      
      const formattedEvents: BrtTrackingEvent[] = rawEvents.map((item: any) => {
        const ev = item.evento || item;
        return {
          data: getVal(ev, ["data", "data_evento"]) || "",
          ora: getVal(ev, ["ora", "ora_evento"]) || "",
          id: getVal(ev, ["id", "id_evento", "codice", "code"]) || "",
          descrizione: getVal(ev, ["descrizione", "info", "stato"]) || "",
          filiale: getVal(ev, ["filiale", "luogo", "office"]) || ""
        };
      });

      // Note di consegna: lista_note[].nota.descrizione
      let rawNotes = bolla.lista_note || resBody.lista_note || [];
      if (!Array.isArray(rawNotes) && rawNotes) rawNotes = [rawNotes];
      const note_consegna: string[] = rawNotes
        .map((n: any) => (n.nota?.descrizione || n.descrizione || "").trim())
        .filter(Boolean);

      const mappedResult: BrtTrackingResult = {
        parcelID: cleanParcelId,
        success: isSuccess,
        code: code,
        severity: resBody.severity || (resBody.executionMessage && resBody.executionMessage.severity) || "INFO",
        codeDesc: resBody.codeDesc || (resBody.executionMessage && resBody.executionMessage.codeDesc) || "",
        message: resBody.message || (resBody.executionMessage && resBody.executionMessage.message) || "",
        
        // Requested fields (Destinatario)
        ragione_sociale,
        indirizzo,
        cap,
        localita,
        sigla_provincia,
        sigla_nazione,
        referente_consegna,
        telefono_referente,

        // Requested fields (Mittente)
        ragione_sociale_mittente,
        indirizzo_mittente,
        cap_mittente,
        localita_mittente,
        sigla_provincia_mittente,
        sigla_nazione_mittente,
        telefono_mittente,
        
        // Requested fields (Merce)
        colli: typeof getVal(merce, ["colli", "numero_colli", "quantity"]) !== 'undefined' ? Number(getVal(merce, ["colli", "numero_colli"])) : 0,
        peso_kg: typeof getVal(merce, ["peso_kg", "peso", "weight"]) !== 'undefined' ? Number(getVal(merce, ["peso_kg", "peso"])) : 0,
        
        // Requested fields (Riferimenti)
        riferimento_mittente_numerico: typeof getVal(references, ["riferimento_mittente_numerico", "riferimento_numerico"]) !== 'undefined' ? Number(getVal(references, ["riferimento_mittente_numerico", "riferimento_numerico"])) : 0,
        riferimento_mittente_alfabetico: getVal(references, ["riferimento_mittente_alfabetico", "riferimento_alfabetico"]) || "",
        
        // Requested fields (Dati Consegna)
        data_cons_richiesta: getVal(deliv, ["data_cons_richiesta", "dataConsRichiesta"]) || "",
        ora_cons_richiesta: getVal(deliv, ["ora_cons_richiesta", "oraConsRichiesta"]) || "",
        tipo_cons_richiesta: getVal(deliv, ["tipo_cons_richiesta", "tipoConsRichiesta"]) || "",
        descrizione_cons_richiesta: getVal(deliv, ["descrizione_cons_richiesta", "descrizioneConsRichiesta"]) || "",
        data_teorica_consegna: getVal(deliv, ["data_teorica_consegna", "dataTeoricaConsegna"]) || "",
        ora_teorica_consegna_da: getVal(deliv, ["ora_teorica_consegna_da", "oraTeoricaConsegnaDa"]) || "",
        ora_teorica_consegna_a: getVal(deliv, ["ora_teorica_consegna_a", "oraTeoricaConsegnaA"]) || "",
        data_consegna_merce: getVal(deliv, ["data_consegna_merce", "dataConsegnaMerce"]) || "",
        ora_consegna_merce: getVal(deliv, ["ora_consegna_merce", "oraConsegnaMerce"]) || "",
        firmatario_consegna: getVal(deliv, ["firmatario_consegna", "firmatarioConsegna"]) || "",

        // Note di consegna
        note_consegna,

        // Extra status details for visual UI
        lista_eventi: formattedEvents,
        stato_sped_parte1: resBody.stato_sped_parte1 || bolla.stato_sped_parte1 || "",
        stato_sped_parte2: resBody.stato_sped_parte2 || bolla.stato_sped_parte2 || "",
        descrizione_stato_sped_parte1: resBody.descrizione_stato_sped_parte1 || bolla.descrizione_stato_sped_parte1 || "",
        descrizione_stato_sped_parte2: resBody.descrizione_stato_sped_parte2 || bolla.descrizione_stato_sped_parte2 || "",
        originalPayload: resJson
      };

      return res.json(mappedResult);
    } catch (err: any) {
      console.error("[BRT Proxy Error]:", err);
      return res.status(500).json({
        success: false,
        message: `Errore del server proxy: ${err.message || err}`
      });
    }
  });

  // Vite Integration
  if (process.env.NODE_ENV !== "production") {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: "spa",
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), "dist");
    app.use(express.static(distPath));
    app.get("*", (req, res) => {
      res.sendFile(path.join(distPath, "index.html"));
    });
  }

  app.listen(PORT, "0.0.0.0", () => {
    console.log(`Server listening on http://localhost:${PORT}`);
  });
}

startServer();
