// src/App.jsx
import React, { useState } from 'react';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import StoreMenu from './pages/StoreMenu';
import Footer from './components/Footer';

// Importação dos Modais
import LoginModal from './components/LoginModal';
import OrdersModal from './components/OrdersModal';
import SettingsModal from './components/SettingsModal';
import { clearCustomerSession, migrateLegacyStorage } from './utils/customerSession';
import { useCustomerAuthConfig } from './hooks/useCustomerAuthConfig';

migrateLegacyStorage();

export default function App() {
  const [storeData, setStoreData] = useState({ name: '', color: '' });
  const { otpLoginEnabled, message: authMessage } = useCustomerAuthConfig();
  
  const [isLoginOpen, setIsLoginOpen] = useState(false);
  const [isOrdersOpen, setIsOrdersOpen] = useState(false);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);

  const [user, setUser] = useState(() => {
  const token = localStorage.getItem('token');
  if (!token) return null;

  const saved = localStorage.getItem('user');
  if (!saved || saved === 'undefined') return null;
  
  try {
    return JSON.parse(saved);
  } catch (e) {
    localStorage.removeItem('user');
    return null;
  }
});

  const handleLoginSuccess = (userData) => {
    if (localStorage.getItem('token')) {
      setUser(userData);
      window.dispatchEvent(new CustomEvent('customer-auth-toast', {
        detail: { type: 'login', user: userData }
      }));
    }
  };

  const handleLogout = () => {
    clearCustomerSession();
    setUser(null);
  };

  return (
    <BrowserRouter>
      <div className="min-h-screen flex flex-col justify-between bg-[#fafafa]">
        
        <div className="flex-1">
          <Routes>
            <Route 
              path="/:store_slug" 
              element={
                <StoreMenu 
                  setGlobalStore={setStoreData} 
                  user={user}
                  isAuthenticated={!!user}
                  otpLoginEnabled={otpLoginEnabled}
                  authMessage={authMessage}
                  onLogin={() => {
                    if (otpLoginEnabled) setIsLoginOpen(true);
                  }}
                  onLogout={handleLogout}
                  onOpenOrders={() => setIsOrdersOpen(true)}
                  onOpenSettings={() => setIsSettingsOpen(true)}
                />
              } 
            />
          </Routes>
        </div>

        <Footer storeName={storeData.name} />
        
      </div>

      {/* Modais Globais */}
      {otpLoginEnabled ? (
        <LoginModal 
          isOpen={isLoginOpen} 
          onClose={() => setIsLoginOpen(false)} 
          onSuccess={handleLoginSuccess}
        />
      ) : null}

      <OrdersModal 
        isOpen={isOrdersOpen} 
        onClose={() => setIsOrdersOpen(false)}
        otpLoginEnabled={otpLoginEnabled}
        authMessage={authMessage}
        onLoginRequired={() => {
          if (!otpLoginEnabled) return;
          setIsOrdersOpen(false);
          setIsLoginOpen(true);
        }}
      />

      <SettingsModal 
        isOpen={isSettingsOpen} 
        onClose={() => setIsSettingsOpen(false)}
        otpLoginEnabled={otpLoginEnabled}
        onLoginRequired={() => {
          if (!otpLoginEnabled) return;
          setIsSettingsOpen(false);
          setIsLoginOpen(true);
        }}
      />
    </BrowserRouter>
  );
}