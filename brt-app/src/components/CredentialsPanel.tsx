/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React from "react";
import { Shield, Eye, EyeOff, Settings, CheckCircle, Save } from "lucide-react";

interface CredentialsPanelProps {
  userID: string;
  setUserID: (v: string) => void;
  password: string;
  setPassword: (v: string) => void;
}

const LS_KEY = "brt_credentials";

export default function CredentialsPanel({
  userID,
  setUserID,
  password,
  setPassword,
}: CredentialsPanelProps) {
  const [showPassword, setShowPassword] = React.useState(false);
  const [saved, setSaved] = React.useState(false);

  const handleSave = () => {
    localStorage.setItem(LS_KEY, JSON.stringify({ userID, password }));
    setSaved(true);
    setTimeout(() => setSaved(false), 2500);
  };

  const handleClear = () => {
    localStorage.removeItem(LS_KEY);
    setUserID("");
    setPassword("");
  };

  const hasSaved = !!localStorage.getItem(LS_KEY);

  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6" id="credentials-panel">
      <div className="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
        <h4 className="font-semibold text-slate-800 flex items-center gap-2 text-sm">
          <Settings className="h-4 w-4 text-slate-400" />
          Credenziali BRT REST API
        </h4>
        {hasSaved && (
          <span className="text-xs px-2.5 py-1 font-semibold rounded-full flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200/55">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
            Credenziali salvate
          </span>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
        {/* INPUT USERID */}
        <div className="md:col-span-5">
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
              value={userID}
              onChange={(e) => setUserID(e.target.value)}
              placeholder="Inserisci userID"
              className="w-full pl-9 pr-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-red-500 focus:border-red-500"
            />
          </div>
        </div>

        {/* INPUT PASSWORD */}
        <div className="md:col-span-5">
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
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••••••••••"
              className="w-full pl-9 pr-10 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-red-500 focus:border-red-500 font-mono"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 transition"
            >
              {showPassword ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
            </button>
          </div>
        </div>

        {/* SALVA BUTTON */}
        <div className="md:col-span-2 flex flex-col justify-end">
          <button
            type="button"
            onClick={handleSave}
            disabled={!userID || !password}
            className="w-full py-2 px-3 bg-red-600 hover:bg-red-700 disabled:bg-slate-200 disabled:text-slate-400 text-white text-xs font-bold rounded-lg transition flex items-center justify-center gap-1.5"
          >
            {saved ? (
              <><CheckCircle className="h-3.5 w-3.5" /> Salvato</>
            ) : (
              <><Save className="h-3.5 w-3.5" /> Salva</>
            )}
          </button>
        </div>
      </div>

      {hasSaved && (
        <div className="mt-3 flex items-center justify-between text-[10px] text-slate-500 bg-slate-50 p-2 rounded-md border border-slate-100">
          <span>Le credenziali sono salvate localmente nel browser e vengono caricate automaticamente.</span>
          <button onClick={handleClear} className="ml-4 text-red-500 hover:text-red-700 font-semibold whitespace-nowrap">
            Rimuovi
          </button>
        </div>
      )}

      {!userID && (
        <div className="mt-3 text-[10px] text-amber-600 bg-amber-50 p-2 rounded-md border border-amber-100 flex items-center gap-2">
          <span>⚠️</span> Inserisci UserID e Password BRT per tracciare spedizioni reali.
        </div>
      )}
    </div>
  );
}
