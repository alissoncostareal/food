import React, { useState } from 'react';
import {
  X,
  ArrowLeft,
  Loader2,
  Check,
  MessageCircle,
  ShieldCheck,
  MapPin,
  ReceiptText
} from 'lucide-react';
import api from '../services/api';
import { persistCustomerSession, getProfileFromResponse } from '../utils/customerSession';

const inputClass =
  'w-full h-12 px-4 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-900 outline-none focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10 transition-all placeholder:text-slate-400 disabled:opacity-60';

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

      await api.post('customers/send-code', { phone });
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
      if (data.access_token) {
        localStorage.setItem('token', data.access_token);
      }

      const user = getProfileFromResponse(data);

      if (user) {
        persistCustomerSession(user);
      }

      setSuccess(true);

      setTimeout(() => {
        onSuccess?.(user);
        handleClose();
      }, 1200);

    } catch (err) {
      setError(
        err.response?.data?.message ||
        'Código inválido ou expirado. Tente novamente.'
      );
    } finally {
      setLoading(false);
    }
  };

  const displayPhone = phone.trim() || 'seu WhatsApp';

  return (
    <div className="fixed inset-0 z-[999] flex items-end sm:items-center justify-center p-0 sm:p-4">
      <div className="absolute inset-0 bg-slate-950/45 backdrop-blur-sm" onClick={handleClose} />

      <div className="relative bg-white w-full max-w-md rounded-t-3xl sm:rounded-3xl overflow-hidden shadow-2xl flex flex-col max-h-[92dvh]">

        {!success && (
          <div className="shrink-0 px-5 pt-5 pb-4 border-b border-slate-100 bg-white">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-start gap-3 min-w-0">
                {step === 2 && (
                  <button
                    onClick={() => { setStep(1); setError(''); setCode(''); }}
                    className="mt-0.5 p-2 rounded-xl hover:bg-slate-100 text-slate-500 transition-colors shrink-0"
                    type="button"
                    aria-label="Voltar"
                  >
                    <ArrowLeft size={18} />
                  </button>
                )}

                <div className="min-w-0">
                  <div className="flex items-center gap-2 mb-2">
                    <span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full text-[10px] font-black ${
                      step === 1
                        ? 'bg-[var(--store-primary)] text-white'
                        : 'bg-slate-100 text-slate-500'
                    }`}>
                      1
                    </span>
                    <span className="h-px w-6 bg-slate-200" />
                    <span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full text-[10px] font-black ${
                      step === 2
                        ? 'bg-[var(--store-primary)] text-white'
                        : 'bg-slate-100 text-slate-400'
                    }`}>
                      2
                    </span>
                  </div>

                  <h2 className="font-black text-lg text-slate-900 leading-tight">
                    {step === 1 ? 'Entrar com WhatsApp' : 'Confirme o código'}
                  </h2>
                  <p className="text-xs font-semibold text-slate-500 mt-1">
                    {step === 1
                      ? 'Rápido, seguro e sem senha'
                      : `Enviamos um código para ${displayPhone}`}
                  </p>
                </div>
              </div>

              <button
                onClick={handleClose}
                className="p-2 rounded-xl text-slate-400 hover:bg-slate-100 transition-colors shrink-0"
                type="button"
                aria-label="Fechar"
              >
                <X size={20} />
              </button>
            </div>
          </div>
        )}

        <div className="flex-1 overflow-y-auto overscroll-contain bg-slate-50/60">

          {success ? (
            <div className="flex flex-col items-center justify-center py-12 px-6 text-center animate-in fade-in zoom-in duration-300 bg-white">
              <div className="w-16 h-16 bg-emerald-50 border border-emerald-100 rounded-full flex items-center justify-center text-emerald-500 mb-4 shadow-inner">
                <Check className="w-8 h-8 stroke-[3]" />
              </div>
              <h3 className="font-black text-xl text-slate-900">Login realizado com sucesso!</h3>
              <p className="text-sm font-semibold text-slate-500 mt-2 max-w-xs">
                Seus pedidos e endereço salvos já estão disponíveis.
              </p>
            </div>
          ) : (
            <div className="p-5 space-y-4">

              {step === 1 && (
                <div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 flex items-start gap-3">
                  <div className="h-10 w-10 rounded-xl bg-white border border-emerald-100 flex items-center justify-center text-emerald-600 shrink-0 shadow-sm">
                    <MessageCircle size={20} />
                  </div>
                  <div>
                    <p className="text-sm font-black text-slate-900">Acesso pelo WhatsApp</p>
                    <p className="text-xs font-medium text-slate-600 mt-1 leading-relaxed">
                      Informe o mesmo número que você usa nos pedidos. Enviaremos um código de verificação.
                    </p>
                  </div>
                </div>
              )}

              {error && (
                <div className="px-4 py-3 rounded-xl bg-[var(--store-primary)]/10 border border-[var(--store-primary)]/20 text-[var(--store-primary)] text-xs font-bold">
                  {error}
                </div>
              )}

              {step === 1 && (
                <form onSubmit={handleRequestOtp} className="space-y-4">
                  <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3 shadow-sm">
                    <label className="text-[11px] font-bold text-slate-500">
                      Número do WhatsApp
                    </label>

                    <div className="flex items-center gap-2">
                      <span className="inline-flex h-12 items-center px-3 rounded-xl bg-slate-50 border border-slate-200 text-sm font-black text-slate-600 shrink-0">
                        +55
                      </span>
                      <input
                        type="tel"
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        placeholder="85999999999"
                        inputMode="numeric"
                        autoComplete="tel"
                        className={inputClass}
                        required
                        disabled={loading}
                      />
                    </div>

                    <p className="text-[11px] font-medium text-slate-400">
                      DDD + número, apenas dígitos. Ex: 85999999999
                    </p>
                  </div>

                  <button
                    type="submit"
                    disabled={loading || !phone}
                    className="w-full h-12 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm rounded-xl flex items-center justify-center gap-2 transition-all shadow-md disabled:opacity-50"
                  >
                    {loading ? (
                      <>
                        <Loader2 className="animate-spin text-white" size={16} />
                        Enviando código...
                      </>
                    ) : (
                      <>
                        <MessageCircle size={16} />
                        Receber código no WhatsApp
                      </>
                    )}
                  </button>
                </form>
              )}

              {step === 2 && (
                <form onSubmit={handleVerifyOtp} className="space-y-4">
                  <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3 shadow-sm">
                    <label className="text-[11px] font-bold text-slate-500 block text-center">
                      Código de 6 dígitos
                    </label>

                    <input
                      type="text"
                      value={code}
                      onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                      placeholder="000000"
                      maxLength={6}
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      className={`${inputClass} text-center text-2xl tracking-[0.45em] font-black`}
                      required
                      disabled={loading}
                    />

                    <p className="text-[11px] font-medium text-slate-400 text-center">
                      Digite o código recebido no WhatsApp
                    </p>
                    <p className="text-[10px] font-semibold text-amber-700 text-center">
                      Ambiente de teste: use <strong>123456</strong>
                    </p>
                  </div>

                  <button
                    type="submit"
                    disabled={loading || code.length < 6}
                    className="w-full h-12 bg-[var(--store-primary)] hover:brightness-90 text-white font-black text-sm rounded-xl flex items-center justify-center gap-2 transition-all shadow-md disabled:opacity-50"
                  >
                    {loading ? (
                      <>
                        <Loader2 className="animate-spin text-white" size={16} />
                        Verificando...
                      </>
                    ) : (
                      'Confirmar e entrar'
                    )}
                  </button>
                </form>
              )}

              {step === 1 ? (
                <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
                  <p className="text-[10px] font-black text-slate-400 uppercase tracking-wider">
                    Com sua conta você pode
                  </p>
                  <div className="space-y-2.5">
                    <div className="flex items-center gap-2.5 text-xs font-semibold text-slate-600">
                      <MapPin size={15} className="text-[var(--store-primary)] shrink-0" />
                      Salvar endereço para próximos pedidos
                    </div>
                    <div className="flex items-center gap-2.5 text-xs font-semibold text-slate-600">
                      <ReceiptText size={15} className="text-[var(--store-primary)] shrink-0" />
                      Acompanhar histórico de pedidos
                    </div>
                    <div className="flex items-center gap-2.5 text-xs font-semibold text-slate-600">
                      <ShieldCheck size={15} className="text-[var(--store-primary)] shrink-0" />
                      Entrar de forma segura, sem senha
                    </div>
                  </div>
                </div>
              ) : (
                <div className="text-center space-y-2 px-2">
                  <p className="text-xs font-semibold text-slate-500">
                    Não recebeu? Volte e confira se o número está correto.
                  </p>
                  <button
                    type="button"
                    onClick={() => { setStep(1); setError(''); setCode(''); }}
                    className="text-xs font-bold text-[var(--store-primary)] hover:underline"
                  >
                    Alterar número do WhatsApp
                  </button>
                </div>
              )}

            </div>
          )}

        </div>
      </div>
    </div>
  );
}
