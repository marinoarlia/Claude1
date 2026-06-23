/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useEffect } from "react";
import { 
  Truck, 
  Search, 
  MapPin, 
  Package, 
  Calendar, 
  Clock, 
  FileText, 
  ShieldAlert, 
  CheckCircle2, 
  AlertCircle, 
  Loader2, 
  Info, 
  FileCode, 
  Sparkles, 
  ExternalLink,
  Signature,
  ArrowRight,
  Phone,
  User,
  Hash,
  Activity,
  Building,
  Terminal,
  ChevronDown,
  ChevronUp
} from "lucide-react";
import { motion, AnimatePresence } from "motion/react";
import { BrtTrackingResult } from "./types";
import CredentialsPanel from "./components/CredentialsPanel";
import TrackingTimeline from "./components/TrackingTimeline";
import MetricsChart from "./components/MetricsChart";
import PhpGenerator from "./components/PhpGenerator";

export default function App() {
  const [activeTab, setActiveTab] = useState<"dashboard" | "php">("dashboard");
  const [parcelID, setParcelID] = useState("08459100301718");
  const [userID, setUserID] = useState("");
  const [password, setPassword] = useState("");
  const [useSandbox, setUseSandbox] = useState(true);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<BrtTrackingResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showRawJson, setShowRawJson] = useState(false);

  // Form input states for interactive GDPR data enrichment / override
  const [enrichRagioneSociale, setEnrichRagioneSociale] = useState("");
  const [enrichIndirizzo, setEnrichIndirizzo] = useState("");
  const [enrichCap, setEnrichCap] = useState("");
  const [enrichLocalita, setEnrichLocalita] = useState("");
  const [enrichProvincia, setEnrichProvincia] = useState("");
  const [enrichTelefono, setEnrichTelefono] = useState("");
  const [showEnrichPanel, setShowEnrichPanel] = useState(false);

  // Automated search when mounting to show beautiful pre-loaded dashboard
  useEffect(() => {
    handleSearch();
  }, []);

  const handleSearch = async (e?: React.FormEvent, overrideId?: string) => {
    if (e) e.preventDefault();
    
    const searchId = overrideId || parcelID;
    if (!searchId.trim()) {
      setError("Inserire un id collo valido.");
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const response = await fetch("./api/brt-tracking.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          parcelID: searchId.trim(),
          userID,
          password,
          useSandbox,
        }),
      });

      if (!response.ok) {
        throw new Error(`Risposta del server non valida: http ${response.status}`);
      }

      const data = await response.json();
      
      if (data.success === false && data.code < 0) {
        // Safe mapping if shipment is not found but returns successfully from simulation/API
        setError(data.message || `ID collo "${searchId}" non trovato.`);
        setResult(data);
      } else {
        setResult(data);
        // Sincronizza i dati di arricchimento / override manuale
        setEnrichRagioneSociale(data.ragione_sociale || "");
        setEnrichIndirizzo(data.indirizzo || "");
        setEnrichCap(data.cap || "");
        setEnrichLocalita(data.localita || "");
        setEnrichProvincia(data.sigla_provincia || "");
        setEnrichTelefono(data.telefono_referente || "");
      }
    } catch (err: any) {
      console.error(err);
      setError(err.message || "Errore di rete durante la ricerca.");
    } finally {
      setLoading(false);
    }
  };

  const handleEnrichSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!result) return;
    setResult({
      ...result,
      ragione_sociale: enrichRagioneSociale,
      indirizzo: enrichIndirizzo,
      cap: enrichCap,
      localita: enrichLocalita,
      sigla_provincia: enrichProvincia,
      telefono_referente: enrichTelefono
    });
    setShowEnrichPanel(false);
  };

  const handleQuickPreset = (id: string, sandbox: boolean = true) => {
    setParcelID(id);
    setUseSandbox(sandbox);
    handleSearch(undefined, id);
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-800 flex flex-col font-sans selection:bg-red-500 selection:text-white">
      {/* HEADER BAR */}
      <header className="bg-white border-b border-slate-200 mt-0 z-10 sticky top-0 shadow-sm/10">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center h-16">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-lg bg-red-600 flex items-center justify-center text-white shadow-md shadow-red-200/50">
                <Truck className="h-6 w-6 stroke-[2.2]" />
              </div>
              <div>
                <span className="text-xs font-bold text-red-600 uppercase tracking-widest leading-none block">BARTOLINI BRT</span>
                <h1 className="text-md sm:text-lg font-black text-slate-900 tracking-tight leading-normal mt-0.5">
                  Tracker REST API Client
                </h1>
              </div>
            </div>

            {/* NAVIGATION TABS */}
            <div className="flex items-center gap-1.5">
              <button
                onClick={() => setActiveTab("dashboard")}
                className={`px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition-all duration-150 flex items-center gap-2 ${
                  activeTab === "dashboard"
                    ? "bg-slate-900 text-white shadow-sm"
                    : "text-slate-650 hover:text-slate-900 hover:bg-slate-100"
                }`}
              >
                <Activity className="h-4 w-4" />
                Dashboard <span className="hidden sm:inline">di Prova</span>
              </button>
              <button
                onClick={() => setActiveTab("php")}
                className={`px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition-all duration-150 flex items-center gap-2 ${
                  activeTab === "php"
                    ? "bg-slate-900 text-white shadow-sm"
                    : "text-slate-650 hover:text-slate-900 hover:bg-slate-100"
                }`}
                id="php-code-tab"
              >
                <FileCode className="h-4 w-4" />
                Script PHP <span className="hidden sm:inline">per FTP</span>
              </button>
            </div>
          </div>
        </div>
      </header>

      {/* CORE WRAPPER */}
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        {/* APP CONTEXT INFO BANNER */}
        <div className="bg-gradient-to-r from-red-600 to-red-800 text-white rounded-xl p-6 mb-8 shadow-md relative overflow-hidden">
          <div className="absolute top-0 right-0 opacity-10 translate-x-12 -translate-y-6 pointer-events-none">
            <Truck className="h-64 w-64 rotate-12" />
          </div>
          <div className="relative z-10 max-w-3xl">
            <h2 className="text-xl sm:text-2xl font-black tracking-tight flex items-center gap-2">
              Strumento di Collaudo & Generatore PHP BRT
            </h2>
            <p className="text-xs sm:text-sm text-red-100/90 mt-2 leading-relaxed">
              Questo applicativo simula e interroga l'endpoint REST reale di BRT Bartolini. Sotto la scheda 
              <strong> "Script PHP per FTP"</strong> troverai l'intero file pronto da scaricare e mettere sul tuo host FTP: è pre-costruito, supporta Bootstrap 5, Font Awesome, DataTables avanzate e grafici Chart.js.
            </p>
          </div>
        </div>

        {/* CONTENUTI IN BASE AL TAB ATTIVO */}
        {activeTab === "php" ? (
          <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
          >
            <PhpGenerator />
          </motion.div>
        ) : (
          <div className="space-y-6">
            {/* CREDENTIALS COMPONENT */}
            <CredentialsPanel
              userID={userID}
              setUserID={setUserID}
              password={password}
              setPassword={setPassword}
              useSandbox={useSandbox}
              setUseSandbox={setUseSandbox}
            />

            {/* BARRA DI RICERCA */}
            <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
              <form onSubmit={handleSearch} className="space-y-4">
                <div className="flex flex-col md:flex-row gap-3">
                  <div className="relative flex-1">
                    <span className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                      <Search className="h-5 w-5" />
                    </span>
                    <input
                      type="text"
                      placeholder="Inserisci l'ID collo o un ID di prova (es: BRT-OK-CONSEGNATO)..."
                      value={parcelID}
                      onChange={(e) => setParcelID(e.target.value)}
                      id="parcel-search-input"
                      className="w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-250 rounded-lg text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-600 transition"
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={loading}
                    className="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-3 rounded-lg text-sm transition duration-150 shadow-md shadow-red-200 flex items-center justify-center gap-2 disabled:bg-slate-300"
                  >
                    {loading ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" /> Ricerca in corso...
                      </>
                    ) : (
                      <>
                        <Search className="h-4 w-4 stroke-[2.5]" /> Traccia Collo BRT
                      </>
                    )}
                  </button>
                </div>

                {/* PRESETS */}
                <div className="flex flex-wrap items-center gap-2 pt-1 text-xs">
                  <span className="text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Esempi di Test rapidi:</span>
                  <button
                    type="button"
                    onClick={() => handleQuickPreset("BRT-OK-CONSEGNATO")}
                    className="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-md font-medium text-[11px] transition"
                  >
                    BRT-OK-CONSEGNATO (Consegnato)
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickPreset("BRT-IN-TRANSIT")}
                    className="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 rounded-md font-medium text-[11px] transition"
                  >
                    BRT-IN-TRANSIT (In Viaggio)
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickPreset("BRT-GIACENZA")}
                    className="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 rounded-md font-medium text-[11px] transition"
                  >
                    BRT-GIACENZA (Giacenza aperta)
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickPreset("BRT-ERROR")}
                    className="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-800 border border-rose-200 rounded-md font-medium text-[11px] transition"
                  >
                    BRT-ERROR (Shipment Non Trovata)
                  </button>
                </div>
              </form>
            </div>

            {/* ERROR PANEL */}
            {error && (
              <div className="bg-red-50 border border-red-200 text-red-800 p-5 rounded-xl flex items-start gap-3">
                <AlertCircle className="h-5 w-5 text-red-600 mt-0.5 shrink-0" />
                <div>
                  <h4 className="font-bold text-red-900 text-sm">Richiesta non disponibile</h4>
                  <p className="text-xs text-slate-650 mt-1">{error}</p>
                </div>
              </div>
            )}

            {/* LOADING STATE PLACEHOLDER */}
            {loading && (
              <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-12 flex flex-col items-center justify-center">
                <Loader2 className="h-8 w-8 text-red-600 animate-spin" />
                <span className="text-xs font-semibold text-slate-500 mt-3">Interrogazione API BRT in corso...</span>
              </div>
            )}
            {/* DASHBOARD CONTENT (WHEN RESULT EXISTS) */}
            {!loading && result && result.success && (
              <div className="space-y-6">
                
                {/* PROGRESS BAR & CURRENT GENERAL STATE */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
                  {/* Stepper block */}
                  <div className="w-full lg:flex-1">
                    <div className="relative flex items-center justify-between w-full">
                      {/* Connection Red/Gray Line */}
                      <div className="absolute top-[18px] left-[18px] right-[18px] h-1 bg-slate-200 z-0 rounded">
                        <div 
                          className="h-full bg-red-600 rounded transition-all duration-500"
                          style={{ 
                            width: result.data_consegna_merce 
                              ? "100%" 
                              : result.stato_sped_parte1?.includes("GIACENZA") 
                                ? "50%" 
                                : result.lista_eventi.length > 2 
                                  ? "25%" 
                                  : "0%" 
                          }}
                        />
                      </div>

                      {/* Step Bubbles */}
                      {[
                        { 
                          label: "Affidate a BRT", 
                          active: true,
                          icon: (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                          )
                        },
                        { 
                          label: "In viaggio", 
                          active: !!result.data_consegna_merce || result.stato_sped_parte1?.includes("GIACENZA") || result.lista_eventi.length >= 3,
                          icon: <Truck className="h-4 w-4 stroke-[2.5]" /> 
                        },
                        { 
                          label: "In filiale", 
                          active: !!result.data_consegna_merce || result.stato_sped_parte1?.includes("GIACENZA") || result.lista_eventi.some(e => e.filiale?.includes("ARR") || e.filiale?.includes("CAMPI")),
                          icon: <Building className="h-4 w-4 stroke-[2.5]" /> 
                        },
                        { 
                          label: "In consegna", 
                          active: !!result.data_consegna_merce || result.lista_eventi.some(e => e.id === "O4D"),
                          icon: <Package className="h-4 w-4 stroke-[2.5]" /> 
                        },
                        { 
                          label: "Consegnata", 
                          active: !!result.data_consegna_merce,
                          icon: <CheckCircle2 className="h-4 w-4 stroke-[2.5]" /> 
                        },
                      ].map((step, idx) => (
                        <div key={idx} className="flex flex-col items-center z-10 relative">
                          <div className={`h-9 w-9 rounded-full flex items-center justify-center border-2 transition-all duration-300 ${
                            step.active 
                              ? "bg-red-600 border-red-600 text-white shadow" 
                              : "bg-white border-slate-300 text-slate-400"
                          }`}>
                            {step.icon}
                          </div>
                          <span className={`text-[10px] sm:text-[11px] font-bold mt-2 text-center whitespace-nowrap px-1 leading-none ${
                            step.active 
                              ? "text-slate-900 font-extrabold" 
                              : "text-slate-400"
                          }`}>
                            {step.label}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>

                  {/* Delivery textual summary */}
                  <div className="w-full lg:w-auto lg:text-right shrink-0 border-t lg:border-t-0 border-slate-100 pt-4 lg:pt-0 pl-1">
                    {result.data_consegna_merce ? (
                      <div>
                        <span className="text-xs text-slate-500 font-bold block">La spedizione è stata consegnata</span>
                        <span className="text-lg sm:text-xl font-black text-red-600 uppercase tracking-tight block mt-0.5">
                          {result.data_consegna_merce === "16.06.2026" ? "Martedì " : "Martedì "}
                          {result.data_consegna_merce}
                        </span>
                      </div>
                    ) : result.stato_sped_parte1?.includes("GIACENZA") ? (
                      <div>
                        <span className="text-xs text-slate-500 font-bold block">Stato Spedizione</span>
                        <span className="text-lg font-black text-amber-500 uppercase tracking-tight block mt-0.5">
                          Pratica di Giacenza Aperta
                        </span>
                      </div>
                    ) : (
                      <div>
                        <span className="text-xs text-slate-500 font-bold block">Consegna stimata</span>
                        <span className="text-lg font-black text-blue-600 block mt-0.5">
                          {result.data_teorica_consegna || "In viaggio"}
                        </span>
                      </div>
                    )}
                  </div>
                </div>

                {/* THE EXQUISITE FIVE-COLUMN INFRASTRUCTURE CARD */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                  {/* Fonte dati badge */}
                  {result.fonte_dati && (
                    <div className="px-4 pt-3 pb-0 flex items-center gap-2">
                      {result.fonte_dati === 'csv+api' && (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 uppercase tracking-wider">
                          <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 inline-block"></span>
                          CSV + API
                        </span>
                      )}
                      {result.fonte_dati === 'api' && (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200 uppercase tracking-wider">
                          <span className="h-1.5 w-1.5 rounded-full bg-blue-500 inline-block"></span>
                          API BRT
                        </span>
                      )}
                      {result.fonte_dati === 'mock' && (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200 uppercase tracking-wider">
                          <span className="h-1.5 w-1.5 rounded-full bg-slate-400 inline-block"></span>
                          Demo
                        </span>
                      )}
                    </div>
                  )}
                  <div className="grid grid-cols-1 md:grid-cols-5 bg-slate-100/80 border-b border-slate-200 text-[10px] font-extrabold text-slate-700 tracking-wider uppercase divide-y md:divide-y-0 md:divide-x divide-slate-200">
                    <div className="p-3">Destinatario</div>
                    <div className="p-3">Mittente</div>
                    <div className="p-3">BRT code</div>
                    <div className="p-3">Riferimenti mittente</div>
                    <div className="p-3">Informazioni</div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-5 text-xs text-slate-800 font-medium bg-white divide-y md:divide-y-0 md:divide-x divide-slate-200">
                    {/* Destinatario content cell */}
                    <div className="p-4 leading-relaxed">
                      <div className="font-extrabold text-slate-900 text-sm">{result.ragione_sociale || "Non specificato"}</div>
                      <div className="text-slate-650 mt-1">{result.indirizzo}</div>
                      <div className="text-slate-650">{result.cap} {result.localita} {result.sigla_provincia ? `(${result.sigla_provincia})` : ''}</div>
                      <div className="text-slate-450 text-[11px] font-bold mt-1">
                        {result.sigla_nazione === "IT" ? "IT - ITALY" : (result.sigla_nazione || "IT - ITALY")}
                      </div>
                      {result.telefono_referente && (
                        <div className="font-semibold text-red-600 mt-2 text-sm select-all tracking-wider">
                          {result.telefono_referente}
                        </div>
                      )}
                    </div>

                    {/* Mittente content cell */}
                    <div className="p-4 leading-relaxed text-slate-600">
                      <div className="font-extrabold text-slate-900 text-sm">
                        {result.ragione_sociale_mittente || "ARLIA SRL"}
                      </div>
                      <div className="text-slate-650 mt-1">{result.indirizzo_mittente || "VIA FRAILLITI SNC"}</div>
                      <div className="text-slate-650">
                        {result.cap_mittente || "87030"} {result.localita_mittente || "LONGOBARDI"} {result.sigla_provincia_mittente ? `(${result.sigla_provincia_mittente})` : ''}
                      </div>
                      <div className="text-slate-450 text-[11px] font-bold mt-1">
                        {result.sigla_nazione_mittente === "IT" ? "IT - ITALY" : (result.sigla_nazione_mittente || "IT - ITALY")}
                      </div>
                      {result.telefono_mittente && (
                        <div className="font-bold text-slate-800 mt-2 text-xs select-all">
                          {result.telefono_mittente}
                        </div>
                      )}
                    </div>

                    {/* BRT ID content cell */}
                    <div className="p-4 font-bold text-slate-900 break-all select-all font-mono text-[13px] flex flex-col justify-center">
                      <div>{result.parcelID}</div>
                    </div>

                    {/* Riferimenti mittente content cell */}
                    <div className="p-4 font-mono text-slate-700 text-xs flex flex-col justify-center space-y-1">
                      <div>{result.riferimento_mittente_alfabetico || "-"}</div>
                      <div className="text-slate-400 text-[11px] font-bold">
                        {result.riferimento_mittente_numerico || "-"}
                      </div>
                      {result.numero_ordine && (
                        <div className="mt-1 pt-1 border-t border-slate-100">
                          <div className="text-[10px] text-slate-400 font-bold uppercase tracking-wide">N° Ordine</div>
                          <div className="text-slate-700 select-all">{result.numero_ordine}</div>
                        </div>
                      )}
                      {result.referente_consegna && (
                        <div className="mt-1 pt-1 border-t border-slate-100">
                          <div className="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Referente</div>
                          <div className="text-slate-700">{result.referente_consegna}</div>
                        </div>
                      )}
                      {result.note_consegna && result.note_consegna.length > 0 && (
                        <div className="mt-1 pt-1 border-t border-slate-100">
                          <div className="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Note consegna</div>
                          {result.note_consegna.map((n, i) => (
                            <div key={i} className="text-slate-600 text-[11px] leading-snug">{n}</div>
                          ))}
                        </div>
                      )}
                    </div>

                    {/* Informazioni content cell */}
                    <div className="p-4 leading-relaxed text-slate-650 text-xs flex flex-col justify-center">
                      <div>{result.dimensione_info || "Dimensione non disponibile"}</div>
                      <div className="mt-0.5">{result.peso_info || (result.peso_kg ? `Peso ${result.peso_kg} kg` : "Peso non disponibile")}</div>
                      <div className="font-mono text-[11px] mt-1 text-slate-400">{result.nota_info || "328 - -"}</div>
                    </div>
                  </div>
                </div>

                {/* SPIEGAZIONE GDPR & PANNELLO DI ARRICCHIMENTO DATI DESTINATARIO */}
                <div className="bg-slate-50 border border-slate-200 rounded-xl p-5 shadow-sm">
                  <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div className="flex items-start gap-3">
                      <div className="p-2 bg-red-50 text-red-600 rounded-lg shrink-0 mt-0.5">
                        <ShieldAlert className="h-5 w-5" />
                      </div>
                      <div>
                        <h4 className="font-bold text-slate-900 text-sm flex items-center gap-2">
                          Perché i dati completi del destinatario e del telefono potrebbero non apparire?
                        </h4>
                        <p className="text-xs text-slate-500 mt-1 leading-relaxed max-w-3xl">
                          In conformità alle direttive europee sulla <strong className="text-slate-705">Privacy (GDPR)</strong>, i server e le API originali di BRT Bartolini mascherano o escludono il nome completo, la via e il recapito telefonico del destinatario qualora la ricerca avvenga in modalità pubblica, oppure se le credenziali API REST in uso non sono abbinate all'esatto mittente commerciale della spedizione.
                        </p>
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => setShowEnrichPanel(!showEnrichPanel)}
                      className="px-4 py-2 bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 rounded-lg text-xs font-bold transition shrink-0 shadow-sm flex items-center gap-1.5"
                    >
                      <Sparkles className="h-3.5 w-3.5 text-amber-500" />
                      {showEnrichPanel ? "Nascondi Strumenti" : "Arricchisci Dati [Override]"}
                    </button>
                  </div>

                  <AnimatePresence>
                    {showEnrichPanel && (
                      <motion.form
                        onSubmit={handleEnrichSubmit}
                        initial={{ opacity: 0, height: 0 }}
                        animate={{ opacity: 1, height: "auto" }}
                        exit={{ opacity: 0, height: 0 }}
                        className="mt-5 pt-5 border-t border-slate-200 grid grid-cols-1 sm:grid-cols-3 gap-4"
                      >
                        <div className="sm:col-span-3">
                          <span className="text-[10px] font-extrabold uppercase text-slate-400 tracking-wider block mb-2">
                            Override manuale dei Dati Sensibili Destinatario
                          </span>
                          <p className="text-[11px] text-slate-500 leading-normal mb-3">
                            Usa i campi sottostanti per inserire manualmente il corretto destinatario comprensivo di recapito telefonico cellulare per simulare o stampare la bolla con i dati completi.
                          </p>
                        </div>

                        <div>
                          <label className="block text-[11px] font-bold text-slate-600 mb-1">Ragione Sociale / Nome Destinatario</label>
                          <div className="relative">
                            <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                              <User className="h-3.5 w-3.5" />
                            </span>
                            <input
                              type="text"
                              value={enrichRagioneSociale}
                              onChange={(e) => setEnrichRagioneSociale(e.target.value)}
                              placeholder="es. Mario Bianchi"
                              className="w-full pl-9 pr-3 py-1.5 bg-white border border-slate-250 rounded-md text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-600 outline-none"
                            />
                          </div>
                        </div>

                        <div>
                          <label className="block text-[11px] font-bold text-slate-600 mb-1">Telefono Referente (Per Notifica SMS)</label>
                          <div className="relative">
                            <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                              <Phone className="h-3.5 w-3.5" />
                            </span>
                            <input
                              type="text"
                              value={enrichTelefono}
                              onChange={(e) => setEnrichTelefono(e.target.value)}
                              placeholder="+39 345 678910"
                              className="w-full pl-9 pr-3 py-1.5 bg-white border border-slate-250 rounded-md text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-600 outline-none"
                            />
                          </div>
                        </div>

                        <div>
                          <label className="block text-[11px] font-bold text-slate-600 mb-1">Indirizzo di Consegna</label>
                          <div className="relative">
                            <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                              <MapPin className="h-3.5 w-3.5" />
                            </span>
                            <input
                              type="text"
                              value={enrichIndirizzo}
                              onChange={(e) => setEnrichIndirizzo(e.target.value)}
                              placeholder="Via dei Campi, 14"
                              className="w-full pl-9 pr-3 py-1.5 bg-white border border-slate-250 rounded-md text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-600 outline-none"
                            />
                          </div>
                        </div>

                        <div>
                          <label className="block text-[11px] font-bold text-slate-600 mb-1">CAP</label>
                          <input
                            type="text"
                            value={enrichCap}
                            onChange={(e) => setEnrichCap(e.target.value)}
                            placeholder="20121"
                            className="w-full px-3 py-1.5 bg-white border border-slate-250 rounded-md text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-600 outline-none"
                          />
                        </div>

                        <div>
                          <label className="block text-[11px] font-bold text-slate-600 mb-1">Località</label>
                          <input
                            type="text"
                            value={enrichLocalita}
                            onChange={(e) => setEnrichLocalita(e.target.value)}
                            placeholder="Milano"
                            className="w-full px-3 py-1.5 bg-white border border-slate-250 rounded-md text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-600 outline-none"
                          />
                        </div>

                        <div>
                          <label className="block text-[11px] font-bold text-slate-600 mb-1">Sigla Provincia</label>
                          <input
                            type="text"
                            value={enrichProvincia}
                            onChange={(e) => setEnrichProvincia(e.target.value)}
                            placeholder="MI"
                            maxLength={2}
                            className="w-full px-3 py-1.5 bg-white border border-slate-250 rounded-md text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-600 outline-none"
                          />
                        </div>

                        <div className="sm:col-span-3 flex justify-end gap-2 pt-2">
                          <button
                            type="button"
                            onClick={() => setShowEnrichPanel(false)}
                            className="px-4 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-md text-xs font-bold transition"
                          >
                            Annulla
                          </button>
                          <button
                            type="submit"
                            className="px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-md text-xs font-bold transition shadow-sm"
                          >
                            Salva Override / Aggiorna Bolla
                          </button>
                        </div>
                      </motion.form>
                    )}
                  </AnimatePresence>
                </div>

                {/* LOWER DATATABLES TIMELINE BLOCK */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                  <div className="lg:col-span-8 space-y-6">
                    <TrackingTimeline events={result.lista_eventi || []} />
                  </div>

                  <div className="lg:col-span-4 space-y-6">
                    {/* Metrics Chart showing weight and colli split */}
                    <MetricsChart colli={result.colli || 1} peso_kg={result.peso_kg || 0.0} />

                    {/* Quick credentials details or diagnostic */}
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                      <h4 className="font-bold text-slate-800 text-xs uppercase tracking-wider mb-2 text-red-600">Note e Consigli</h4>
                      <div className="text-xs text-slate-500 leading-relaxed space-y-2">
                        <p>
                          Il numero del destinatario della consegna è registrato per consentire la notifica SMS automatica sullo stato dell'inoltro.
                        </p>
                        <p className="font-bold text-slate-700">
                          Hai bisogno di scaricare questa applicazione?
                        </p>
                        <p>
                          Fai clic sulla scheda <span className="font-semibold text-slate-750">"Generatore PHP per FTP"</span> in cima allo schermo per scaricare la pagina PHP integrata con Bootstrap e DataTables già pronta da inserire sul tuo server FTP!
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* INTERACTIVE RAW JSON RESPONSE DEBUGGER */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                  <button
                    type="button"
                    onClick={() => setShowRawJson(!showRawJson)}
                    className="w-full flex justify-between items-center px-6 py-4 bg-slate-900 text-white font-bold text-xs uppercase tracking-wider hover:bg-slate-850 transition-colors focus:outline-none"
                  >
                    <span className="flex items-center gap-2">
                      <Terminal className="h-4 w-4 text-emerald-400" />
                      Visualizzatore Payload di Risposta JSON (Debug e Struttura Chiavi BRT)
                    </span>
                    {showRawJson ? <ChevronUp className="h-4 w-4 text-slate-400" /> : <ChevronDown className="h-4 w-4 text-slate-400" />}
                  </button>
                  <AnimatePresence>
                    {showRawJson && (
                      <motion.div
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: "auto", opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        transition={{ duration: 0.2 }}
                        className="border-t border-slate-200 p-6 bg-slate-950 font-mono text-[11px] leading-relaxed overflow-x-auto select-all text-emerald-400"
                      >
                        <div className="mb-3 text-[10px] text-slate-500 font-bold uppercase tracking-wider border-b border-slate-900 pb-2">
                          // Payload completo restituito dal server (incluso l'oggetto originale "originalPayload" inviato da BRT)
                        </div>
                        <pre className="max-h-[400px] overflow-y-auto font-mono text-emerald-400 bg-black/30 p-4 rounded-lg">
                          {JSON.stringify(result, null, 2)}
                        </pre>
                      </motion.div>
                    )}
                  </AnimatePresence>
                </div>

              </div>
            )}
            
            {/* INSTRUCTIONS IF NO SEARCH YET */}
            {!loading && !result && (
              <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center max-w-xl mx-auto">
                <Truck className="h-12 w-12 text-slate-350 mx-auto stroke-[1.5]" />
                <h3 className="text-lg font-bold text-slate-850 mt-4">Nessun dato caricato</h3>
                <p className="text-xs text-slate-500 leading-relaxed mt-2">
                  Inserisci un ID segnacollo BRT nel modulo di ricerca sopra oppure fai clic su uno dei pulsanti di esempio rapidi per visualizzare lo stato della spedizione.
                </p>
              </div>
            )}
          </div>
        )}
      </main>

      {/* FOOTER BAR */}
      <footer className="bg-slate-900 border-t border-slate-950 text-slate-450 text-xs py-8 mt-12">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center sm:text-left flex flex-col sm:flex-row justify-between items-center gap-4">
          <div>
            <p className="font-semibold text-slate-300">Client di tracking autorizzato BRT REST API</p>
            <p className="text-[11px] text-slate-500 mt-1">Sviluppato in esclusiva per integrazione gestionale con server FTP.</p>
          </div>
          <div className="flex gap-4">
            <span className="text-[11px] text-slate-500">Node JS + React UI + PHP cURL Helper</span>
          </div>
        </div>
      </footer>
    </div>
  );
}
