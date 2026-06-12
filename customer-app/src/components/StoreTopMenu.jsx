import React, { useState, useEffect } from 'react';
import CustomerToast from './CustomerToast';
import {
  Home,
  ReceiptText,
  LogIn,
  LogOut,
  User,
  MapPin
} from 'lucide-react';
import {
  clearCustomerSession,
  migrateLegacyStorage,
  readLocalCustomer
} from '../utils/customerSession';
import { buildHoursMetaLabel } from '../utils/storeMeta';

const MenuToggleBars = ({ open, className = '' }) => (
  <span className={`relative block w-6 h-5 ${className}`}>
    <span
      className={`absolute left-0 top-0 h-0.5 w-6 rounded-full bg-current transition-all duration-300 ease-out ${
        open ? 'translate-y-[9px] rotate-45' : ''
      }`}
    />
    <span
      className={`absolute left-0 top-[9px] h-0.5 w-6 rounded-full bg-current transition-all duration-200 ease-out ${
        open ? 'opacity-0 scale-x-0' : 'opacity-100 scale-x-100'
      }`}
    />
    <span
      className={`absolute left-0 top-[18px] h-0.5 w-6 rounded-full bg-current transition-all duration-300 ease-out ${
        open ? '-translate-y-[9px] -rotate-45' : ''
      }`}
    />
  </span>
);

