import React from 'react';
import { CheckCircle } from 'lucide-react';

export default function CustomerToast({ message, show }) {
  if (!show || !message) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      className="fixed top-5 left-1/2 -translate-x-1/2 z-[9999] max-w-[calc(100vw-2rem)] bg-slate-900 text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-2xl flex items-center gap-2.5 border border-slate-800 animate-in fade-in slide-in-from-top-4 duration-300"
    >
      <CheckCircle size={18} className="text-emerald-400 shrink-0" />
      <span>{message}</span>
    </div>
  );
}
