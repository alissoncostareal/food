import React, { useEffect, useMemo, useRef, useState } from 'react';
import { CheckCircle, Copy, Loader2, QrCode } from 'lucide-react';
import QRCode from 'react-qr-code';
import api from '../services/api';
import { onlyDigits } from '../utils/customerSession';

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

const formatCountdown = (seconds) => {
    const safe = Math.max(0, seconds);
    const minutes = Math.floor(safe / 60);
    const secs = safe % 60;

    return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
};

const PIX_FALLBACK_TTL_MS = Number(import.meta.env.VITE_PAYMENTS_PIX_TTL_MS || 30 * 60 * 1000);
const EXPIRY_GRACE_MS = 15_000;

const resolveExpiresAt = (raw) => {
    if (raw) {
        const parsed = new Date(raw);

        if (!Number.isNaN(parsed.getTime()) && parsed.getTime() > Date.now() + 15_000) {
            return parsed;
        }
    }

    return new Date(Date.now() + PIX_FALLBACK_TTL_MS);
};

const isRenderablePixImageUrl = (url) => {
    if (!url || typeof url !== 'string') return false;

    if (url.startsWith('data:image/')) return true;

    if (/mercadopago\.com/i.test(url)) return false;

    return /\.(png|jpe?g|webp|gif)(\?|$)/i.test(url);
};

export default function PixPaymentStep({
    order,
    payment,
    customerPhone,
    onPaid,
    onExpired
}) {
    const mountedAt = useRef(Date.now());
    const [status, setStatus] = useState(payment?.status || 'awaiting_payment');
    const [copied, setCopied] = useState(false);
    const [pollingError, setPollingError] = useState('');
    const [expiresAt] = useState(() => resolveExpiresAt(payment?.expires_at));
    const [now, setNow] = useState(Date.now());

    const orderId = order?.id;
    const pixCode = payment?.pix?.qr_code || '';
    const pixImageUrl = useMemo(
        () => (isRenderablePixImageUrl(payment?.pix?.qr_code_url) ? payment.pix.qr_code_url : ''),
        [payment?.pix?.qr_code_url]
    );
    const amount = payment?.amount ?? order?.total_amount ?? 0;

    const secondsLeft = useMemo(() => {
        if (!expiresAt) return null;
        return Math.floor((expiresAt.getTime() - now) / 1000);
    }, [expiresAt, now]);

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        if (secondsLeft === null || secondsLeft > 0 || status !== 'awaiting_payment') {
            return;
        }

        if (Date.now() - mountedAt.current < EXPIRY_GRACE_MS) {
            return;
        }

        setStatus('expired');
        onExpired?.();
    }, [secondsLeft, status, onExpired]);

    useEffect(() => {
        if (!orderId || status !== 'awaiting_payment') return undefined;

        const intervalMs = Number(import.meta.env.VITE_PAYMENTS_POLLING_MS || 3000);
        let active = true;

        const poll = async () => {
            try {
                const { data } = await api.get(`/checkout/orders/${orderId}/payment`, {
                    params: { phone: onlyDigits(customerPhone) }
                });

                if (!active) return;

                const nextStatus = data?.payment?.status;

                if (nextStatus === 'paid') {
                    setStatus('paid');
                    onPaid?.(data);
                    return;
                }

                if (nextStatus === 'expired' || nextStatus === 'failed') {
                    if (Date.now() - mountedAt.current < EXPIRY_GRACE_MS) {
                        return;
                    }

                    setStatus(nextStatus);
                    onExpired?.(data);
                    return;
                }

                setPollingError('');
            } catch {
                if (active) {
                    setPollingError('Não foi possível verificar o pagamento. Tentando novamente...');
                }
            }
        };

        poll();
        const timer = setInterval(poll, intervalMs);

        return () => {
            active = false;
            clearInterval(timer);
        };
    }, [orderId, customerPhone, status, onPaid, onExpired]);

    const copyPixCode = async () => {
        if (!pixCode) return;

        try {
            await navigator.clipboard.writeText(pixCode);
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        } catch {
            setCopied(false);
        }
    };

    if (status === 'paid') {
        return (
            <div className="text-center py-6 space-y-4">
                <div className="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto">
                    <CheckCircle size={32} />
                </div>
                <div>
                    <h3 className="text-xl font-black text-slate-900">Pagamento confirmado!</h3>
                    <p className="text-sm font-semibold text-slate-500 mt-1">
                        Pedido #{order?.display_code || order?.id} enviado para a loja.
                    </p>
                </div>
            </div>
        );
    }

    if (status === 'expired' || status === 'failed') {
        return (
            <div className="text-center py-6 space-y-3">
                <div className="w-16 h-16 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mx-auto">
                    <QrCode size={28} />
                </div>
                <h3 className="text-lg font-black text-slate-900">Pix expirado</h3>
                <p className="text-sm font-semibold text-slate-500 max-w-sm mx-auto">
                    O prazo para pagamento acabou. Feche e tente finalizar o pedido novamente.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-5 pb-2">
            <div className="text-center space-y-1">
                <p className="text-xs font-black uppercase tracking-wider text-slate-400">Pague para confirmar</p>
                <p className="text-2xl font-black text-slate-900">{formatCurrency(amount)}</p>
                {secondsLeft !== null && (
                    <p className="text-xs font-bold text-amber-600">
                        Expira em {formatCountdown(secondsLeft)}
                    </p>
                )}
            </div>

            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 flex flex-col items-center">
                {pixCode ? (
                    <div className="w-64 h-64 rounded-xl bg-white p-4 flex items-center justify-center">
                        {pixImageUrl ? (
                            <img
                                src={pixImageUrl}
                                alt="QR Code Pix"
                                className="h-full w-full object-contain"
                            />
                        ) : (
                            <QRCode
                                value={pixCode}
                                size={224}
                                bgColor="#ffffff"
                                fgColor="#0f172a"
                                level="L"
                            />
                        )}
                    </div>
                ) : (
                    <div className="w-52 h-52 rounded-xl bg-white border border-dashed border-slate-200 flex items-center justify-center text-slate-400">
                        <Loader2 className="animate-spin" size={28} />
                    </div>
                )}
                <p className="mt-3 text-xs font-semibold text-slate-500 text-center">
                    Abra o app do seu banco e escaneie o QR Code ou copie o código Pix.
                </p>
            </div>

            {pixCode && (
                <button
                    type="button"
                    onClick={copyPixCode}
                    className="w-full h-12 rounded-xl border border-slate-200 bg-white text-sm font-black text-slate-700 flex items-center justify-center gap-2 hover:bg-slate-50"
                >
                    <Copy size={16} />
                    {copied ? 'Código copiado!' : 'Copiar código Pix'}
                </button>
            )}

            <div className="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 flex items-center gap-3">
                <Loader2 className="animate-spin text-[var(--store-primary)] shrink-0" size={18} />
                <p className="text-sm font-semibold text-slate-600">
                    Aguardando confirmação do pagamento...
                </p>
            </div>

            {pollingError && (
                <p className="text-xs font-semibold text-amber-600 text-center">{pollingError}</p>
            )}
        </div>
    );
}
