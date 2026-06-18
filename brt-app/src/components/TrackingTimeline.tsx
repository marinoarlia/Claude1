/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useMemo } from "react";
import { ArrowUpDown, Search, Calendar, Clock, MapPin, Building, FileText } from "lucide-react";
import { BrtTrackingEvent } from "../types";

interface TrackingTimelineProps {
  events: BrtTrackingEvent[];
}

export default function TrackingTimeline({ events }: TrackingTimelineProps) {
  const [searchTerm, setSearchTerm] = useState("");
  const [sortField, setSortField] = useState<"data" | "ora" | "descrizione" | "filiale">("data");
  const [sortDirection, setSortDirection] = useState<"asc" | "desc">("desc");
  const [currentPage, setCurrentPage] = useState(1);
  const [activeSubTab, setActiveSubTab] = useState<"spedizioni" | "reindirizzamenti">("spedizioni");
  const itemsPerPage = 8;

  // Custom DataTables replication in React: Search & Sort Filter
  const filteredSortedEvents = useMemo(() => {
    let result = [...events];

    // Search
    if (searchTerm.trim() !== "") {
      const q = searchTerm.toLowerCase();
      result = result.filter(
        (e) =>
          e.descrizione.toLowerCase().includes(q) ||
          e.filiale.toLowerCase().includes(q) ||
          e.data.toLowerCase().includes(q)
      );
    }

    // Sort
    result.sort((a, b) => {
      let valA = a[sortField] || "";
      let valB = b[sortField] || "";
      
      if (sortField === "data") {
        // Handle parsing or direct string comparison (16.06.2026 is DD.MM.YYYY or YYYY-MM-DD)
        const parseDate = (dStr: string) => {
          if (dStr.includes(".")) {
            const parts = dStr.split(".");
            return `${parts[2]}-${parts[1]}-${parts[0]}`;
          }
          return dStr;
        };
        valA = `${parseDate(a.data)} ${a.ora}`;
        valB = `${parseDate(b.data)} ${b.ora}`;
      }

      if (valA < valB) return sortDirection === "asc" ? -1 : 1;
      if (valA > valB) return sortDirection === "asc" ? 1 : -1;
      return 0;
    });

    return result;
  }, [events, searchTerm, sortField, sortDirection]);

  // Page Calculations
  const paginatedEvents = useMemo(() => {
    const startIndex = (currentPage - 1) * itemsPerPage;
    return filteredSortedEvents.slice(startIndex, startIndex + itemsPerPage);
  }, [filteredSortedEvents, currentPage]);

  const totalPages = Math.ceil(filteredSortedEvents.length / itemsPerPage) || 1;

  const handleSort = (field: "data" | "ora" | "descrizione" | "filiale") => {
    if (sortField === field) {
      setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    } else {
      setSortField(field);
      setSortDirection("desc"); // Default to desc for newest logs first
    }
    setCurrentPage(1);
  };

  // Safe checks Reset page on search change
  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setSearchTerm(e.target.value);
    setCurrentPage(1);
  };

  return (
    <div className="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden" id="timeline-and-logs">
      {/* TABS HEADER MATCHING SCREENSHOT */}
      <div className="flex border-b border-slate-200">
        <button
          onClick={() => {
            setActiveSubTab("spedizioni");
            setCurrentPage(1);
          }}
          className={`flex-1 py-4 text-center font-bold text-xs sm:text-sm tracking-wide transition-all border-b-2 outline-none ${
            activeSubTab === "spedizioni"
              ? "border-red-650 text-slate-900 bg-slate-50/40"
              : "border-transparent text-slate-400 hover:text-slate-600 hover:bg-slate-50/20"
          }`}
        >
          Storico spedizioni
        </button>
        <button
          onClick={() => {
            setActiveSubTab("reindirizzamenti");
            setCurrentPage(1);
          }}
          className={`flex-1 py-4 text-center font-bold text-xs sm:text-sm tracking-wide transition-all border-b-2 outline-none ${
            activeSubTab === "reindirizzamenti"
              ? "border-red-650 text-slate-900 bg-slate-50/40"
              : "border-transparent text-slate-400 hover:text-slate-600 hover:bg-slate-50/20"
          }`}
        >
          Storico reindirizzamenti
        </button>
      </div>

      <div className="p-4 sm:p-5">
        {/* FILTRA CASSA SE SPEDIZIONI CORRENTI */}
        {activeSubTab === "spedizioni" && events.length > 5 && (
          <div className="mb-4 flex justify-end">
            <div className="relative">
              <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                <Search className="h-3.5 w-3.5" />
              </span>
              <input
                type="text"
                placeholder="Filtra storico..."
                value={searchTerm}
                onChange={handleSearchChange}
                className="pl-9 pr-3 py-1 bg-white rounded border border-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-red-500 focus:border-red-500 w-44 sm:w-56 transition"
              />
            </div>
          </div>
        )}

        {activeSubTab === "reindirizzamenti" ? (
          <div className="py-12 text-center">
            <MapPin className="h-10 w-10 text-slate-300 mx-auto stroke-[1.5] mb-2" />
            <p className="text-slate-500 text-xs italic">Nessun reindirizzamento effettuato per questa spedizione.</p>
            <p className="text-[10px] text-slate-400 mt-1">La merce segue il percorso logistico standard concordato all'origine.</p>
          </div>
        ) : (
          <div>
            <div className="overflow-x-auto">
              <table className="w-full text-xs text-left text-slate-600 border-collapse">
                <thead className="text-slate-700 bg-slate-100 uppercase text-[10px] tracking-wider font-semibold">
                  <tr className="border-b border-slate-200">
                    <th 
                      onClick={() => handleSort("data")} 
                      className="px-4 py-3 cursor-pointer hover:bg-slate-200 transition select-none w-32"
                    >
                      <div className="flex items-center gap-1">
                        Data
                        <span className="text-[9px] text-red-650">
                          {sortField === "data" ? (sortDirection === "asc" ? "▲" : "▼") : "▲▼"}
                        </span>
                      </div>
                    </th>
                    <th 
                      onClick={() => handleSort("ora")} 
                      className="px-4 py-3 cursor-pointer hover:bg-slate-200 transition select-none w-24"
                    >
                      <div className="flex items-center gap-1">
                        Ora
                        <span className="text-[9px] text-slate-400">
                          {sortField === "ora" ? (sortDirection === "asc" ? "▲" : "▼") : ""}
                        </span>
                      </div>
                    </th>
                    <th 
                      onClick={() => handleSort("filiale")} 
                      className="px-4 py-3 cursor-pointer hover:bg-slate-200 transition select-none w-44"
                    >
                      <div className="flex items-center gap-1">
                        Luogo
                        <span className="text-[9px] text-slate-400">
                          {sortField === "filiale" ? (sortDirection === "asc" ? "▲" : "▼") : ""}
                        </span>
                      </div>
                    </th>
                    <th 
                      onClick={() => handleSort("descrizione")} 
                      className="px-4 py-3 cursor-pointer hover:bg-slate-200 transition select-none"
                    >
                      <div className="flex items-center gap-1">
                        Status spedizione
                        <span className="text-[9px] text-slate-400">
                          {sortField === "descrizione" ? (sortDirection === "asc" ? "▲" : "▼") : ""}
                        </span>
                      </div>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {paginatedEvents.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="px-4 py-8 text-center text-slate-400 italic">
                        Nessun evento registrato corrispondente ai criteri inseriti.
                      </td>
                    </tr>
                  ) : (
                    paginatedEvents.map((item, index) => (
                      <tr 
                        key={index} 
                        className="border-b border-secondary/15 hover:bg-slate-50 transition duration-150 align-middle"
                      >
                        <td className="px-4 py-4 font-mono text-slate-700 font-medium">
                          {item.data}
                        </td>
                        <td className="px-4 py-4 font-mono text-slate-500">
                          {item.ora}
                        </td>
                        <td className="px-4 py-4 text-slate-800 font-semibold">
                          {item.filiale || "-"}
                        </td>
                        <td className="px-4 py-4 text-slate-700">
                          {item.descrizione}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/* PAGINATION CONTROLLER */}
            {filteredSortedEvents.length > itemsPerPage && (
              <div className="flex justify-between items-center mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
                <span>
                  Mostrati da <strong>{(currentPage - 1) * itemsPerPage + 1}</strong> a{" "}
                  <strong>{Math.min(filteredSortedEvents.length, currentPage * itemsPerPage)}</strong> di{" "}
                  <strong>{filteredSortedEvents.length}</strong> record
                </span>

                <div className="flex items-center gap-1">
                  <button
                    disabled={currentPage === 1}
                    onClick={() => setCurrentPage((prev) => prev - 1)}
                    className="px-2.5 py-1 border border-slate-300 rounded hover:bg-slate-50 transition text-[11px] font-semibold text-slate-600 disabled:opacity-40 disabled:hover:bg-transparent"
                  >
                    Prec
                  </button>
                  
                  <div className="flex items-center gap-1">
                    {Array.from({ length: totalPages }, (_, i) => i + 1).map((pg) => (
                      <button
                        key={pg}
                        onClick={() => setCurrentPage(pg)}
                        className={`h-6 w-6 rounded text-xs font-bold transition ${
                          currentPage === pg 
                            ? "bg-red-650 text-white" 
                            : "hover:bg-slate-100 text-slate-600"
                        }`}
                      >
                        {pg}
                      </button>
                    ))}
                  </div>

                  <button
                    disabled={currentPage === totalPages}
                    onClick={() => setCurrentPage((prev) => prev + 1)}
                    className="px-2.5 py-1 border border-slate-300 rounded hover:bg-slate-50 transition text-[11px] font-semibold text-slate-600 disabled:opacity-40 disabled:hover:bg-transparent"
                  >
                    Succ
                  </button>
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
