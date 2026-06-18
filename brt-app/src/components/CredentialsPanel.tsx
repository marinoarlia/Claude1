/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React from "react";
import { Shield, Eye, EyeOff, Settings, Sparkles, CheckCircle } from "lucide-react";

interface CredentialsPanelProps {
  userID: string;
  setUserID: (v: string) => void;
  password: string;
  setPassword: (v: string) => void;
  useSandbox: boolean;
  setUseSandbox: (v: boolean) => void;
}

export default function CredentialsPanel({
  userID,
  setUserID,
  password,
  setPassword,
  useSandbox,
  setUseSandbox,
}: CredentialsPanelProps) {
  const [showPassword, setShowPassword] = React.useState(false);

  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6" id="credentials-panel">
      <div className="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
        <h4 className="font-semibold text-slate-800 flex items-center gap-2 text-sm">
          <Settings className="h-4 w-4 text-slate-400 animate-spin-slow" />
          Configurazione Connessione BRT API
        </h4>
        <span className={`text-xs px-2.5 py-1 font-semibold rounded-full flex items-center gap-1.5 ${
          useSandbox 
            ? "bg-amber-50 text-amber-700 border border-amber-200/55" 
            : "bg-emerald-50 text-emerald-700 border border-emerald-200/55"
        }`}>
          <span className={`h-1.5 w-1.5 rounded-full ${useSandbox ? "bg-amber-500" : "bg-emerald-500"}`}></span>
          {useSandbox ? "Test Simulator" : "Live Proxy"}
        </span>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
        {/* MODALITA SIMULATORE CHECK */}
        <div className="md:col-span-4 flex items-center bg-slate-50 border border-slate-150 p-3 rounded-lg hover:border-slate-300 transition duration-150">
          <label className="flex items-start gap-3 cursor-pointer w-full selectivity-none">
            <input
              type="checkbox"
              id="sandbox-checkbox"
              className="mt-1 h-4 w-4 text-red-600 border-slate-300 rounded focus:ring-red-500"
              checked={useSandbox}
              onChange={(e) => setUseSandbox(e.target.checked)}
            />
            <div>
              <span className="font-medium text-xs text-slate-800 flex items-center gap-1">
                Attiva Simulatore Offline <Sparkles className="h-3 w-3 text-yellow-500 fill-yellow-500" />
              </span>
              <p className="text-[10px] text-slate-500 leading-normal mt-0.5">
                Utilizza dati dimostrativi preconfezionati senza fare chiamate reali alle API BRT.
              </p>
            </div>
          </label>
        </div>

        {/* INPUT USERID */}
        <div className="md:col-span-4">
          <label htmlFor="userid-input" className="block text-xs font-semibold text-slate-500 mb-1">
            UserID BRT REST API
          </label>
          <div className="relative">
            <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
              <Shield className="h-3.5 w-3.5" />
            </span>
            <input
              type="text"
              id="userid-input"
              disabled={useSandbox}
              value={userID}
              onChange={(e) => setUserID(e.target.value)}
              placeholder={useSandbox ? "Simulatore di Default" : "Inserisci userID"}
              className="w-full pl-9 pr-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-red-500 focus:border-red-500 disabled:bg-slate-100 disabled:text-slate-400"
            />
          </div>
        </div>

        {/* INPUT PASSWORD */}
        <div className="md:col-span-4">
          <label htmlFor="password-input" className="block text-xs font-semibold text-slate-500 mb-1">
            Password / API-Key BRT
          </label>
          <div className="relative">
            <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
              <Shield className="h-3.5 w-3.5" />
            </span>
            <input
              type={showPassword ? "text" : "password"}
              id="password-input"
              disabled={useSandbox}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder={useSandbox ? "Simulatore di Default" : "••••••••••••••••"}
              className="w-full pl-9 pr-10 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-red-500 focus:border-red-500 disabled:bg-slate-100 disabled:text-slate-400 font-mono"
            />
            {!useSandbox && (
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 transition"
              >
                {showPassword ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
              </button>
            )}
          </div>
        </div>
      </div>

      {!useSandbox && !userID && (
        <div className="mt-3 text-[10px] text-amber-600 bg-amber-50 p-2 rounded-md border border-amber-100 flex items-center gap-2">
          <span>⚠️</span> Inserendo UserID e Password personali, le ricerche verranno inviate in cURL reale tramite backend Express di prova alla URL certificata <code>api.brt.it</code>.
        </div>
      )}
    </div>
  );
}
