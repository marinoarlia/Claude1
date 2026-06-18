/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState } from "react";
import { Copy, Check, Download, FileCode, Server, Terminal, ArrowRight, ShieldCheck } from "lucide-react";
import { phpTemplateString } from "../phpTemplate";

export default function PhpGenerator() {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    navigator.clipboard.writeText(phpTemplateString);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleDownload = () => {
    const blob = new Blob([phpTemplateString], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "brt_tracking_ftp.php";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden" id="php-generator-pane">
      <div className="bg-slate-900 px-6 py-5 text-white flex flex-wrap justify-between items-center gap-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="p-1 px-2 bg-red-600 rounded text-xs font-bold uppercase tracking-wider">PHP + JS</span>
            <span className="p-1 px-2 bg-slate-850 rounded text-xs text-slate-400 font-bold uppercase tracking-wider">Bootstrap 5</span>
          </div>
          <h3 className="text-xl font-bold mt-2 flex items-center gap-2">
            <FileCode className="h-6 w-6 text-red-500" />
            Script di Connessione FTP (PHP)
          </h3>
          <p className="text-sm text-slate-400 mt-1">Carica questo file singolo e autonomo sul tuo host per tracciare le spedizioni.</p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={handleCopy}
            className="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-100 hover:text-white transition px-4 py-2 rounded-lg text-sm font-medium border border-slate-700"
            title="Copia negli Appunti"
          >
            {copied ? (
              <>
                <Check className="h-4 w-4 text-green-500" /> Letto!
              </>
            ) : (
              <>
                <Copy className="h-4 w-4" /> Copia Codice
              </>
            )}
          </button>
          <button
            onClick={handleDownload}
            className="flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white transition px-4 py-2 rounded-lg text-sm font-semibold"
            title="Scarica File .php"
          >
            <Download className="h-4 w-4" /> Scarica Script PHP
          </button>
        </div>
      </div>

      <div className="p-6">
        {/* ISTRUZIONI DI DEPLOY */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="p-4 rounded-lg bg-red-50 border border-red-100/50 flex gap-4">
            <div className="h-10 w-10 shrink-0 rounded-full bg-red-100 flex items-center justify-center text-red-600">
              <span className="font-bold text-lg">1</span>
            </div>
            <div>
              <h4 className="font-semibold text-slate-850">Scarica il file</h4>
              <p className="text-xs text-slate-500 mt-1 leading-relaxed">
                Fai clic su <strong>"Scarica Script PHP"</strong> sopra per salvare il file pronto ad essere eseguito sul tuo PC.
              </p>
            </div>
          </div>

          <div className="p-4 rounded-lg bg-blue-50 border border-blue-100/50 flex gap-4">
            <div className="h-10 w-10 shrink-0 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
              <span className="font-bold text-lg">2</span>
            </div>
            <div>
              <h4 className="font-semibold text-slate-850">Configura Credenziali</h4>
              <p className="text-xs text-slate-500 mt-1 leading-relaxed">
                Apri il file ed inserisci il tuo <strong>UserID</strong> ed <strong>API-Key</strong> alle righe 13 e 14, oppure digitali nel form temporaneo.
              </p>
            </div>
          </div>

          <div className="p-4 rounded-lg bg-green-50 border border-green-100/50 flex gap-4">
            <div className="h-10 w-10 shrink-0 rounded-full bg-green-100 flex items-center justify-center text-green-600">
              <span className="font-bold text-lg">3</span>
            </div>
            <div>
              <h4 className="font-semibold text-slate-850">Carica via FTP</h4>
              <p className="text-xs text-slate-500 mt-1 leading-relaxed">
                Usa FileZilla o un client FTP per caricare il file nel tuo spazio web. Rinestinalo come <code>index.php</code> per l'avvio diretto.
              </p>
            </div>
          </div>
        </div>

        {/* GUIDA AI REQUISITI RICHIESTI */}
        <div className="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-6">
          <h4 className="font-semibold text-slate-850 text-sm flex items-center gap-2 mb-3">
            <ShieldCheck className="h-4 w-4 text-emerald-600" />
            Linguaggi e Librerie di Terze Parti Incluse
          </h4>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <div className="flex items-center gap-2 text-slate-650 bg-white border border-slate-100 p-2 rounded">
              <span className="text-blue-500 font-bold">B5</span> Bootstrap v5.3.2 (Layout)
            </div>
            <div className="flex items-center gap-2 text-slate-650 bg-white border border-slate-100 p-2 rounded">
              <span className="text-yellow-600 font-bold">FA</span> Font Awesome v6.4 (Icone)
            </div>
            <div className="flex items-center gap-2 text-slate-650 bg-white border border-slate-100 p-2 rounded">
              <span className="text-green-600 font-bold">DT</span> DataTables v1.13 (Tabella)
            </div>
            <div className="flex items-center gap-2 text-slate-650 bg-white border border-slate-100 p-2 rounded">
              <span className="text-orange-500 font-bold">CJS</span> Chart.js v4.x (Grafici)
            </div>
          </div>
        </div>

        {/* CODE BLOCK ANTEPRIMA */}
        <div className="relative">
          <div className="absolute top-3 right-3 z-10">
            <button
              onClick={handleCopy}
              className="flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition px-3 py-1.5 rounded text-xs border border-slate-700 shadow-sm"
            >
              {copied ? <Check className="h-3 w-3 text-green-400" /> : <Copy className="h-3 w-3" />}
              {copied ? "Copiato!" : "Copia Intero PHP"}
            </button>
          </div>
          <div className="text-xs bg-slate-950 rounded-lg font-mono text-slate-300 overflow-x-auto max-h-[480px] border border-slate-800 p-4 leading-relaxed">
            <pre className="text-[11px] whitespace-pre-wrap">{phpTemplateString}</pre>
          </div>
        </div>
      </div>
    </div>
  );
}