export default function StoreTopMenu({
  store,
  deliveryFee: _deliveryFee,
  deliverySummary: _deliverySummary = null,
  isAuthenticated: _isAuthenticated = false,
  user = null,
  onHome,
  onOpenOrders,
  onOpenSettings,
  onLogin,
  onLogout
}) {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [localUser, setLocalUser] = useState(null);
  const [hasToken, setHasToken] = useState(false);
  const [authToast, setAuthToast] = useState('');

  const showAuthToast = (message) => {
    setAuthToast(message);
    setTimeout(() => setAuthToast(''), 3000);
  };

  useEffect(() => {
    const handleAuthToast = (event) => {
      if (event.detail?.type !== 'login') return;

      const profile = event.detail.user;
      const firstName = (profile?.name || profile?.customer_name || '').trim().split(' ')[0];

      showAuthToast(
        firstName
          ? `Bem-vindo de volta, ${firstName}!`
          : 'Login realizado com sucesso!'
      );
    };

    window.addEventListener('customer-auth-toast', handleAuthToast);
    return () => window.removeEventListener('customer-auth-toast', handleAuthToast);
  }, []);

  useEffect(() => {
    const checkSavedCustomer = () => {
      migrateLegacyStorage();
      const token = localStorage.getItem('token');
      setHasToken(!!token);
      setLocalUser(readLocalCustomer());
    };

    checkSavedCustomer();
    window.addEventListener('storage', checkSavedCustomer);
    window.addEventListener('customer-session-updated', checkSavedCustomer);

    return () => {
      window.removeEventListener('storage', checkSavedCustomer);
      window.removeEventListener('customer-session-updated', checkSavedCustomer);
    };
  }, []);

  const currentUser = user || localUser;
  const isLoggedIn = hasToken;

  const displayName = isLoggedIn
    ? (currentUser?.name || currentUser?.customer_name || 'Cliente')
    : currentUser?.name || currentUser?.customer_name
      ? (currentUser.name || currentUser.customer_name).split(' ')[0]
      : 'Visitante';

  const subtitle = isLoggedIn
    ? 'Conta verificada'
    : currentUser?.phone || currentUser?.customer_phone
      ? 'Visitante · faça login para ver pedidos'
      : 'Menu de navegação';

  const initials = displayName
    .split(' ')
    .map(part => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();

  const bannerUrl =
    store?.banner_url ||
    'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=1400&auto=format&fit=crop&q=80';

  const isStoreOpen = Boolean(store?.opening_status?.is_open ?? store?.is_open);
  const hoursLabel = buildHoursMetaLabel(store);
  const addressLabel = String(store?.address || '').trim();

  const toggleMenu = () => setIsMenuOpen(value => !value);
  const closeMenu = () => setIsMenuOpen(false);

  useEffect(() => {
    const handleResize = () => {
      if (window.innerWidth < 768) {
        setIsMenuOpen(false);
      }
    };

    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const handleLogin = () => {
    onLogin?.();
    closeMenu();
  };

  const handleLogout = () => {
    clearCustomerSession();
    setLocalUser(null);
    setHasToken(false);
    showAuthToast('Logout realizado com sucesso!');
    onLogout?.();
    closeMenu();
  };

  const openOrders = () => {
    if (!localStorage.getItem('token')) {
      onLogin?.();
    } else {
      onOpenOrders?.();
    }
    closeMenu();
  };

  const openSettings = () => {
    if (!localStorage.getItem('token')) {
      onLogin?.();
    } else {
      onOpenSettings?.();
    }
    closeMenu();
  };

  const mobileItems = [
    { label: 'Início', icon: Home, action: onHome },
    { label: 'Pedidos', icon: ReceiptText, action: openOrders },
    { label: 'Endereço', icon: MapPin, action: openSettings },
    { label: isLoggedIn ? 'Sair' : 'Login', icon: isLoggedIn ? LogOut : User, action: isLoggedIn ? handleLogout : handleLogin }
  ];

  return (
    <>
      <CustomerToast message={authToast} show={Boolean(authToast)} />

      <section className="relative bg-[#fafafa]">
        <div className="relative h-44 sm:h-52 lg:h-48 w-full overflow-hidden bg-slate-950">
          <img
            src={bannerUrl}
            alt={store?.name}
            className="w-full h-full object-cover opacity-75"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-slate-950/75 via-slate-950/20 to-transparent" />

          <button
            onClick={toggleMenu}
            className="hidden md:inline-flex absolute top-4 right-4 z-[70] items-center justify-center w-11 h-11 text-white drop-shadow-lg transition-colors"
            aria-label={isMenuOpen ? 'Fechar menu' : 'Abrir menu'}
            aria-expanded={isMenuOpen}
          >
            <MenuToggleBars open={isMenuOpen} />
          </button>
        </div>

        <div className="max-w-7xl mx-auto px-4 relative">
          <div className="relative pt-12 sm:pt-4 pb-3">
            <img
              src={store?.logo_url}
              alt={store?.name}
              className="absolute -top-10 left-1/2 -translate-x-1/2 sm:left-0 sm:translate-x-0 w-20 h-20 rounded-2xl object-cover border-4 border-[#fafafa] shadow-lg bg-white"
            />

            <div className="sm:pl-28 min-w-0 text-center sm:text-left">
              <div className="flex flex-col gap-1.5">
                <div className="flex items-center justify-center sm:justify-start gap-2 min-w-0">
                  <h1 className="text-xl sm:text-2xl font-black text-slate-950 uppercase tracking-tight leading-none truncate">
                    {store?.name}
                  </h1>
                  <span
                    className={`inline-flex h-2 w-2 shrink-0 rounded-full ${
                      isStoreOpen ? 'bg-emerald-500' : 'bg-slate-400'
                    }`}
                    aria-hidden="true"
                  />
                </div>

                {store?.description && (
                  <p className="text-sm text-slate-500 w-full max-w-2xl truncate">
                    {store.description}
                  </p>
                )}

                {(hoursLabel || addressLabel) && (
                  <div className="flex flex-wrap items-center justify-center sm:justify-start gap-x-2 gap-y-0.5 pt-0.5 text-[11px] sm:text-xs leading-relaxed">
                    {hoursLabel && (
                      <span className={`font-semibold ${isStoreOpen ? 'text-emerald-600' : 'text-amber-600'}`}>
                        {hoursLabel}
                      </span>
                    )}
                    {hoursLabel && addressLabel && (
                      <span className="text-slate-300" aria-hidden="true">·</span>
                    )}
                    {addressLabel && (
                      <span className="font-medium text-slate-500">
                        {addressLabel}
                      </span>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </section>

      <div
        className={`hidden md:block fixed inset-0 z-[100] transition-[visibility] duration-500 ${
          isMenuOpen ? 'visible' : 'invisible pointer-events-none delay-0'
        }`}
      >
        <div
          className={`absolute inset-0 bg-slate-950/40 backdrop-blur-[2px] transition-all duration-500 ease-out ${
            isMenuOpen ? 'opacity-100 backdrop-blur-sm' : 'opacity-0 backdrop-blur-none'
          }`}
          onClick={closeMenu}
        />

        <aside
          className={`absolute top-0 right-0 h-full w-full max-w-sm bg-white shadow-2xl flex flex-col transform transition-[transform,box-shadow] duration-500 ease-[cubic-bezier(0.32,0.72,0,1)] ${
            isMenuOpen ? 'translate-x-0 shadow-2xl' : 'translate-x-full shadow-none'
          }`}
        >
          <div
            className={`p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50 shrink-0 transition-all duration-500 ease-out ${
              isMenuOpen ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'
            }`}
            style={{ transitionDelay: isMenuOpen ? '120ms' : '0ms' }}
          >
            <div className="flex items-center gap-3 min-w-0">
              <div className="w-11 h-11 rounded-full bg-slate-900 text-white flex items-center justify-center font-black text-sm shrink-0">
                {initials}
              </div>
              <div className="min-w-0 text-left">
                <h3 className="text-sm font-black text-slate-900 truncate">{displayName}</h3>
                <p className="text-xs font-semibold text-slate-400 truncate">{subtitle}</p>
              </div>
            </div>
            <button onClick={closeMenu} className="p-2 rounded-xl text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all" aria-label="Fechar menu">
              <MenuToggleBars open />
            </button>
          </div>

          <nav className="flex-1 overflow-y-auto p-5 space-y-1 bg-white">
            {[
              { label: 'Início', icon: Home, action: () => { onHome?.(); closeMenu(); } },
              { label: 'Meus Pedidos', icon: ReceiptText, action: openOrders },
              { label: 'Meu endereço', icon: MapPin, action: openSettings }
            ].map(({ label, icon: Icon, action }, index) => (
              <button
                key={label}
                type="button"
                onClick={action}
                className={`group w-full px-4 py-3 rounded-xl flex items-center gap-3 text-slate-700 hover:bg-slate-50 hover:text-slate-900 font-bold text-sm text-left transition-all duration-300 ease-out ${
                  isMenuOpen ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-3'
                }`}
                style={{ transitionDelay: isMenuOpen ? `${180 + index * 55}ms` : '0ms' }}
              >
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600 transition-colors group-hover:bg-[var(--store-primary)]/10 group-hover:text-[var(--store-primary)]">
                  <Icon size={18} />
                </span>
                {label}
              </button>
            ))}
          </nav>

          <div
            className={`p-5 pt-4 border-t border-slate-100 bg-white shrink-0 transition-all duration-500 ease-out ${
              isMenuOpen ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3'
            }`}
            style={{ transitionDelay: isMenuOpen ? '380ms' : '0ms' }}
          >
            {isLoggedIn ? (
              <button
                type="button"
                onClick={handleLogout}
                className="w-full h-11 px-4 rounded-xl text-slate-500 hover:text-red-600 hover:bg-red-50 font-semibold text-sm flex items-center justify-start gap-2.5 transition-colors duration-200"
              >
                <LogOut size={16} />
                Sair
              </button>
            ) : (
              <button
                type="button"
                onClick={handleLogin}
                className="w-full h-11 px-4 rounded-xl bg-[var(--store-primary)] text-white font-bold text-sm flex items-center justify-start gap-2.5 transition-opacity duration-200 hover:opacity-90"
              >
                <LogIn size={16} />
                Entrar com WhatsApp
              </button>
            )}
          </div>
        </aside>
      </div>

      <div className="md:hidden fixed bottom-0 left-0 right-0 z-50 h-14 bg-white border-t border-slate-100 px-2 shadow-lg">
        <div className="grid h-full grid-cols-4 gap-1">
          {mobileItems.map((item, index) => {
            const Icon = item.icon;

            return (
              <button
                key={index}
                type="button"
                onClick={() => item.action?.()}
                className="flex flex-col items-center justify-center text-slate-500 hover:text-slate-900 transition-colors"
              >
                <Icon size={20} />
                <span className="text-[10px] font-bold mt-0.5">{item.label}</span>
              </button>
            );
          })}
        </div>
      </div>
    </>
  );
}
