// src/components/Footer.jsx
import React from 'react';

const platformUrl = (import.meta.env.VITE_PLATFORM_URL || 'https://partiumenu.com.br').replace(/\/+$/, '');
const platformLabel = platformUrl.replace(/^https?:\/\//, '');

export default function Footer({ storeName }) {
  if (!storeName) return null;

  const currentYear = new Date().getFullYear();

  return (
    <footer
      style={{ backgroundColor: 'var(--store-primary)' }}
      className="w-full mt-12 pt-8 pb-35 md:pb-8 px-4 transition-colors duration-300 shadow-inner"
    >
      <div className="max-w-7xl mx-auto flex flex-col items-center justify-center text-center space-y-2">
        <p className="text-xs font-bold text-white tracking-wide">
          <span className="uppercase font-black mr-1">
            {storeName}
          </span>
          - {currentYear}. Todos os direitos reservados.
        </p>
        <p className="text-[11px] text-white/80 font-medium">
          Plataforma fornecida por{' '}
          <a
            href={platformUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="font-semibold text-white underline underline-offset-2 hover:text-white/90"
          >
            {platformLabel}
          </a>
        </p>
      </div>
    </footer>
  );
}
