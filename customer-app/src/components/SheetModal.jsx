import React from 'react';
import { X } from 'lucide-react';

export default function SheetModal({
  isOpen,
  onClose,
  title,
  subtitle,
  children,
  footer = null,
  maxWidth = 'max-w-lg'
}) {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-[999] flex items-end sm:items-center justify-center p-0 sm:p-4">
      <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" onClick={onClose} />

      <div className={`relative bg-white w-full ${maxWidth} h-[92dvh] max-h-[92dvh] sm:h-auto sm:max-h-[92dvh] rounded-t-3xl sm:rounded-3xl overflow-hidden shadow-2xl flex flex-col min-h-0`}>
        <div className="shrink-0 px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-white">
          <div>
            <h2 className="font-black text-lg text-slate-900">{title}</h2>
            {subtitle && (
              <p className="text-xs font-semibold text-slate-400 mt-0.5">{subtitle}</p>
            )}
          </div>
          <button onClick={onClose} className="p-2 rounded-xl text-slate-400 hover:bg-slate-100" type="button">
            <X size={20} />
          </button>
        </div>

        <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain bg-slate-50/50 flex flex-col">
          {children}
        </div>

        {footer && (
          <div className="shrink-0 px-5 py-4 border-t border-slate-100 bg-white">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
