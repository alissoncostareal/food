import React, { useEffect, useState } from 'react';
import { CheckCircle, CreditCard, Loader2, XCircle } from 'lucide-react';
import api from '../services/api';
import { onlyDigits } from '../utils/customerSession';

export default function CardPaymentPendingStep({
    order,
    payment,
    customerPhone,
    onPaid,
    onFailed
}) {
    const [status, setStatus] = useState(payment?.status || 'awaiting_payment');
    const [pollingError, setPollingError] = useState('');

    const orderId = order?.id;

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
                    setStatus(nextStatus);
                    onFailed?.(data);
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
    }, [orderId, customerPhone, status, onPaid, onFailed]);

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
                <div className="w-16 h-16 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto">
                    <XCircle size={28} />
                </div>
                <h3 className="text-lg font-black text-slate-900">Pagamento não aprovado</h3>
                <p className="text-sm font-semibold text-slate-500 max-w-sm mx-auto">
                    O cartão não foi aprovado. Feche e tente outra forma de pagamento.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-5 pb-2 text-center">
            <div className="w-16 h-16 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center mx-auto">
                <CreditCard size={28} />
            </div>
            <div>
                <h3 className="text-lg font-black text-slate-900">Processando pagamento</h3>
                <p className="text-sm font-semibold text-slate-500 mt-1">
                    Aguardando confirmação do emissor do cartão...
                </p>
            </div>
            <div className="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 flex items-center justify-center gap-3">
                <Loader2 className="animate-spin text-[var(--store-primary)] shrink-0" size={18} />
                <p className="text-sm font-semibold text-slate-600">Isso pode levar alguns segundos</p>
            </div>
            {pollingError && (
                <p className="text-xs font-semibold text-amber-600">{pollingError}</p>
            )}
        </div>
    );
}
