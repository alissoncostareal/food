import React from 'react';
import { formatBrazilPhoneInput } from '../utils/phoneInput';

const defaultInputClass =
  'flex-1 min-w-0 h-12 px-4 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-900 outline-none focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10 transition-all placeholder:text-slate-400 placeholder:font-medium disabled:opacity-60';

export default function BrazilPhoneInput({
  value,
  onChange,
  onBlur,
  disabled = false,
  required = false,
  className = '',
  inputClassName = defaultInputClass,
  id
}) {
  return (
    <div className={`flex items-center gap-2 ${className}`}>
      <span className="text-sm font-semibold text-slate-400 shrink-0 select-none tabular-nums">
        +55
      </span>
      <input
        id={id}
        type="tel"
        value={value}
        onChange={(e) => onChange?.(formatBrazilPhoneInput(e.target.value))}
        onBlur={onBlur}
        placeholder="(85) 99999-9999"
        inputMode="numeric"
        autoComplete="tel"
        required={required}
        disabled={disabled}
        className={inputClassName}
      />
    </div>
  );
}
