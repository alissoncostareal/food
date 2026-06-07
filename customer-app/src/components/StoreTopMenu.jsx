import React, { useState, useEffect } from 'react';
import {
  Home,
  ReceiptText,
  Settings,
  LogIn,
  LogOut,
  User,
  MapPin,
  Bike,
  X,
  CheckCircle // Adicionado para o ícone de sucesso no logout
} from 'lucide-react';

export default function StoreTopMenu({
  store,
  deliveryFee,
  isAuthenticated = false,
  user = null,
  onHome,
  onOpenAbout,
  onOpenOrders,
  onOpenSettings,
  onLogin,
  onLogout
}) {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [localUser, setLocalUser] = useState(null);
  const [hasToken, setHasToken] = useState(false);
  
  const [showLogoutToast, setShowLogoutToast] = useState(false);

  useEffect(() => {
    const checkSavedCustomer = () => {
      const saved = localStorage.getItem('user');
      const token = localStorage.getItem('token');
      setHasToken(!!token);
      
      if (saved) {
        try {
          setLocalUser(JSON.parse(saved));
        } catch (e) {
          console.error(e);
        }
      } else {
        setLocalUser(null);
      }
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
  const isClientIdentified = isAuthenticated || !!currentUser;

  const displayName = isClientIdentified 
    ? (currentUser?.name || currentUser?.customer_name || 'Cliente') 
    : 'Visitante';
    
  const subtitle = isClientIdentified ? 'Cliente identificado' : 'Menu de navegação';
  
  const initials = displayName
    .split(' ')
    .map(part => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();

  const bannerUrl =
    store?.banner_url ||
    'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=1400&auto=format&fit=crop&q=80';

  const toggleMenu = () => setIsMenuOpen(value => !value);
  const openMenu = () => setIsMenuOpen(true);
  const closeMenu = () => setIsMenuOpen(false);

  const handleLogin = () => {
    if (typeof onLogin === 'function') {
      onLogin();
    }
    closeMenu();
  };

  const handleLogout = () => {
    localStorage.removeItem('user');
    localStorage.removeItem('token');
    localStorage.removeItem('@fooddash:customer');
    setLocalUser(null);
    setHasToken(false);
    window.dispatchEvent(new Event('customer-session-updated'));

    setShowLogoutToast(true);
    
    setTimeout(() => {
      setShowLogoutToast(false);
    }, 3000);

    if (typeof onLogout === 'function') {
      onLogout();
    }
    closeMenu();
  };

  const mobileItems = [
    {
      label: 'Início',
      icon: Home,
      action: onHome
    },
    {
      label: 'Pedidos',
      icon: ReceiptText,
      action: () => {
        const token = localStorage.getItem('token');

        if (!token) {
          onLogin?.();
          return;
        }

        onOpenOrders?.();
      }
    },
    {
      label: 'Endereço',
      icon: MapPin,
      action: () => {
        const token = localStorage.getItem('token');

        if (!token) {
          onLogin?.();
          return;
        }

        onOpenSettings?.();
      }
    },
    {
      label: isClientIdentified ? 'Sair' : 'Login',
      icon: isClientIdentified ? LogOut : User,
      action: isClientIdentified ? handleLogout : handleLogin
    }
  ];

  return (
    <>
      {/* Toast Notificação de Logout */}
      {showLogoutToast && (
        <div className="fixed top-5 left-1/2 -translate-x-1/2 z-[9999] bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-xs uppercase tracking-wider shadow-2xl flex items-center gap-2 border border-slate-800 transition-all duration-300 animate-in fade-in slide-in-from-top-4">
          <CheckCircle size={16} className="text-emerald-400" />
          <span>Você saiu com sucesso!</span>
        </div>
      )}

      <section className="relative bg-[#fafafa]">
        <div className="relative h-56 sm:h-64 w-full overflow-hidden bg-slate-950">
          <img
            src={bannerUrl}
            alt={store?.name}
            className="w-full h-full object-cover opacity-75"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-slate-950/20 to-transparent" />

          <button
            onClick={onHome}
            className="hidden md:block absolute top-4 left-4 z-20 font-black text-xl text-white tracking-tight drop-shadow-lg"
          >
            food<span className="font-normal">dash</span>
          </button>

          <button
            onClick={toggleMenu}
            className="hidden md:inline-flex absolute top-4 right-4 z-[70] items-center justify-center w-11 h-11 text-white drop-shadow-lg transition-colors"
            aria-label={isMenuOpen ? 'Fechar menu' : 'Abrir menu'}
            aria-expanded={isMenuOpen}
          >
            <span className="relative block w-6 h-5">
              <span
                className={`absolute left-0 top-0 h-0.5 w-6 rounded-full bg-current transition-all duration-300 ease-out ${
                  isMenuOpen ? 'translate-y-[9px] rotate-45' : ''
                }`}
              />
              <span
                className={`absolute left-0 top-[9px] h-0.5 w-6 rounded-full bg-current transition-all duration-200 ease-out ${
                  isMenuOpen ? 'opacity-0 scale-x-0' : 'opacity-100 scale-x-100'
                }`}
              />
              <span
                className={`absolute left-0 top-[18px] h-0.5 w-6 rounded-full bg-current transition-all duration-300 ease-out ${
                  isMenuOpen ? '-translate-y-[9px] -rotate-45' : ''
                }`}
              />
            </span>
          </button>
        </div>

        <div className="max-w-7xl mx-auto px-4 relative">
          <div className="relative pt-14 sm:pt-5 pb-5">
            <img
              src={store?.logo_url}
              alt={store?.name}
              className="absolute -top-12 left-1/2 -translate-x-1/2 sm:left-0 sm:translate-x-0 w-24 h-24 rounded-2xl object-cover border-4 border-[#fafafa] shadow-xl bg-white"
            />

            <div className="sm:pl-32 min-w-0 text-center sm:text-left">
              <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center justify-center sm:justify-start gap-2 flex-wrap">
                    <h1 className="text-2xl sm:text-3xl font-black text-slate-950 uppercase tracking-tight leading-none">
                      {store?.name}
                    </h1>

                    <span className={`md:hidden inline-flex items-center gap-1.5 text-[11px] font-black px-2.5 py-1 rounded-full border ${
                      store?.is_open
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-slate-100 text-slate-500 border-slate-200'
                    }`}>
                      <span className={`w-1.5 h-1.5 rounded-full ${store?.is_open ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                      {store?.is_open ? 'Aberto agora' : 'Fechado'}
                    </span>
                  </div>

                  {store?.description && (
                    <p className="hidden md:block text-sm text-slate-500 mt-2 max-w-2xl mx-auto sm:mx-0 leading-relaxed">
                      {store.description}
                    </p>
                  )}
                </div>

                <div className="hidden md:flex items-center gap-2 flex-wrap">
                  <span className={`inline-flex items-center gap-1.5 text-[11px] font-black px-2.5 py-1 rounded-full border ${
                    store?.is_open
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      : 'bg-slate-100 text-slate-500 border-slate-200'
                  }`}>
                    <span className={`w-1.5 h-1.5 rounded-full ${store?.is_open ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                    {store?.is_open ? 'Aberto agora' : 'Fechado'}
                  </span>
                </div>
              </div>

              <div className="mt-4 flex flex-col sm:flex-row sm:flex-wrap items-center justify-center sm:justify-start gap-1 sm:gap-x-3 sm:gap-y-1 text-[11px] font-bold text-slate-500">
                <span className="inline-flex items-center justify-center sm:justify-start gap-1 py-1">
                  <Bike className="w-3.5 h-3.5 text-[var(--store-primary)]" />
                  {deliveryFee === 0 ? 'Entrega grátis' : `Entrega R$ ${Number(deliveryFee || 0).toFixed(2)}`}
                </span>
                <span className="inline-flex items-center justify-center sm:justify-start gap-1 py-1">
                  <MapPin className="w-3.5 h-3.5 text-[var(--store-primary)]" />
                  {store?.address || 'Consulte nosso endereço'}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div className={`fixed inset-0 z-[100] ${isMenuOpen ? 'visible' : 'invisible'}`}>
        <div
          className={`absolute inset-0 bg-slate-950/40 backdrop-blur-sm transition-opacity duration-300 ${
            isMenuOpen ? 'opacity-100' : 'opacity-0'
          }`}
          onClick={closeMenu}
        />

        <aside
          className={`absolute top-0 right-0 h-full w-full max-w-sm bg-white shadow-2xl flex flex-col transition-transform duration-300 ease-out transform ${
            isMenuOpen ? 'translate-x-0' : 'translate-x-full'
          }`}
        >
          <div className="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div className="flex items-center gap-3">
              <div className="w-11 h-11 rounded-full bg-slate-900 text-white flex items-center justify-center font-black text-sm">
                {initials}
              </div>
              <div className="text-left">
                <h3 className="text-sm font-black text-slate-900">{displayName}</h3>
                <p className="text-xs font-semibold text-slate-400">{subtitle}</p>
              </div>
            </div>
            <button
              onClick={closeMenu}
              className="p-2 rounded-xl text-slate-400 hover:bg-slate-200 hover:text-slate-700 transition-all"
            >
              <X size="20" />
            </button>
          </div>

          <div className="flex-1 overflow-y-auto p-5 space-y-6">
            {currentUser && !hasToken && (
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-3 text-left">
                <div>
                  <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider block">WhatsApp</span>
                  <span className="text-sm font-bold text-slate-800">
                    {currentUser.phone || currentUser.customer_phone || 'Não informado'}
                  </span>
                </div>
                {currentUser.address && (
                  <div>
                    <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Endereço de Entrega</span>
                    <p className="text-sm font-bold text-slate-800 leading-tight">
                      {currentUser.address}{currentUser.address_number ? `, ${currentUser.address_number}` : ''}
                      {currentUser.address_complement && ` - ${currentUser.address_complement}`}
                    </p>
                    {currentUser.district && (
                      <p className="text-xs font-semibold text-slate-400 mt-0.5">{currentUser.district}</p>
                    )}
                  </div>
                )}
              </div>
            )}

            <nav className="space-y-1">
              <button
                onClick={() => { onHome?.(); closeMenu(); }}
                className="w-full px-4 py-3 rounded-xl flex items-center gap-3 text-slate-600 hover:bg-slate-50 font-bold text-sm transition-all text-left"
              >
                <Home size="18" />
                Início
              </button>
              <button
                onClick={() => {
                  const token = localStorage.getItem('token');

                  if (!token) {
                    onLogin?.();
                  } else {
                    onOpenOrders?.();
                  }

                  closeMenu();
                }}
                className="w-full px-4 py-3 rounded-xl flex items-center gap-3 text-slate-600 hover:bg-slate-50 font-bold text-sm transition-all text-left"
              >
                <ReceiptText size="18" />
                Meus Pedidos
              </button>
              <button
                onClick={() => {
                  const token = localStorage.getItem('token');

                  if (!token) {
                    onLogin?.();
                  } else {
                    onOpenSettings?.();
                  }

                  closeMenu();
                }}
                className="w-full px-4 py-3 rounded-xl flex items-center gap-3 text-slate-600 hover:bg-slate-50 font-bold text-sm transition-all text-left"
              >
                <MapPin size="18" />
                Endereço
              </button>
            </nav>
          </div>

          <div className="p-5 border-t border-slate-100">
            {isClientIdentified ? (
              <button
                onClick={handleLogout}
                className="w-full h-11 border border-red-200 text-red-600 bg-red-50/50 hover:bg-red-50 rounded-xl font-bold text-sm flex items-center justify-center gap-2 transition-all"
              >
                <LogOut size="16" />
                Sair da Conta
              </button>
            ) : (
              <button
                onClick={handleLogin}
                className="w-full h-11 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-black text-sm flex items-center justify-center gap-2 transition-all"
              >
                <LogIn size="16" />
                Login
              </button>
            )}
          </div>
        </aside>
      </div>

      <div className="md:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-100 z-50 px-2 py-1.5 shadow-lg">
        <div className="grid grid-cols-4 gap-1">
          {mobileItems.map((item, index) => {
            const Icon = item.icon;
            return (
              <button
                key={index}
                onClick={() => {
                  if (typeof item.action === 'function') {
                    item.action();
                  }
                }}
                className="flex flex-col items-center justify-center py-1 text-slate-500 hover:text-slate-900 transition-all"
              >
                <Icon size="20" />
                <span className="text-[10px] font-bold mt-0.5">{item.label}</span>
              </button>
            );
          })}
        </div>
      </div>
    </>
  );
}