import React, { useState, useEffect } from 'react';
import { X, MapPin, User, Smartphone, Save, Loader2 } from 'lucide-react';
import api from '../services/api';

const emptyForm = {
  name: '',
  phone: '',
  address: '',
  address_number: '',
  district: '',
  address_complement: ''
};

const safeJsonParse = (value) => {
  try {
    return value ? JSON.parse(value) : null;
  } catch {
    return null;
  }
};

const getProfileFromResponse = (data) => {
  return data?.user || data?.customer || data?.data?.user || data?.data?.customer || null;
};

const buildFormFromCustomer = (customer, fallback = emptyForm) => {
  return {
    name: customer?.name || customer?.customer_name || fallback.name || '',
    phone: customer?.phone || customer?.customer_phone || fallback.phone || '',
    address: customer?.address || fallback.address || '',
    address_number: customer?.address_number || customer?.number || fallback.address_number || '',
    district: customer?.district || customer?.neighborhood || fallback.district || '',
    address_complement: customer?.address_complement || customer?.complement || fallback.address_complement || ''
  };
};

export default function SettingsModal({ isOpen, onClose, onLoginRequired }) {
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(false);
  const [profileLoading, setProfileLoading] = useState(true);
  const [message, setMessage] = useState({ type: '', text: '' });

  const token = typeof window !== 'undefined' ? localStorage.getItem('token') : null;

  useEffect(() => {
    if (!isOpen) return;

    setProfileLoading(true);
    setMessage({ type: '', text: '' });

    if (!token) {
      const timer = setTimeout(() => {
        onLoginRequired?.();
      }, 10);

      return () => clearTimeout(timer);
    }

    const savedUser = safeJsonParse(localStorage.getItem('user'));
    const savedCustomer = safeJsonParse(localStorage.getItem('@fooddash:customer'));
    const localForm = buildFormFromCustomer(savedUser || savedCustomer);

    const fetchFreshProfile = async () => {
      try {
        const { data } = await api.get('/customer/profile', {
          headers: { Authorization: `Bearer ${token}` }
        });

        const user = getProfileFromResponse(data);

        if (user) {
          const freshForm = buildFormFromCustomer(user, localForm);

          setForm(freshForm);
          localStorage.setItem('user', JSON.stringify(user));
          localStorage.setItem('@fooddash:customer', JSON.stringify({
            name: freshForm.name,
            customer_name: freshForm.name,
            phone: freshForm.phone,
            customer_phone: freshForm.phone,
            address: freshForm.address,
            address_number: freshForm.address_number,
            district: freshForm.district,
            address_complement: freshForm.address_complement
          }));
        } else {
          setForm(localForm);
        }
      } catch (err) {
        if (err.response?.status === 401) {
          onLoginRequired?.();
          return;
        }

        setForm(localForm);

        setMessage({
          type: 'error',
          text: 'Não foi possível carregar seus dados salvos agora.'
        });
      } finally {
        setProfileLoading(false);
      }
    };

    fetchFreshProfile();
  }, [isOpen, token, onLoginRequired]);

  if (!isOpen || !token) return null;

  const updateForm = (key, value) => {
    setForm(prev => ({ ...prev, [key]: value }));
    setMessage({ type: '', text: '' });
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setLoading(true);
    setMessage({ type: '', text: '' });

    try {
      if (!token) {
        onLoginRequired?.();
        return;
      }

      const payload = {
        name: form.name,
        phone: form.phone,
        address: form.address,
        address_number: form.address_number,
        district: form.district,
        address_complement: form.address_complement
      };

      const { data } = await api.put('/customer/profile', payload, {
        headers: { Authorization: `Bearer ${token}` }
      });

      const user = getProfileFromResponse(data);
      const savedForm = buildFormFromCustomer(user, form);

      if (user) {
        localStorage.setItem('user', JSON.stringify(user));
      }

      localStorage.setItem('@fooddash:customer', JSON.stringify({
        name: savedForm.name,
        customer_name: savedForm.name,
        phone: savedForm.phone,
        customer_phone: savedForm.phone,
        address: savedForm.address,
        address_number: savedForm.address_number,
        district: savedForm.district,
        address_complement: savedForm.address_complement
      }));

      setForm(savedForm);
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

      <div className="relative bg-white w-full max-w-lg max-h-[92vh] rounded-3xl overflow-hidden shadow-2xl flex flex-col">
        <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h2 className="font-black text-lg text-slate-900">Configurações da Conta</h2>
            <p className="text-xs font-semibold text-slate-400">Gerencie seus dados padrão de entrega</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-xl text-slate-400 hover:bg-slate-100">
            <X size={20} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto bg-slate-50/50">
          {profileLoading ? (
            <div className="flex min-h-[420px] flex-col items-center justify-center gap-3 p-5 text-slate-400">
              <Loader2 className="animate-spin text-[var(--store-primary)]" size={32} />
              <span className="text-xs font-bold">Carregando seus dados...</span>
            </div>
          ) : (
            <form onSubmit={handleSave} className="px-5 py-4 space-y-3 text-left bg-white">
              {message.text && (
                <div className={`px-3 py-2 rounded-xl text-xs font-bold border text-center ${
                  message.type === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-red-50 text-red-700 border-red-100'
                }`}>
                  {message.text}
                </div>
              )}

              <div className="grid sm:grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="text-[10px] font-black text-slate-400 uppercase">Nome Completo</label>
                  <div className="relative">
                    <User className="absolute left-3 top-3 w-4 h-4 text-slate-400" />
                    <input
                      type="text"
                      value={form.name}
                      onChange={e => updateForm('name', e.target.value)}
                      required
                      className="w-full h-10 pl-10 pr-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-black text-slate-400 uppercase">WhatsApp</label>
                  <div className="relative">
                    <Smartphone className="absolute left-3 top-3 w-4 h-4 text-slate-400" />
                    <input
                      type="tel"
                      value={form.phone}
                      onChange={e => updateForm('phone', e.target.value)}
                      required
                      placeholder="Ex: 85999999999"
                      className="w-full h-10 pl-10 pr-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                    />
                  </div>
                </div>
              </div>

              <div className="border-t border-slate-100 pt-3 space-y-2.5">
                <h4 className="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-1">
                  <MapPin size={14} className="text-[var(--store-primary)]" /> Endereço Padrão
                </h4>

                <div className="space-y-1">
                  <label className="text-[10px] font-black text-slate-400 uppercase">Rua / Avenida</label>
                  <input
                    type="text"
                    value={form.address}
                    onChange={e => updateForm('address', e.target.value)}
                    placeholder="Ex: Av. Beira Mar"
                    className="w-full h-10 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="text-[10px] font-black text-slate-400 uppercase">Número</label>
                    <input
                      type="text"
                      value={form.address_number}
                      onChange={e => updateForm('address_number', e.target.value)}
                      placeholder="Ex: 123 ou S/N"
                      className="w-full h-10 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-black text-slate-400 uppercase">Bairro</label>
                    <input
                      type="text"
                      value={form.district}
                      onChange={e => updateForm('district', e.target.value)}
                      placeholder="Ex: Centro"
                      className="w-full h-10 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-black text-slate-400 uppercase">Complemento</label>
                  <input
                    type="text"
                    value={form.address_complement}
                    onChange={e => updateForm('address_complement', e.target.value)}
                    placeholder="Ex: Apt 402, Bloco B / Próximo ao mercado"
                    className="w-full h-10 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                  />
                </div>
              </div>

              <button
                type="submit"
                disabled={loading}
                className="w-full h-11 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm uppercase rounded-xl flex items-center justify-center gap-2 transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {loading ? (
                  <Loader2 size={16} className="animate-spin" />
                ) : (
                  <Save size={16} />
                )}
                {loading ? 'Salvando...' : 'Salvar Alterações'}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
