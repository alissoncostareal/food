import { Loader2 } from 'lucide-react';

const SIZE_MAP = {
  sm: { icon: 24, minH: 'min-h-[220px]' },
  md: { icon: 32, minH: 'min-h-[min(420px,58vh)]' },
  lg: { icon: 40, minH: 'min-h-[min(480px,62vh)]' }
};

export default function CustomerLoadingPanel({
  message = 'Carregando...',
  size = 'md',
  className = ''
}) {
  const config = SIZE_MAP[size] || SIZE_MAP.md;

  return (
    <div
      className={`flex w-full flex-1 flex-col items-center justify-center gap-3 p-6 ${config.minH} ${className}`}
    >
      <Loader2
        className="animate-spin"
        style={{ color: 'var(--store-primary)' }}
        size={config.icon}
        strokeWidth={2.25}
      />
      <p className="text-xs font-bold text-slate-500 text-center max-w-[240px] leading-relaxed">
        {message}
      </p>
    </div>
  );
}

export function CustomerInlineSpinner({ size = 16, className = '' }) {
  return (
    <Loader2
      size={size}
      strokeWidth={2.25}
      className={`animate-spin ${className}`}
      style={{ color: 'var(--store-primary)' }}
    />
  );
}

export const customerPanelMinHeight = SIZE_MAP.md.minH;
