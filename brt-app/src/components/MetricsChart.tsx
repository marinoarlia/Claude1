/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React from "react";
import { Scale, Package, BarChart3, TrendingUp, Compass, ArrowUpRight } from "lucide-react";

interface MetricsChartProps {
  colli: number;
  peso_kg: number;
}

export default function MetricsChart({ colli, peso_kg }: MetricsChartProps) {
  // Simple ratios for SVG representation
  const maxWeightRef = 150; // benchmark
  const weightPercentage = Math.min(100, Math.round((peso_kg / maxWeightRef) * 100)) || 5;
  const colliPercentage = Math.min(100, Math.round((colli / 10) * 100)) || 10;

  // Doughnut parameters
  const size = 160;
  const strokeWidth = 14;
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  
  // Calculate stroke offsets
  const safeWeight = peso_kg || 1;
  const safeColli = colli || 1;
  const total = safeWeight + safeColli;
  const weightCircRatio = (safeWeight / total) * circumference;
  const colliCircRatio = (safeColli / total) * circumference;

  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6" id="metrics-charts-pane">
      <h4 className="font-bold text-slate-800 text-sm flex items-center gap-2 mb-4">
        <BarChart3 className="h-4 w-4 text-red-500" />
        Ripartizione Peso & Colli
      </h4>

      <div className="flex flex-col items-center justify-center py-3">
        {/* SVG DOUGHNUT CHART */}
        <div className="relative" style={{ width: size, height: size }}>
          <svg className="transform -rotate-90 w-full h-full">
            {/* Background Circle */}
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              className="stroke-slate-100"
              strokeWidth={strokeWidth}
              fill="transparent"
            />
            {/* Colli Segment */}
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              className="stroke-blue-500"
              strokeWidth={strokeWidth}
              strokeDasharray={`${colliCircRatio} ${circumference - colliCircRatio}`}
              strokeDashoffset={0}
              fill="transparent"
              strokeLinecap="round"
            />
            {/* Peso Segment (staggered offset) */}
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              className="stroke-red-600"
              strokeWidth={strokeWidth + 2} // Slightly larger for emphasis
              strokeDasharray={`${weightCircRatio} ${circumference - weightCircRatio}`}
              strokeDashoffset={-colliCircRatio}
              fill="transparent"
              strokeLinecap="round"
            />
          </svg>

          {/* Center Text Labels */}
          <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
            <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Peso Sped.</span>
            <span className="text-xl font-bold text-slate-850 mt-0.5">{peso_kg} <span className="text-xs">kg</span></span>
            <span className="text-[10px] text-slate-500 font-semibold mt-1">Colli: {colli}</span>
          </div>
        </div>

        {/* Legend */}
        <div className="w-full mt-6 space-y-3">
          {/* PESO METRIC BAR */}
          <div>
            <div className="flex justify-between text-xs mb-1">
              <span className="flex items-center gap-1 text-slate-650">
                <span className="h-2.5 w-2.5 rounded-full bg-red-600 shrink-0"></span>
                Peso Totale
              </span>
              <span className="font-semibold text-slate-800">{peso_kg} kg</span>
            </div>
            <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
              <div 
                className="bg-red-600 h-full rounded-full transition-all duration-500"
                style={{ width: `${weightPercentage}%` }}
              ></div>
            </div>
            <span className="text-[9px] text-slate-400 mt-0.5 block leading-normal text-right">
              Capacità di carico indicativa: {weightPercentage}%
            </span>
          </div>

          {/* COLLI METRIC BAR */}
          <div>
            <div className="flex justify-between text-xs mb-1">
              <span className="flex items-center gap-1 text-slate-650">
                <span className="h-2.5 w-2.5 rounded-full bg-blue-500 shrink-0"></span>
                Unità Colli
              </span>
              <span className="font-semibold text-slate-800">{colli} {colli === 1 ? "Collo" : "Colli"}</span>
            </div>
            <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
              <div 
                className="bg-blue-500 h-full rounded-full transition-all duration-500"
                style={{ width: `${colliPercentage}%` }}
              ></div>
            </div>
            <span className="text-[9px] text-slate-400 mt-0.5 block leading-normal text-right">
              Capienza furgonatura stimata: {colliPercentage}%
            </span>
          </div>
        </div>

        {/* STATISTIC CARD */}
        <div className="mt-5 w-full bg-red-50/50 border border-red-100/40 rounded-lg p-3 text-xs text-red-800 flex items-start gap-2">
          <TrendingUp className="h-4 w-4 text-red-600 shrink-0 mt-0.5" />
          <div>
            <span className="font-bold text-red-900 block">Rilevamento Autista BRT</span>
            <p className="text-[11px] text-slate-600 mt-0.5 leading-normal">
              La proporzione peso/volume è bilanciata per la spedizione di merce generica standard.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
