// src/components/LoginModal.jsx
import React, { useState } from 'react';
import { X, Phone, ArrowLeft, Loader2, MessageSquareCode, Check } from 'lucide-react';
import api from '../services/api';

export default function LoginModal({ isOpen, onClose, onSuccess }) {
  const [step, setStep] = useState(1);
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');

  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState('');

  if (!isOpen) return null;

  const handleClose = () => {
    setStep(1);
    setPhone('');
    setCode('');
    setError('');
    setSuccess(false);
    onClose();
  };

  const handleRequestOtp = async (e) => {
    e.preventDefault();
    try {
      setLoading(true);
      setError('');

      await api.post('/customers/send-code', { phone });
      setStep(2);
    } catch (err) {
      setError(
        err.response?.data?.message || 
        'Número não encontrado ou erro ao processar o envio.'
      );
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyOtp = async (e) => {
    e.preventDefault();
    try {
      setLoading(true);
      setError('');

      const { data } = await api.post('/customers/verify-code', {
        phone,
        code
      });

      localStorage.setItem('token', data.access_token);
      localStorage.setItem('user', JSON.stringify(data.user));

      window.dispatchEvent(new Event('customer-session-updated'));

      setSuccess(true);
      
      setTimeout(() => {
        onSuccess?.(data.user);
        handleClose();
      }, 1500);

    } catch (err) {
      setError(
        err.response?.data?.message || 
        'Código inválido ou expirado. Tente novamente.'
      );
    } finally {
      setLoading(false);
    }
  };


  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={handleClose} />

      <div className="relative bg-white w-full max-w-md rounded-3xl overflow-hidden shadow-2xl transition-all">
        
        {!success && (
          <div className="p-5 border-b border-gray-100 flex items-center justify-between">
            <div className="flex items-center gap-2">
              {step === 2 && (
                <button 
                  onClick={() => { setStep(1); setError(''); setCode(''); }}
                  className="p-1 rounded-lg hover:bg-slate-100 text-slate-500 mr-1 transition-colors"
                  type="button"
                >
                  <ArrowLeft size={18} />
                </button>
              )}
              <div>
                <h2 className="font-black text-xl text-slate-900">
                  {step === 1 ? 'Acessar Conta' : 'Confirme seu número'}
                </h2>
                <p className="text-xs font-semibold text-slate-400 mt-0.5">
                  {step === 1 ? 'Entre usando seu WhatsApp' : 'Insira o código de acesso'}
                </p>
              </div>
            </div>

            <button onClick={handleClose} className="p-2 rounded-xl text-slate-400 hover:bg-slate-100 transition-colors">
              <X className="w-5 h-5" />
            </button>
          </div>
        )}

        <div className="p-5">
          
          {success ? (
            <div className="flex flex-col items-center justify-center py-8 text-center animate-in fade-in zoom-in duration-300">
              <div className="w-16 h-16 bg-green-50 border border-green-100 rounded-full flex items-center justify-center text-green-500 mb-4 shadow-inner">
                <Check className="w-8 h-8 stroke-[3]" />
              </div>
              <h3 className="font-black text-xl text-slate-900">Acesso Autorizado!</h3>
              <p className="text-xs font-bold text-slate-500 mt-1">Carregando suas informações de entrega...</p>
              <div className="mt-6 flex items-center gap-2 bg-slate-50 px-4 py-2 rounded-full text-[11px] font-bold text-slate-500 border border-slate-100">
                <Loader2 className="w-3.5 h-3.5 animate-spin text-red-600" />
                Sincronizando painel...
              </div>
            </div>
          ) : (
            <>
              {error && (
                <div className="mb-4 px-4 py-2.5 rounded-xl bg-red-50 border border-red-100 text-red-600 text-xs font-bold">
                  {error}
                </div>
              )}

              {step === 1 && (
                <form onSubmit={handleRequestOtp} className="space-y-4">
                  <div className="space-y-1">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-wider">
                      Número do WhatsApp
                    </label>
                    <div className="relative">
                      <Phone className="absolute left-3 top-3.5 w-4 h-4 text-slate-400" />
                      <input
                        type="tel"
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        placeholder="Ex: 85999999999"
                        className="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 transition-all"
                        required
                        disabled={loading}
                      />
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={loading || !phone}
                    className="w-full h-12 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm uppercase rounded-xl flex items-center justify-center gap-2 transition-all shadow-md disabled:opacity-50"
                  >
                    {loading ? <Loader2 className="animate-spin" size={16} /> : 'Receber Código'}
                  </button>
                </form>
              )}

              {step === 2 && (
                <form onSubmit={handleVerifyOtp} className="space-y-4">
                  <div className="space-y-1">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-wider block text-center">
                      Código de Verificação
                    </label>
                    <div className="relative">
                      <MessageSquareCode className="absolute left-4 top-3.5 w-4 h-4 text-slate-400" />
                      <input
                        type="text"
                        value={code}
                        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                        placeholder="000000"
                        maxLength={6}
                        className="w-full tracking-[0.6em] text-center pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-base font-black outline-none focus:bg-white focus:border-slate-900 transition-all"
                        required
                        disabled={loading}
                      />
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={loading || code.length < 6}
                    className="w-full h-12 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm uppercase rounded-xl flex items-center justify-center gap-2 transition-all shadow-md disabled:opacity-50"
                  >
                    {loading ? <Loader2 className="animate-spin" size={16} /> : 'Confirmar e Entrar'}
                  </button>
                </form>
              )}

              <div className="mt-6 text-center text-[11px] font-semibold text-slate-400 border-t border-slate-50 pt-4 leading-relaxed">
                {step === 1 ? (
                  <>Digite seu número cadastrado para recuperar seus endereços salvos.</>
                ) : (
                  <>Insira os 6 dígitos recebidos ou use o código mestre de testes.</>
                )}
              </div>
            </>
          )}

        </div>
      </div>
    </div>
  );
}