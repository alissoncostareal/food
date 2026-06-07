import React, { useState, useEffect } from 'react';
import { X, MapPin, User, Smartphone, Save, Loader2 } from 'lucide-react'; // 🌟 Importado o Loader2
import api from '../services/api';

export default function SettingsModal({ isOpen, onClose, onLoginRequired }) {
  const [form, setForm] = useState({
    name: '',
    phone: '',
    address: '',
    address_number: '',
    district: '',
    address_complement: ''
  });
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });

  const token = typeof window !== 'undefined' ? localStorage.getItem('token') : null;

  useEffect(() => {
    if (!isOpen) return;
    setMessage({ type: '', text: '' });
    if (!token) {
      const timer = setTimeout(() => {
        onLoginRequired?.();
      }, 10);
      return () => clearTimeout(timer);
    }
    
    const savedUser = localStorage.getItem('user');
    const savedLocal = localStorage.getItem('@fooddash:customer');
    const baseData = savedUser ? JSON.parse(savedUser) : (savedLocal ? JSON.parse(savedLocal) : null);
    
    if (baseData) {
      setForm({
        name: baseData.name || '',
        phone: baseData.phone || '',
        address: baseData.address || '',
        address_number: baseData.address_number || '',
        district: baseData.district || '',
        address_complement: baseData.address_complement || ''
      });
    }

    const fetchFreshProfile = async () => {
      try {
        const { data } = await api.get('/customer/profile', {
          headers: { Authorization: `Bearer ${token}` }
        });

        if (data.user) {
          setForm({
            name: data.user.name || '',
            phone: data.user.phone || '',
            address: data.user.address || '',
            address_number: data.user.address_number || '',
            district: data.user.district || '',
            address_complement: data.user.address_complement || ''
          });
          localStorage.setItem('user', JSON.stringify(data.user));
        }
      } catch (err) {
        if (err.response?.status === 401) {
          onLoginRequired?.();
        }
      }
    };

    fetchFreshProfile();
  }, [isOpen, token]);

  if (!isOpen || !token) return null;

  const handleSave = async (e) => {
    e.preventDefault();
    setLoading(true);
    setMessage({ type: '', text: '' });

    try {
      if (!token) {
        onLoginRequired?.();
        return;
      }

      const { data } = await api.put('/customer/profile', form, {
        headers: { Authorization: `Bearer ${token}` }
      });
      
      if (data.user) {
        localStorage.setItem('user', JSON.stringify(data.user));
      }

      const dadosSessao = {
        name: form.name,
        phone: form.phone,
        address: form.address,
        address_number: form.address_number,
        district: form.district,
        address_complement: form.address_complement
      };
      localStorage.setItem('@fooddash:customer', JSON.stringify(dadosSessao));
      
      window.dispatchEvent(new Event('customer-session-updated'));

      setMessage({ type: 'success', text: 'Dados salvos com sucesso!' });
      setTimeout(onClose, 1500);
    } catch (err) {
      setMessage({ 
        type: 'error', 
        text: err.response?.data?.message || 'Não foi possível atualizar as informações.' 
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" onClick={onClose} />

      <div className="relative bg-white w-full max-w-md rounded-3xl overflow-hidden shadow-2xl flex flex-col">
        <div className="p-5 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h2 className="font-black text-xl text-slate-900">Configurações da Conta</h2>
            <p className="text-xs font-semibold text-slate-400">Gerencie seus dados padrão de entrega</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-xl text-slate-400 hover:bg-slate-100">
            <X size={20} />
          </button>
        </div>

        <form onSubmit={handleSave} className="p-5 space-y-4 text-left">
          {message.text && (
            <div className={`p-3 rounded-xl text-xs font-bold border text-center ${
              message.type === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-red-50 text-red-700 border-red-100'
            }`}>
              {message.text}
            </div>
          )}

          <div className="space-y-1">
            <label className="text-[10px] font-black text-slate-400 uppercase">Nome Completo</label>
            <div className="relative">
              <User className="absolute left-3 top-3.5 w-4 h-4 text-slate-400" />
              <input
                type="text"
                value={form.name}
                onChange={e => setForm({...form, name: e.target.value})}
                required
                className="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
              />
            </div>
          </div>

          <div className="space-y-1">
            <label className="text-[10px] font-black text-slate-400 uppercase">WhatsApp</label>
            <div className="relative">
              <Smartphone className="absolute left-3 top-3.5 w-4 h-4 text-slate-400" />
              <input
                type="tel"
                value={form.phone}
                onChange={e => setForm({...form, phone: e.target.value})}
                required
                placeholder="Ex: 85999999999"
                className="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
              />
            </div>
          </div>

          <div className="border-t border-slate-100 pt-3 space-y-3">
            <h4 className="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-1">
              <MapPin size={14} className="text-[var(--store-primary)]" /> Endereço Padrão
            </h4>

            <div className="space-y-1">
              <label className="text-[10px] font-black text-slate-400 uppercase">Rua / Avenida</label>
              <input
                type="text"
                value={form.address}
                onChange={e => setForm({...form, address: e.target.value})}
                placeholder="Ex: Av. Beira Mar"
                className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <label className="text-[10px] font-black text-slate-400 uppercase">Número</label>
                <input
                  type="text"
                  value={form.address_number}
                  onChange={e => setForm({...form, address_number: e.target.value})}
                  placeholder="Ex: 123 ou S/N"
                  className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                />
              </div>
              <div className="space-y-1">
                <label className="text-[10px] font-black text-slate-400 uppercase">Bairro</label>
                <input
                  type="text"
                  value={form.district}
                  onChange={e => setForm({...form, district: e.target.value})}
                  placeholder="Ex: Centro"
                  className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                />
              </div>
            </div>

            <div className="space-y-1">
              <label className="text-[10px] font-black text-slate-400 uppercase">Complemento</label>
              <input
                type="text"
                value={form.address_complement}
                onChange={e => setForm({...form, address_complement: e.target.value})}
                placeholder="Ex: Apt 402, Bloco B / Próximo ao mercado"
                className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
              />
            </div>
          </div>

          {/* 🌟 Botão Atualizado com o Spinner */}
          <button
            type="submit"
            disabled={loading}
            className="w-full h-12 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm uppercase rounded-xl flex items-center justify-center gap-2 transition-all shadow-md mt-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {loading ? (
              <Loader2 size={16} className="animate-spinanimate-spin text-[var(--store-primary)]" />
            ) : (
              <Save size={16} />
            )}
            {loading ? 'Salvando...' : 'Salvar Alterações'}
          </button>
        </form>
      </div>
    </div>
  );
}