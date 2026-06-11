import React, { useState, useEffect, useRef } from 'react';
import { User, Smartphone, Save, Loader2 } from 'lucide-react';
import api from '../services/api';
import AddressSection from './AddressSection';
import SheetModal from './SheetModal';
import CustomerLoadingPanel from './CustomerLoadingPanel';
import { hasStreetNumber } from '../utils/streetAddress';
import {
  buildCustomerSession,
  fetchCustomerProfile,
  persistCustomerSession,
  readLocalCustomer,
  getProfileFromResponse,
  onlyDigits
} from '../utils/customerSession';
import { formatSavedAddressSummary } from '../utils/addressDisplay';

const emptyForm = {
  name: '',
  phone: '',
  address: '',
  district: '',
  city: '',
  address_complement: '',
  delivery_area_id: ''
};

export default function SettingsModal({ isOpen, onClose, onLoginRequired }) {
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(false);
  const [profileLoading, setProfileLoading] = useState(true);
  const [isEditingAddress, setIsEditingAddress] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });
  const addressSnapshotRef = useRef(null);

  const token = typeof window !== 'undefined' ? localStorage.getItem('token') : null;

  useEffect(() => {
    if (!isOpen) {
      setIsEditingAddress(false);
      addressSnapshotRef.current = null;
      return;
    }

    setProfileLoading(true);
    setMessage({ type: '', text: '' });
    setIsEditingAddress(false);
    addressSnapshotRef.current = null;

    if (!token) {
      const timer = setTimeout(() => {
        onLoginRequired?.();
      }, 10);

      return () => clearTimeout(timer);
    }

    fetchCustomerProfile(api)
      .then((customer) => {
        setForm(buildCustomerSession(customer));
      })
      .catch((err) => {
        if (err.response?.status === 401) {
          onLoginRequired?.();
          return;
        }

        setMessage({
          type: 'error',
          text: 'Não foi possível carregar seus dados salvos agora.'
        });
      })
      .finally(() => {
        setProfileLoading(false);
      });
  }, [isOpen, token, onLoginRequired]);

  if (!isOpen || !token) return null;

  const updateForm = (key, value) => {
    setForm(prev => ({ ...prev, [key]: value }));
    setMessage({ type: '', text: '' });
  };

  const handleAddressChange = (addressValues) => {
    setForm(prev => ({
      ...prev,
      address: addressValues.address ?? prev.address,
      address_complement: addressValues.address_complement ?? prev.address_complement,
      district: addressValues.district ?? prev.district,
      city: addressValues.city ?? prev.city
    }));
    setMessage({ type: '', text: '' });
  };

  const startEditingAddress = () => {
    addressSnapshotRef.current = {
      address: form.address,
      district: form.district,
      city: form.city || '',
      address_complement: form.address_complement
    };
    setIsEditingAddress(true);
  };

  const cancelEditingAddress = () => {
    if (addressSnapshotRef.current) {
      setForm(prev => ({
        ...prev,
        ...addressSnapshotRef.current
      }));
    }

    setIsEditingAddress(false);
    addressSnapshotRef.current = null;
    setMessage({ type: '', text: '' });
  };

  const savedAddressLines = formatSavedAddressSummary(form);

  const handleSave = async (e) => {
    e.preventDefault();
    setMessage({ type: '', text: '' });

    if (!form.name.trim()) {
      setMessage({ type: 'error', text: 'Informe seu nome.' });
      return;
    }

    if (onlyDigits(form.phone).length < 10) {
      setMessage({ type: 'error', text: 'Informe um WhatsApp válido.' });
      return;
    }

    if (!form.address.trim()) {
      setMessage({ type: 'error', text: 'Informe o endereço.' });
      return;
    }

    if (!hasStreetNumber(form.address)) {
      setMessage({ type: 'error', text: 'Informe o número da casa ou prédio.' });
      return;
    }

    if (!form.district.trim()) {
      setMessage({ type: 'error', text: 'Informe o bairro.' });
      return;
    }

    setLoading(true);

    try {
      if (!token) {
        onLoginRequired?.();
        return;
      }

      const { data } = await api.put('/customer/profile', {
        name: form.name,
        phone: form.phone,
        address: form.address,
        district: form.district,
        address_complement: form.address_complement
      }, {
        headers: { Authorization: `Bearer ${token}` }
      });

      const user = getProfileFromResponse(data);
      const savedForm = buildCustomerSession(user || form);

      persistCustomerSession(user || savedForm);
      setForm(savedForm);
      setIsEditingAddress(false);
      addressSnapshotRef.current = null;

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
    <SheetModal
      isOpen={isOpen}
      onClose={onClose}
      title="Meu endereço"
      subtitle="Seus dados padrão para pedidos"
      footer={(
        <button
          type="submit"
          form="settings-form"
          disabled={profileLoading || loading}
          className="w-full h-12 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm uppercase rounded-xl flex items-center justify-center gap-2 transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {loading ? (
            <Loader2 size={16} className="animate-spin text-white" />
          ) : (
            <Save size={16} />
          )}
          {profileLoading ? 'Carregando...' : loading ? 'Salvando...' : 'Salvar Alterações'}
        </button>
      )}
    >
      {profileLoading ? (
        <CustomerLoadingPanel message="Carregando seus dados..." />
      ) : (
        <form id="settings-form" onSubmit={handleSave} className="p-5 space-y-4 text-left flex-1">
          {message.text && (
            <div className={`px-3 py-2.5 rounded-xl text-xs font-bold border text-center ${
              message.type === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-[var(--store-primary)]/10 text-[var(--store-primary)] border-[var(--store-primary)]/20'
            }`}>
              {message.text}
            </div>
          )}

          <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
            <div className="flex items-center gap-2">
              <div className="h-8 w-8 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-500">
                <User size={16} />
              </div>
              <div>
                <h4 className="text-sm font-black text-slate-900">Dados pessoais</h4>
                <p className="text-xs text-slate-500">Nome e WhatsApp para contato</p>
              </div>
            </div>

            <div className="grid sm:grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <label className="text-[11px] font-bold text-slate-500">
                  Nome completo <span className="text-[var(--store-primary)]">*</span>
                </label>
                <div className="relative">
                  <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                  <input
                    type="text"
                    value={form.name}
                    onChange={e => updateForm('name', e.target.value)}
                    required
                    className="w-full h-11 pl-10 pr-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:bg-white focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10"
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="text-[11px] font-bold text-slate-500">
                  WhatsApp <span className="text-[var(--store-primary)]">*</span>
                </label>
                <div className="relative">
                  <Smartphone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                  <input
                    type="tel"
                    value={form.phone}
                    onChange={e => updateForm('phone', e.target.value)}
                    required
                    placeholder="Ex: 85999999999"
                    className="w-full h-11 pl-10 pr-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:bg-white focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10"
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 space-y-3">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h4 className="text-sm font-black text-slate-900">Endereço padrão</h4>
                <p className="text-xs font-medium text-slate-500 mt-0.5">
                  Usado para preencher pedidos com entrega
                </p>
              </div>
              {!isEditingAddress && (
                <button
                  type="button"
                  onClick={startEditingAddress}
                  className="shrink-0 text-xs font-bold text-[var(--store-primary)] hover:underline"
                >
                  Editar endereço
                </button>
              )}
            </div>

            {!isEditingAddress ? (
              savedAddressLines.length > 0 ? (
                <div className="rounded-xl border border-emerald-100 bg-emerald-50/60 px-3.5 py-3 space-y-1">
                  {savedAddressLines.map((line, index) => (
                    <p
                      key={`${line}-${index}`}
                      className={`${index === 0 ? 'text-sm font-bold text-slate-900' : 'text-xs font-semibold text-slate-600'}`}
                    >
                      {line}
                    </p>
                  ))}
                </div>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-200 bg-white px-3.5 py-4 text-center">
                  <p className="text-sm font-semibold text-slate-500">Nenhum endereço salvo ainda</p>
                  <button
                    type="button"
                    onClick={startEditingAddress}
                    className="mt-2 text-xs font-bold text-[var(--store-primary)] hover:underline"
                  >
                    Cadastrar endereço
                  </button>
                </div>
              )
            ) : (
              <div className="space-y-3">
                <AddressSection
                  values={form}
                  onChange={handleAddressChange}
                  showDeliverySummary={false}
                  showLocationButton
                  required
                  autoSearch={false}
                />
                <button
                  type="button"
                  onClick={cancelEditingAddress}
                  className="text-xs font-bold text-slate-500 hover:text-slate-700"
                >
                  Cancelar edição
                </button>
              </div>
            )}
          </div>
        </form>
      )}
    </SheetModal>
  );
}
