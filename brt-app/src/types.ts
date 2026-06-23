/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

export interface BrtRecipient {
  ragione_sociale: string;
  indirizzo: string;
  cap: string;
  localita: string;
  sigla_provincia: string;
  sigla_nazione: string;
  referente_consegna: string;
  telefono_referente: string;
}

export interface BrtRecipientMittente {
  codice?: number;
  ragione_sociale: string;
  indirizzo: string;
  cap: string;
  localita: string;
  sigla_area?: string;
}

export interface BrtGoods {
  colli: number;
  peso_kg: number;
  volume_m3?: number;
  natura_merce?: string;
}

export interface BrtDeliveryData {
  data_cons_richiesta: string;
  ora_cons_richiesta: string;
  tipo_cons_richiesta: string;
  descrizione_cons_richiesta: string;
  data_teorica_consegna: string;
  ora_teorica_consegna_da: string;
  ora_teorica_consegna_a: string;
  data_consegna_merce: string;
  ora_consegna_merce: string;
  firmatario_consegna: string;
}

export interface BrtReferences {
  riferimento_mittente_numerico: number;
  riferimento_mittente_alfabetico: string;
  riferimento_partner_estero?: string;
}

export interface BrtCodiceEsito {
  code: number;
  severity: "INFO" | "WARNING" | "ERROR";
  codeDesc: string;
  message: string;
}

export interface BrtTrackingEvent {
  data: string;
  ora: string;
  id: string;
  descrizione: string;
  filiale: string;
}

export interface BrtTrackingResult {
  parcelID: string;
  success: boolean;
  code: number;
  severity: string;
  codeDesc: string;
  message: string;
  
  // Specific requested fields mapped directly or grouped
  ragione_sociale: string;
  indirizzo: string;
  cap: string;
  localita: string;
  sigla_provincia: string;
  sigla_nazione: string;
  referente_consegna: string;
  telefono_referente: string;

  colli: number;
  peso_kg: number;

  riferimento_mittente_numerico: number;
  riferimento_mittente_alfabetico: string;

  data_cons_richiesta: string;
  ora_cons_richiesta: string;
  tipo_cons_richiesta: string;
  descrizione_cons_richiesta: string;
  data_teorica_consegna: string;
  ora_teorica_consegna_da: string;
  ora_teorica_consegna_a: string;
  data_consegna_merce: string;
  ora_consegna_merce: string;
  firmatario_consegna: string;

  // Additional elements to supply charts and lists
  lista_eventi: BrtTrackingEvent[];
  stato_sped_parte1?: string;
  stato_sped_parte2?: string;
  descrizione_stato_sped_parte1?: string;
  descrizione_stato_sped_parte2?: string;

  // Mittente information fields matching the spreadsheet/screenshot
  ragione_sociale_mittente?: string;
  indirizzo_mittente?: string;
  cap_mittente?: string;
  localita_mittente?: string;
  sigla_provincia_mittente?: string;
  sigla_nazione_mittente?: string;
  telefono_mittente?: string;

  // Note di consegna (lista_note[].nota.descrizione)
  note_consegna?: string[];

  // Info details from the screenshot
  dimensione_info?: string;
  peso_info?: string;
  nota_info?: string;

  originalPayload?: any; // For full inspections

  // CSV SFTP enrichment fields
  numero_ordine?: string;
  fonte_dati?: 'csv+api' | 'api' | 'mock';
}
