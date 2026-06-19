import React, { useEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { CheckCircle, Copy, Loader2, QrCode, Smartphone } from 'lucide-react';
import QRCode from 'react-qr-code';
import { fetchOrderPaymentStatus, isOrderPaymentPaid, startPaymentStatusPolling } from '../utils/paymentPolling';
import { subscribeToOrderPayment } from '../utils/orderPaymentRealtime';
import { resolveWhatsAppUrl } from '../utils/whatsapp';

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

const formatCountdown = (seconds) => {
    const safe = Math.max(0, seconds);
    const minutes = Math.floor(safe / 60);
    const secs = safe % 60;

    return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
};

const PIX_FALLBACK_TTL_MS = Number(import.meta.env.VITE_PAYMENTS_PIX_TTL_MS || 30 * 60 * 1000);
const MIN_PIX_TTL_MS = 25 * 60 * 1000;
const EXPIRY_GRACE_MS = 15_000;
const MANUAL_CHECK_COOLDOWN_MS = 3000;

const resolveExpiresAt = (raw) => {
    const fallback = new Date(Date.now() + PIX_FALLBACK_TTL_MS);

    if (!raw) {
        return fallback;
    }

    const parsed = new Date(raw);

    if (Number.isNaN(parsed.getTime()) || parsed.getTime() <= Date.now() + MIN_PIX_TTL_MS) {
        return fallback;
    }

    return parsed;
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
    store,
    onPaid,
    onComplete,
    onExpired
}) {
    const mountedAt = useRef(Date.now());
    const onExpiredRef = useRef(onExpired);
    const onPaidRef = useRef(onPaid);
    const onCompleteRef = useRef(onComplete);
    const settledRef = useRef(false);
    const lastManualCheckAtRef = useRef(0);
    const [localPaidData, setLocalPaidData] = useState(null);
    const [copied, setCopied] = useState(false);
    const [checkingPayment, setCheckingPayment] = useState(false);
    const [checkFeedback, setCheckFeedback] = useState('');
    const [expiresAt, setExpiresAt] = useState(() => resolveExpiresAt(payment?.expires_at));
    const [now, setNow] = useState(Date.now());

    const pixCode = payment?.pix?.qr_code || '';
    const pixImageUrl = useMemo(
        () => (isRenderablePixImageUrl(payment?.pix?.qr_code_url) ? payment.pix.qr_code_url : ''),
        [payment?.pix?.qr_code_url]
    );
    const amount = payment?.amount ?? order?.total_amount ?? 0;
    const orderId = order?.id;
    const phone = customerPhone || order?.customer_phone || '';

    const isPaid = Boolean(
        localPaidData
        || payment?.status === 'paid'
        || order?.payment_status === 'paid'
    );

    const secondsLeft = useMemo(() => {
        if (!expiresAt || isPaid) return null;
        return Math.floor((expiresAt.getTime() - now) / 1000);
    }, [expiresAt, now, isPaid]);

    useEffect(() => {
        onExpiredRef.current = onExpired;
        onPaidRef.current = onPaid;
        onCompleteRef.current = onComplete;
    }, [onExpired, onPaid, onComplete]);

    useEffect(() => {
        if (payment?.expires_at) {
            setExpiresAt(resolveExpiresAt(payment.expires_at));
        }
    }, [payment?.expires_at]);

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        if (secondsLeft === null || secondsLeft > 0) {
            return;
        }

        if (Date.now() - mountedAt.current < EXPIRY_GRACE_MS) {
            return;
        }

        onExpiredRef.current?.();
    }, [secondsLeft]);

    useEffect(() => {
        if (!orderId || settledRef.current || isPaid) {
            return undefined;
        }

        const handlePaid = (data) => {
            if (settledRef.current || !isOrderPaymentPaid(data)) {
                return;
            }

            settledRef.current = true;

            flushSync(() => {
                setLocalPaidData(data);
            });

            onPaidRef.current?.(data);
        };

        void fetchOrderPaymentStatus(orderId, phone)
            .then((data) => {
                if (isOrderPaymentPaid(data)) {
                    handlePaid(data);
                }
            })
            .catch(() => {});

        const stopRealtime = subscribeToOrderPayment({
            orderId,
            customerPhone: phone,
            onConfirmed: handlePaid,
        });

        const stopPolling = startPaymentStatusPolling({
            orderId,
            customerPhone: phone,
            isActive: () => !settledRef.current,
            onPaid: (data, meta) => {
                if (meta?.error || !data) {
                    return;
                }

                handlePaid(data);
            },
            onTerminal: (_data, status) => {
                if (status === 'expired' || status === 'failed') {
                    onExpiredRef.current?.();
                }
            },
        });

        return () => {
            stopPolling();
            stopRealtime();
        };
    }, [orderId, phone, isPaid]);

    useEffect(() => {
        if (!isPaid || localPaidData) {
            return;
        }

        if (payment?.status === 'paid' || order?.payment_status === 'paid') {
            setLocalPaidData({
                order,
                payment: payment || { status: 'paid' },
                whatsapp_url: order?.whatsapp_url,
            });
        }
    }, [isPaid, localPaidData, order, payment]);

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

    const handleManualPaymentCheck = async () => {
        if (!orderId || settledRef.current || isPaid || checkingPayment) {
            return;
        }

        const nowMs = Date.now();
        const elapsed = nowMs - lastManualCheckAtRef.current;

        if (elapsed < MANUAL_CHECK_COOLDOWN_MS) {
            const secondsLeft = Math.ceil((MANUAL_CHECK_COOLDOWN_MS - elapsed) / 1000);
            setCheckFeedback(`Aguarde ${secondsLeft}s para verificar novamente.`);
            return;
        }

        lastManualCheckAtRef.current = nowMs;
        setCheckingPayment(true);
        setCheckFeedback('');

        try {
            const data = await fetchOrderPaymentStatus(orderId, phone);

            if (settledRef.current) {
                return;
            }

            if (isOrderPaymentPaid(data)) {
                settledRef.current = true;

                flushSync(() => {
                    setLocalPaidData(data);
                });

                onPaidRef.current?.(data);
                return;
            }

            const status = data?.payment?.status || data?.order?.payment_status;

            if (status === 'expired' || status === 'failed') {
                onExpiredRef.current?.();
                return;
            }

            setCheckFeedback(
                'Ainda não identificamos o pagamento. Pode levar alguns segundos — tente de novo em instantes.'
            );
        } catch {
            setCheckFeedback(
                'Não foi possível verificar agora. Confira sua conexão e tente novamente.'
            );
        } finally {
            setCheckingPayment(false);
        }
    };

    if (isPaid) {
        const paidOrder = {
            ...(localPaidData?.order || order),
            payment_status: 'paid',
            whatsapp_url: localPaidData?.whatsapp_url
                || localPaidData?.order?.whatsapp_url
                || order?.whatsapp_url
                || null,
        };
        const whatsappUrl = resolveWhatsAppUrl(
            {
                whatsapp_url: paidOrder.whatsapp_url,
                store_whatsapp_number: paidOrder.store_whatsapp_number,
            },
            paidOrder,
            store
        );

        return (
            <div className="text-center py-6 space-y-5 flex flex-col items-center">
                <div className="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center animate-bounce mx-auto">
                    <CheckCircle size={32} />
                </div>

                <div className="space-y-2">
                    <h3 className="text-xl font-black text-slate-900">Pagamento Pix confirmado!</h3>
                    <p className="text-sm font-semibold text-slate-500 max-w-sm mx-auto leading-relaxed">
                        {whatsappUrl
                            ? 'Recebemos seu pagamento. Toque abaixo para enviar os detalhes no WhatsApp da loja.'
                            : 'Recebemos seu pagamento. Seu pedido já foi enviado para a loja.'}
                    </p>
                </div>

                {whatsappUrl ? (
                    <a
                        href={whatsappUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="w-full h-14 bg-emerald-600 text-white rounded-xl font-black text-base flex items-center justify-center gap-2 hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-100"
                    >
                        <Smartphone size="18" />
                        Enviar no WhatsApp da loja
                    </a>
                ) : (
                    <p className="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3">
                        Peça para a loja cadastrar o WhatsApp em Loja.
                    </p>
                )}

                <button
                    type="button"
                    onClick={() => onCompleteRef.current?.()}
                    className="w-full h-12 rounded-xl border border-slate-200 text-slate-600 font-black text-sm hover:bg-slate-50 transition-all"
                >
                    Voltar ao cardápio
                </button>
            </div>
        );
    }

    if (payment?.status === 'expired' || payment?.status === 'failed') {
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
                    Escaneie o QR Code com o app do banco no celular ou copie o código Pix abaixo.
                </p>
                <p className="mt-2 text-[11px] font-semibold text-amber-600 text-center">
                    Mantenha esta página aberta no computador até a confirmação aparecer aqui.
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
                    Aguardando confirmação do pagamento nesta tela...
                </p>
            </div>

            <button
                type="button"
                onClick={handleManualPaymentCheck}
                disabled={checkingPayment}
                className="w-full h-12 rounded-xl bg-[var(--store-primary)] text-white text-sm font-black flex items-center justify-center gap-2 hover:opacity-95 transition-all disabled:opacity-60"
            >
                {checkingPayment ? (
                    <>
                        <Loader2 className="animate-spin" size={16} />
                        Verificando pagamento...
                    </>
                ) : (
                    'Já paguei — verificar agora'
                )}
            </button>

            {checkFeedback && (
                <p className="text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 text-center">
                    {checkFeedback}
                </p>
            )}
        </div>
    );
}
