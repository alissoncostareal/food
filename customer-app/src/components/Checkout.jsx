// src/components/Checkout.jsx
// PROTEGIDO: fluxo WhatsApp pós-pedido — ver .cursor/rules/customer-app-whatsapp-checkout.mdc
import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    X,
    Loader2,
    Home,
    Store,
    CreditCard,
    Banknote,
    Smartphone,
    CheckCircle,
    User,
    Ticket,
    Sparkles,
    Zap
} from 'lucide-react';
import api from '../services/api';
import AddressSection from './AddressSection';
import CustomerLoadingPanel from './CustomerLoadingPanel';
import PixPaymentStep from './PixPaymentStep';
import CardPaymentPendingStep from './CardPaymentPendingStep';
import {
    onlyDigits,
    readLocalCustomer,
    fetchCustomerProfile,
    lookupCustomerByPhone,
    persistCheckoutCustomerSession,
    normalizeBrazilPhone
} from '../utils/customerSession';
import { matchDeliveryArea } from '../utils/deliveryAreaMatch';
import { openWhatsAppUrl, resolveWhatsAppUrl } from '../utils/whatsapp';
import { getApiErrorMessage } from '../utils/apiError';
import { hasStreetNumber } from '../utils/streetAddress';

const formatCurrency = (value) => {
    return Number(value || 0).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    });
};

const STEPS = [
    { id: 1, label: 'Entrega' },
    { id: 2, label: 'Pagamento' },
    { id: 3, label: 'Confirmação' }
];

export default function Checkout({
    isOpen,
    onClose,
    store,
    cart,
    subtotal,
    appliedCoupon,
    discountAmount = 0,
    coupon = '',
    setCoupon,
    couponLoading = false,
    couponError = '',
    onApplyCoupon,
    onRemoveCoupon,
    couponsEnabled = true,
    onSuccess
}) {
    const [step, setStep] = useState(1);
    const [loading, setLoading] = useState(false);
    const [profileLoading, setProfileLoading] = useState(false);
    const [error, setError] = useState('');
    const [orderResult, setOrderResult] = useState(null);
    const [paymentInfo, setPaymentInfo] = useState(null);

    const [card, setCard] = useState({
        holder_name: '',
        holder_document: '',
        number: '',
        exp_month: '',
        exp_year: '',
        cvv: ''
    });

    const phoneLookupRef = useRef(null);
    const checkoutOpenedRef = useRef(false);

    const [form, setForm] = useState(() => {
        const customer = readLocalCustomer();

        return {
            fulfillment_type: 'delivery',
            customer_name: customer.customer_name,
            customer_phone: customer.customer_phone,
            address: customer.address,
            address_complement: customer.address_complement,
            district: customer.district,
            city: customer.city || '',
            delivery_area_id: '',
            latitude: '',
            longitude: '',
            payment_method: 'pix',
            needs_change: false,
            change_for: '',
            observation: ''
        };
    });

    const [deliveryAreas, setDeliveryAreas] = useState([]);
    const [deliveryAreasLoading, setDeliveryAreasLoading] = useState(false);

    const isOnlinePaymentMethod = (method) => ['pix_online', 'credit_card_online'].includes(method);

    const awaitingOnlinePayment = Boolean(
        orderResult
        && isOnlinePaymentMethod(form.payment_method)
        && paymentInfo?.status === 'awaiting_payment'
    );

    const showOrderConfirmation = Boolean(
        step === 3
        && orderResult
        && !awaitingOnlinePayment
    );

    const confirmedWhatsAppUrl = useMemo(() => {
        if (!orderResult) {
            return null;
        }

        return resolveWhatsAppUrl(
            {
                whatsapp_url: orderResult.whatsapp_url,
                store_whatsapp_number: orderResult.store_whatsapp_number
            },
            orderResult,
            store
        );
    }, [orderResult, store]);

    const selectedDeliveryArea = deliveryAreas.find(area => String(area.id) === String(form.delivery_area_id));

    const deliveryFee = form.fulfillment_type === 'delivery'
        ? Number(selectedDeliveryArea?.fee ?? store?.delivery_fee ?? 0)
        : 0;

    const offlinePaymentOptions = useMemo(() => ([
        ['pix', 'Pix na entrega', Smartphone],
        ['cash', 'Dinheiro', Banknote],
        ['debit_card', 'Débito', CreditCard],
        ['credit_card', 'Crédito', CreditCard]
    ]), []);

    const onlinePaymentOptions = useMemo(() => {
        const options = [['pix_online', 'Pix online', Zap]];

        if (store?.online_card_available) {
            options.push(['credit_card_online', 'Cartão online', CreditCard]);
        }

        return options;
    }, [store?.online_card_available]);

    const availableOfflineMethods = useMemo(() => {
        const raw = store?.accepted_payment_methods || store?.payment_methods || [];
        const methods = Array.isArray(raw) && raw.length > 0
            ? raw
            : ['pix', 'cash', 'debit_card', 'credit_card'];

        return offlinePaymentOptions.filter(([value]) => methods.includes(value));
    }, [store, offlinePaymentOptions]);

    const availableOnlineMethods = useMemo(() => {
        if (!store?.online_payments_enabled) return [];

        const raw = store?.accepted_payment_methods || store?.payment_methods || [];

        return onlinePaymentOptions.filter(([value]) => raw.includes(value));
    }, [store, onlinePaymentOptions]);

    const hasPaymentMethods = availableOfflineMethods.length > 0 || availableOnlineMethods.length > 0;

    const discount = Number(discountAmount || appliedCoupon?.discount_amount || 0);
    const total = Math.max(0, Number(subtotal || 0) + deliveryFee - discount);

    const applyCustomerToForm = (customer) => {
        if (!customer) return;

        setForm(prev => ({
            ...prev,
            customer_name: customer.customer_name || customer.name || prev.customer_name,
            customer_phone: customer.customer_phone || customer.phone || prev.customer_phone,
            address: customer.address || prev.address,
            address_complement: customer.address_complement || prev.address_complement,
            district: customer.district || prev.district,
            city: customer.city || prev.city || '',
            delivery_area_id: '',
            latitude: '',
            longitude: ''
        }));
    };

    useEffect(() => {
        if (!isOpen) {
            checkoutOpenedRef.current = false;
            return;
        }

        if (checkoutOpenedRef.current) {
            return;
        }

        checkoutOpenedRef.current = true;

        setStep(1);
        setError('');
        setOrderResult(null);
        setPaymentInfo(null);
        setCard({
            holder_name: '',
            holder_document: '',
            number: '',
            exp_month: '',
            exp_year: '',
            cvv: ''
        });

        const methods = store?.accepted_payment_methods || store?.payment_methods || [];
        const defaultPayment = (store?.online_payments_enabled && methods.includes('pix_online'))
            ? 'pix_online'
            : (store?.online_payments_enabled && store?.online_card_available && methods.includes('credit_card_online'))
                ? 'credit_card_online'
                : (methods[0] || 'pix');

        setForm(prev => ({
            ...prev,
            payment_method: defaultPayment,
            needs_change: false,
            change_for: ''
        }));

        setProfileLoading(true);

        fetchCustomerProfile(api)
            .then(applyCustomerToForm)
            .catch(() => {
                applyCustomerToForm(readLocalCustomer());
            })
            .finally(() => {
                setProfileLoading(false);
            });
    }, [isOpen, store?.id]);

    useEffect(() => {
        if (!isOpen || !store?.slug) return;

        setDeliveryAreasLoading(true);

        api.get(`/stores/${store.slug}/delivery-areas`)
            .then(({ data }) => {
                const areas = data.data || data || [];
                setDeliveryAreas(areas);

                if (areas.length === 0) {
                    setForm(prev => ({ ...prev, delivery_area_id: '' }));
                }
            })
            .catch(() => {
                setDeliveryAreas([]);
            })
            .finally(() => {
                setDeliveryAreasLoading(false);
            });
    }, [isOpen, store?.slug]);

    const updateForm = (key, value) => {
        setForm(prev => ({ ...prev, [key]: value }));
        setError('');
    };

    const handlePhoneBlur = () => {
        const digits = onlyDigits(form.customer_phone);

        if (digits.length < 10 || localStorage.getItem('token')) {
            return;
        }

        clearTimeout(phoneLookupRef.current);
        phoneLookupRef.current = setTimeout(async () => {
            const customer = await lookupCustomerByPhone(api, digits);

            if (customer) {
                applyCustomerToForm(customer);
            }
        }, 400);
    };

    const handleAddressChange = (addressValues) => {
        setForm(prev => ({
            ...prev,
            address: addressValues.address ?? prev.address,
            address_complement: addressValues.address_complement ?? prev.address_complement,
            district: addressValues.district ?? prev.district,
            city: addressValues.city ?? prev.city,
            delivery_area_id: addressValues.delivery_area_id ?? prev.delivery_area_id,
            latitude: addressValues.latitude ?? prev.latitude,
            longitude: addressValues.longitude ?? prev.longitude
        }));
        setError('');
    };

    const validateStep = (stepToValidate = step) => {
        if (stepToValidate === 1) {
            if (!cart || cart.length === 0) {
                setError('Sua sacola está vazia.');
                return false;
            }

            if (!store?.id) {
                setError('Loja não encontrada.');
                return false;
            }

            if (!form.customer_name.trim()) {
                setError('Informe seu nome.');
                return false;
            }

            if (onlyDigits(form.customer_phone).length < 10) {
                setError('Informe um WhatsApp válido.');
                return false;
            }

            if (form.fulfillment_type === 'delivery') {
                let deliveryAreaId = form.delivery_area_id;

                if (deliveryAreas.length > 0 && !deliveryAreaId) {
                    const matchedArea = matchDeliveryArea(deliveryAreas, form.district, form.city);

                    if (matchedArea) {
                        deliveryAreaId = String(matchedArea.id);
                        setForm((prev) => ({
                            ...prev,
                            delivery_area_id: deliveryAreaId,
                            district: matchedArea.district_name || prev.district,
                            city: matchedArea.city || prev.city
                        }));
                    }
                }

                if (deliveryAreas.length > 0 && !deliveryAreaId) {
                    setError('Não entregamos neste endereço. Escolha um endereço em uma das regiões atendidas pela loja.');
                    return false;
                }

                if (!form.address.trim()) {
                    setError('Informe o endereço de entrega.');
                    return false;
                }

                if (!hasStreetNumber(form.address)) {
                    setError('Informe o número da casa ou prédio.');
                    return false;
                }
            }
        }

        if (stepToValidate === 2) {
            if (!hasPaymentMethods) {
                setError('Esta loja ainda não configurou formas de pagamento.');
                return false;
            }

            if (!form.payment_method) {
                setError('Escolha uma forma de pagamento.');
                return false;
            }

            if (form.payment_method === 'cash' && form.needs_change && !form.change_for) {
                setError('Informe para quanto precisa de troco.');
                return false;
            }

            if (form.payment_method === 'cash' && form.needs_change && Number(form.change_for) <= total) {
                setError('O valor para troco precisa ser maior que o total.');
                return false;
            }

            if (form.payment_method === 'credit_card_online') {
                if (!resolveCardHolderName()) {
                    setError('Informe o nome impresso no cartão.');
                    return false;
                }

                if (onlyDigits(card.holder_document).length < 11) {
                    setError('Informe um CPF válido.');
                    return false;
                }

                if (onlyDigits(card.number).length < 13) {
                    setError('Informe o número do cartão.');
                    return false;
                }

                if (!card.exp_month || !card.exp_year) {
                    setError('Informe a validade do cartão.');
                    return false;
                }

                if (onlyDigits(card.cvv).length < 3) {
                    setError('Informe o CVV do cartão.');
                    return false;
                }
            }
        }

        setError('');
        return true;
    };

    const nextStep = () => {
        if (!validateStep()) return;
        setStep(current => Math.min(current + 1, 3));
    };

    const prevStep = () => {
        setError('');
        setStep(current => Math.max(current - 1, 1));
    };

    const finalizeOrderSuccess = (data, order) => {
        const whatsappUrl = resolveWhatsAppUrl(data, order, store);

        const orderWithUrl = {
            ...order,
            whatsapp_url: whatsappUrl,
            store_whatsapp_number: data?.store_whatsapp_number || store?.whatsapp_number || order?.store?.whatsapp_number || null,
            store: order?.store || store
        };

        if (data?.payment?.status === 'paid' || orderWithUrl?.payment_status === 'paid') {
            setPaymentInfo((current) => ({
                ...(current || {}),
                ...(data?.payment || {}),
                status: 'paid',
            }));
        }

        setOrderResult(orderWithUrl);
        setStep(3);
        setLoading(false);

        if (typeof onSuccess === 'function') {
            onSuccess({
                ...data,
                order: orderWithUrl,
                whatsapp_url: whatsappUrl,
                keep_checkout_open: true
            });
        }

        if (whatsappUrl) {
            openWhatsAppUrl(whatsappUrl);
        }
    };

    const handleStepAction = () => {
        if (step === 2) {
            void submitOrder();
            return;
        }

        nextStep();
    };

    const updateCard = (field, value) => {
        setCard((current) => ({ ...current, [field]: value }));
    };

    const resolveCardHolderName = () => (
        card.holder_name.trim() || form.customer_name.trim()
    );

    useEffect(() => {
        if (form.payment_method !== 'credit_card_online') {
            return;
        }

        if (card.holder_name.trim() || !form.customer_name.trim()) {
            return;
        }

        setCard((current) => ({
            ...current,
            holder_name: form.customer_name.trim(),
        }));
    }, [form.payment_method, form.customer_name, card.holder_name]);

    const submitOrder = async () => {
        if (!validateStep(1)) {
            setStep(1);
            return;
        }

        if (!validateStep(2)) {
            return;
        }

        try {
            setLoading(true);
            setError('');

            let cardToken = null;

            if (form.payment_method === 'credit_card_online') {
                const holderName = resolveCardHolderName();

                const { data: tokenData } = await api.post('/checkout/card-token', {
                    store_id: store.id,
                    holder_name: holderName,
                    holder_document: onlyDigits(card.holder_document),
                    number: onlyDigits(card.number),
                    exp_month: Number(card.exp_month),
                    exp_year: Number(card.exp_year),
                    cvv: onlyDigits(card.cvv)
                });

                cardToken = tokenData?.token;

                if (!cardToken) {
                    throw new Error('Não foi possível validar o cartão.');
                }
            }

            const payload = {
                store_id: store.id,
                fulfillment_type: form.fulfillment_type,
                customer_name: form.customer_name,
                customer_phone: normalizeBrazilPhone(form.customer_phone),
                delivery_area_id: form.fulfillment_type === 'delivery' && form.delivery_area_id ? Number(form.delivery_area_id) : null,
                address: form.fulfillment_type === 'delivery' ? form.address : null,
                address_complement: form.fulfillment_type === 'delivery' ? form.address_complement : null,
                district: form.fulfillment_type === 'delivery'
                    ? (form.district || selectedDeliveryArea?.district_name || null)
                    : null,
                city: form.fulfillment_type === 'delivery' ? form.city || null : null,
                latitude: form.fulfillment_type === 'delivery' && form.latitude ? form.latitude : null,
                longitude: form.fulfillment_type === 'delivery' && form.longitude ? form.longitude : null,
                payment_method: form.payment_method,
                card_token: cardToken,
                installments: 1,
                change_for: form.payment_method === 'cash' && form.needs_change && form.change_for ? Number(form.change_for) : null,
                coupon_id: appliedCoupon?.id || null,
                coupon_code: appliedCoupon?.code || null,
                type: 'sale',
                observation: form.observation || null,
                items: cart.map(item => ({
                    product_id: item.id,
                    quantity: item.quantity,
                    observation: item.observation || null,
                    options: (item.selected_options || []).map(option => ({
                        name: option.name,
                        group_name: option.group_name || option.group?.name || 'Adicional',
                        additional_price: Number(option.price || option.additional_price || 0)
                    }))
                }))
            };

            const { data } = await api.post('/checkout/orders', payload);

            persistCheckoutCustomerSession(form, data);

            const order = {
                ...(data.order || data),
                whatsapp_url: data.whatsapp_url || data.order?.whatsapp_url || null,
                store_whatsapp_number: data.store_whatsapp_number || store?.whatsapp_number || data.order?.store?.whatsapp_number || null,
                store: data.order?.store || store
            };

            if (data.payment?.status === 'awaiting_payment') {
                setOrderResult(order);
                setPaymentInfo(data.payment || null);
                setStep(3);
                return;
            }

            finalizeOrderSuccess(data, order);
        } catch (err) {
            setError(getApiErrorMessage(err, 'Erro ao finalizar pedido.'));
        } finally {
            setLoading(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center p-0 sm:p-4">
            <div
                className="absolute inset-0 bg-slate-950/45 backdrop-blur-[2px]"
                onClick={showOrderConfirmation ? undefined : onClose}
            />

            <div className="relative w-full max-w-xl lg:max-w-2xl h-[92dvh] max-h-[92dvh] sm:h-auto sm:max-h-[92dvh] bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col min-h-0 overflow-hidden">
                <div className="shrink-0 px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-white">
                    <div>
                        <h2 className="text-lg font-black text-slate-900">Finalizar pedido</h2>
                        <p className="text-xs font-semibold text-slate-400">
                            {step === 1 && 'Entrega e contato'}
                            {step === 2 && 'Pagamento'}
                            {step === 3 && 'Confirmação'}
                        </p>
                    </div>

                    <button onClick={onClose} className="p-2 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-all">
                        <X size="20" />
                    </button>
                </div>

                <div className="shrink-0 px-5 pt-5 pb-2 bg-white">
                    <div className="flex items-start justify-between gap-2">
                        {STEPS.map((item, index) => (
                            <React.Fragment key={item.id}>
                                <div className="flex flex-col items-center gap-1.5 min-w-[56px]">
                                    <div
                                        className={`w-9 h-9 rounded-full flex items-center justify-center text-sm font-black transition-all ${
                                            step >= item.id
                                                ? 'bg-[var(--store-primary)] text-white shadow-md shadow-[var(--store-primary)]/20'
                                                : 'bg-slate-100 text-slate-400'
                                        }`}
                                    >
                                        {step > item.id ? <CheckCircle size={16} strokeWidth={3} /> : item.id}
                                    </div>
                                    <span className={`text-[10px] uppercase font-black text-center leading-tight ${
                                        step >= item.id ? 'text-slate-900' : 'text-slate-400'
                                    }`}>
                                        {item.label}
                                    </span>
                                </div>

                                {index < STEPS.length - 1 && (
                                    <div className="flex-1 mt-4 h-1 rounded-full bg-slate-100 overflow-hidden">
                                        <div
                                            className={`h-full rounded-full transition-all duration-300 ${
                                                step > item.id ? 'bg-[var(--store-primary)] w-full' : 'w-0'
                                            }`}
                                        />
                                    </div>
                                )}
                            </React.Fragment>
                        ))}
                    </div>
                </div>

                <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-5 py-4 space-y-4">
                    {error && (
                        <div className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-100 text-amber-700 text-sm font-bold">
                            {error}
                        </div>
                    )}

                    {profileLoading ? (
                        <CustomerLoadingPanel message="Carregando seus dados..." size="lg" />
                    ) : (
                        <>
                            {step === 1 && (
                                <div className="space-y-4 pb-2">
                                    <div className="grid grid-cols-2 gap-2">
                                        <button
                                            type="button"
                                            onClick={() => updateForm('fulfillment_type', 'delivery')}
                                            className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${
                                                form.fulfillment_type === 'delivery'
                                                    ? 'border-[var(--store-primary)] bg-[var(--store-primary)] text-white'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                            }`}
                                        >
                                            <Home size="16" />
                                            Entrega
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => updateForm('fulfillment_type', 'pickup')}
                                            className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${
                                                form.fulfillment_type === 'pickup'
                                                    ? 'border-[var(--store-primary)] bg-[var(--store-primary)] text-white'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                            }`}
                                        >
                                            <Store size="16" />
                                            Retirada
                                        </button>
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
                                        <div className="flex items-center gap-2">
                                            <div className="h-8 w-8 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-500">
                                                <User size={16} />
                                            </div>
                                            <div>
                                                <h4 className="text-sm font-black text-slate-900">Seus dados</h4>
                                                <p className="text-xs text-slate-500">Usados para contato e entrega</p>
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div className="space-y-1.5">
                                                <label className="text-[11px] font-bold text-slate-500">
                                                    Nome completo <span className="text-[var(--store-primary)]">*</span>
                                                </label>
                                                <input
                                                    value={form.customer_name}
                                                    onChange={(e) => updateForm('customer_name', e.target.value)}
                                                    required
                                                    className="w-full h-11 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:bg-white focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10"
                                                />
                                            </div>

                                            <div className="space-y-1.5">
                                                <label className="text-[11px] font-bold text-slate-500">
                                                    WhatsApp <span className="text-[var(--store-primary)]">*</span>
                                                </label>
                                                <input
                                                    value={form.customer_phone}
                                                    onChange={(e) => updateForm('customer_phone', e.target.value)}
                                                    onBlur={handlePhoneBlur}
                                                    required
                                                    className="w-full h-11 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:bg-white focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10"
                                                />
                                                <p className="text-[10px] text-slate-400">Se já comprou antes, preenchemos automaticamente.</p>
                                            </div>
                                        </div>
                                    </div>

                                    {form.fulfillment_type === 'delivery' && (
                                        <AddressSection
                                            values={form}
                                            onChange={handleAddressChange}
                                            deliveryAreas={deliveryAreas}
                                            deliveryAreasLoading={deliveryAreasLoading}
                                            selectedDeliveryArea={selectedDeliveryArea}
                                            deliveryFee={deliveryFee}
                                            searchNear={store?.address}
                                            proximityLat={store?.latitude}
                                            proximityLng={store?.longitude}
                                            showLocationButton={false}
                                            hideDistrictField
                                            required
                                        />
                                    )}

                                    {form.fulfillment_type === 'pickup' && (
                                        <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                            <p className="text-[10px] font-black text-slate-400 uppercase">Retirada no local</p>
                                            <p className="text-sm font-bold text-slate-700 mt-1">
                                                {store?.address || 'Endereço da loja não informado.'}
                                            </p>
                                        </div>
                                    )}

                                    <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-1.5">
                                        <label className="text-[11px] font-bold text-slate-500">
                                            Observação do pedido (opcional)
                                        </label>
                                        <textarea
                                            value={form.observation}
                                            onChange={(e) => updateForm('observation', e.target.value)}
                                            maxLength={180}
                                            rows={3}
                                            className="w-full px-3.5 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:bg-white focus:border-[var(--store-primary)] resize-none"
                                        />
                                        <div className="text-right text-[10px] text-slate-400">
                                            {form.observation.length}/180
                                        </div>
                                    </div>
                                </div>
                            )}

                            {step === 2 && (
                                <div className="space-y-4 pb-2">
                                    {hasPaymentMethods ? (
                                        <>
                                            {availableOnlineMethods.length > 0 && (
                                                <div className="space-y-2">
                                                    <p className="text-[11px] font-black uppercase tracking-wider text-slate-400">
                                                        Pagar agora
                                                    </p>
                                                    <div className="grid grid-cols-1 gap-2">
                                                        {availableOnlineMethods.map(([value, label, Icon]) => (
                                                            <button
                                                                key={value}
                                                                type="button"
                                                                onClick={() => {
                                                                    updateForm('payment_method', value);
                                                                    updateForm('needs_change', false);
                                                                    updateForm('change_for', '');
                                                                    if (value === 'credit_card_online' && form.customer_name.trim() && !card.holder_name.trim()) {
                                                                        updateCard('holder_name', form.customer_name.trim());
                                                                    }
                                                                }}
                                                                className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${
                                                                    form.payment_method === value
                                                                        ? 'border-[var(--store-primary)] bg-[var(--store-primary)] text-white'
                                                                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                                }`}
                                                            >
                                                                <Icon size="16" />
                                                                {label}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {form.payment_method === 'credit_card_online' && (
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 space-y-3">
                                                    <p className="text-sm font-black text-slate-900">Dados do cartão</p>

                                                    <div className="space-y-1.5">
                                                        <label className="text-[11px] font-bold text-slate-500">
                                                            Nome impresso no cartão <span className="text-[var(--store-primary)]">*</span>
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={card.holder_name}
                                                            onChange={(e) => updateCard('holder_name', e.target.value)}
                                                            placeholder={form.customer_name || 'Como está no cartão'}
                                                            autoComplete="cc-name"
                                                            className="w-full h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:border-[var(--store-primary)]"
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <label className="text-[11px] font-bold text-slate-500">
                                                            CPF do titular <span className="text-[var(--store-primary)]">*</span>
                                                        </label>
                                                        <input
                                                            type="text"
                                                            inputMode="numeric"
                                                            value={card.holder_document}
                                                            onChange={(e) => updateCard('holder_document', e.target.value)}
                                                            placeholder="000.000.000-00"
                                                            autoComplete="off"
                                                            className="w-full h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:border-[var(--store-primary)]"
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <label className="text-[11px] font-bold text-slate-500">
                                                            Número do cartão <span className="text-[var(--store-primary)]">*</span>
                                                        </label>
                                                        <input
                                                            type="text"
                                                            inputMode="numeric"
                                                            value={card.number}
                                                            onChange={(e) => updateCard('number', e.target.value.replace(/\D/g, '').slice(0, 16))}
                                                            placeholder="0000 0000 0000 0000"
                                                            autoComplete="cc-number"
                                                            className="w-full h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:border-[var(--store-primary)]"
                                                        />
                                                    </div>

                                                    <div className="grid grid-cols-3 gap-2">
                                                        <div className="space-y-1.5">
                                                            <label className="text-[11px] font-bold text-slate-500">Mês</label>
                                                            <input
                                                                type="text"
                                                                inputMode="numeric"
                                                                value={card.exp_month}
                                                                onChange={(e) => updateCard('exp_month', e.target.value.replace(/\D/g, '').slice(0, 2))}
                                                                placeholder="MM"
                                                                autoComplete="cc-exp-month"
                                                                className="h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:border-[var(--store-primary)]"
                                                            />
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <label className="text-[11px] font-bold text-slate-500">Ano</label>
                                                            <input
                                                                type="text"
                                                                inputMode="numeric"
                                                                value={card.exp_year}
                                                                onChange={(e) => updateCard('exp_year', e.target.value.replace(/\D/g, '').slice(0, 4))}
                                                                placeholder="AAAA"
                                                                autoComplete="cc-exp-year"
                                                                className="h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:border-[var(--store-primary)]"
                                                            />
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <label className="text-[11px] font-bold text-slate-500">CVV</label>
                                                            <input
                                                                type="text"
                                                                inputMode="numeric"
                                                                value={card.cvv}
                                                                onChange={(e) => updateCard('cvv', e.target.value.replace(/\D/g, '').slice(0, 4))}
                                                                placeholder="123"
                                                                autoComplete="cc-csc"
                                                                className="h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:border-[var(--store-primary)]"
                                                            />
                                                        </div>
                                                    </div>

                                                    <p className="text-[11px] font-semibold text-slate-500">
                                                        Pagamento processado com segurança via Pagar.me.
                                                    </p>
                                                </div>
                                            )}

                                            {availableOfflineMethods.length > 0 && (
                                                <div className="space-y-2">
                                                    <p className="text-[11px] font-black uppercase tracking-wider text-slate-400">
                                                        Pagar na {form.fulfillment_type === 'pickup' ? 'retirada' : 'entrega'}
                                                    </p>
                                                    <div className="grid grid-cols-2 gap-2">
                                                        {availableOfflineMethods.map(([value, label, Icon]) => (
                                                            <button
                                                                key={value}
                                                                type="button"
                                                                onClick={() => {
                                                                    updateForm('payment_method', value);
                                                                    if (value !== 'cash') {
                                                                        updateForm('needs_change', false);
                                                                        updateForm('change_for', '');
                                                                    }
                                                                }}
                                                                className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${
                                                                    form.payment_method === value
                                                                        ? 'border-[var(--store-primary)] bg-[var(--store-primary)] text-white'
                                                                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                                }`}
                                                            >
                                                                <Icon size="16" />
                                                                {label}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </>
                                    ) : (
                                        <p className="rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                                            Esta loja ainda não configurou formas de pagamento.
                                        </p>
                                    )}

                                    {form.payment_method === 'cash' && (
                                        <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 space-y-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-black text-slate-900">Pagamento em dinheiro</p>
                                                    <p className="text-xs font-medium text-slate-500 mt-0.5">
                                                        Informe se precisa de troco para o entregador.
                                                    </p>
                                                </div>

                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-checked={form.needs_change}
                                                    onClick={() => {
                                                        const next = !form.needs_change;
                                                        updateForm('needs_change', next);
                                                        if (!next) updateForm('change_for', '');
                                                    }}
                                                    className={`relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors ${
                                                        form.needs_change ? 'bg-[var(--store-primary)]' : 'bg-slate-200'
                                                    }`}
                                                >
                                                    <span
                                                        className={`inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform ${
                                                            form.needs_change ? 'translate-x-6' : 'translate-x-1'
                                                        }`}
                                                    />
                                                </button>
                                            </div>

                                            <div className="flex items-center justify-between rounded-xl bg-white border border-slate-200 px-3.5 py-2.5">
                                                <span className="text-sm font-semibold text-slate-600">Preciso de troco</span>
                                                <span className={`text-xs font-black uppercase ${form.needs_change ? 'text-[var(--store-primary)]' : 'text-slate-400'}`}>
                                                    {form.needs_change ? 'Sim' : 'Não'}
                                                </span>
                                            </div>

                                            {form.needs_change && (
                                                <div className="space-y-1.5">
                                                    <label className="text-[11px] font-bold text-slate-500">Troco para quanto?</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={form.change_for}
                                                        onChange={(e) => updateForm('change_for', e.target.value)}
                                                        className="w-full h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10"
                                                    />

                                                    {Number(form.change_for || 0) > total && (
                                                        <p className="text-xs font-bold text-slate-600">
                                                            Troco estimado: {formatCurrency(Number(form.change_for) - total)}
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {couponsEnabled && (
                                        <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
                                            <div className="flex items-center gap-2">
                                                <Sparkles className="h-4 w-4 text-[var(--store-primary)]" />
                                                <div>
                                                    <p className="text-sm font-black text-slate-900">Cupom de desconto</p>
                                                    <p className="text-xs text-slate-500">Aplique aqui se ainda não usou no carrinho</p>
                                                </div>
                                            </div>

                                            {appliedCoupon ? (
                                                <div className="flex items-center justify-between rounded-xl bg-emerald-50 border border-emerald-100 px-3.5 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <Ticket className="w-4 h-4 text-emerald-600" />
                                                        <div>
                                                            <p className="text-sm font-black text-emerald-700">{appliedCoupon.code}</p>
                                                            <p className="text-xs font-bold text-emerald-600">
                                                                - {formatCurrency(discount)}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={onRemoveCoupon}
                                                        className="p-1.5 rounded-lg text-emerald-700 hover:bg-white"
                                                    >
                                                        <X size={16} />
                                                    </button>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="flex gap-2">
                                                        <div className="relative flex-1">
                                                            <Ticket className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                                            <input
                                                                type="text"
                                                                value={coupon}
                                                                onChange={(e) => setCoupon?.(e.target.value.toUpperCase())}
                                                                className="w-full pl-9 pr-3 h-11 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold uppercase outline-none focus:bg-white focus:border-[var(--store-primary)]"
                                                            />
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={onApplyCoupon}
                                                            disabled={couponLoading || !coupon?.trim()}
                                                            className="h-11 px-4 rounded-xl bg-slate-900 text-white text-xs font-black hover:bg-[var(--store-primary)] transition-colors disabled:opacity-50"
                                                        >
                                                            {couponLoading ? '...' : 'Aplicar'}
                                                        </button>
                                                    </div>
                                                    {couponError && (
                                                        <p className="text-xs font-bold text-[var(--store-primary)]">{couponError}</p>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    )}

                                    <div className="space-y-2 px-4 py-4 rounded-2xl bg-slate-50 border border-slate-200">
                                        <div className="flex justify-between text-sm text-slate-500">
                                            <span>Subtotal</span>
                                            <span>{formatCurrency(subtotal)}</span>
                                        </div>

                                        <div className="flex justify-between text-sm text-slate-500">
                                            <span>Entrega</span>
                                            <span>{form.fulfillment_type === 'pickup' ? 'Retirada' : (deliveryFee === 0 ? 'A confirmar' : formatCurrency(deliveryFee))}</span>
                                        </div>

                                        {discount > 0 && (
                                            <div className="flex justify-between text-sm text-emerald-600 font-bold">
                                                <span>Cupom {appliedCoupon?.code}</span>
                                                <span>- {formatCurrency(discount)}</span>
                                            </div>
                                        )}

                                        <div className="pt-2 border-t border-slate-200 flex justify-between text-base font-black text-slate-900">
                                            <span>Total</span>
                                            <span>{formatCurrency(total)}</span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {step === 3 && showOrderConfirmation && (
                                <div className="text-center py-6 space-y-5 flex flex-col items-center">
                                    <div className="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center animate-bounce mx-auto">
                                        <CheckCircle size={32} />
                                    </div>

                                    <div className="space-y-2">
                                        <h3 className="text-xl font-black text-slate-900">Pedido criado com sucesso!</h3>
                                        <p className="text-sm font-semibold text-slate-500 max-w-sm mx-auto leading-relaxed">
                                            {confirmedWhatsAppUrl
                                                ? 'Seu pedido foi registrado. Toque abaixo para enviar os detalhes no WhatsApp da loja.'
                                                : 'Seu pedido foi registrado, mas a loja ainda não configurou o WhatsApp para receber pedidos automaticamente.'}
                                        </p>
                                    </div>

                                    {confirmedWhatsAppUrl ? (
                                        <a
                                            href={confirmedWhatsAppUrl}
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
                                        onClick={onClose}
                                        className="w-full h-12 rounded-xl border border-slate-200 text-slate-600 font-black text-sm hover:bg-slate-50 transition-all"
                                    >
                                        Voltar ao cardápio
                                    </button>
                                </div>
                            )}

                            {step === 3 && orderResult && awaitingOnlinePayment && form.payment_method === 'pix_online' && (
                                <PixPaymentStep
                                    order={orderResult}
                                    payment={paymentInfo}
                                    customerPhone={orderResult?.customer_phone || form.customer_phone}
                                    onPaid={(data) => {
                                        setPaymentInfo((current) => ({
                                            ...(current || {}),
                                            ...(data?.payment || {}),
                                            status: 'paid',
                                        }));

                                        const paidOrder = {
                                            ...(data?.order || orderResult),
                                            payment_status: 'paid',
                                        };

                                        finalizeOrderSuccess(data, paidOrder);
                                    }}
                                    onExpired={() => {
                                        setError('O Pix expirou. Feche e tente novamente.');
                                        setStep(2);
                                    }}
                                />
                            )}

                            {step === 3 && orderResult && awaitingOnlinePayment && form.payment_method === 'credit_card_online' && (
                                <CardPaymentPendingStep
                                    order={orderResult}
                                    payment={paymentInfo}
                                    customerPhone={orderResult?.customer_phone || form.customer_phone}
                                    onPaid={(data) => {
                                        setPaymentInfo((current) => ({
                                            ...(current || {}),
                                            ...(data?.payment || {}),
                                            status: 'paid',
                                        }));

                                        if (data?.order) {
                                            finalizeOrderSuccess(data, data.order);
                                            return;
                                        }

                                        finalizeOrderSuccess(data, orderResult);
                                    }}
                                    onFailed={() => {
                                        setError('Pagamento não aprovado. Feche e tente novamente.');
                                        setStep(2);
                                    }}
                                />
                            )}
                        </>
                    )}
                </div>

                {step < 3 && !showOrderConfirmation && (
                    <div className="shrink-0 px-5 py-4 border-t border-slate-100 bg-white flex gap-3 safe-area-pb">
                        {step > 1 && (
                            <button
                                type="button"
                                onClick={prevStep}
                                disabled={profileLoading}
                                className="h-12 px-5 rounded-xl border border-slate-200 text-slate-600 font-black text-sm hover:bg-slate-100 transition-all disabled:opacity-50"
                            >
                                Voltar
                            </button>
                        )}

                        <button
                            type="button"
                            onClick={handleStepAction}
                            disabled={loading || profileLoading || !hasPaymentMethods}
                            className="flex-1 h-12 bg-[var(--store-primary)] text-white rounded-xl font-black text-sm flex items-center justify-center gap-2 hover:brightness-90 transition-all disabled:opacity-50"
                        >
                            {loading ? (
                                <Loader2 className="animate-spin text-white" size="18" />
                            ) : (
                                step === 2
                                    ? (form.payment_method === 'pix_online'
                                        ? 'Gerar Pix'
                                        : form.payment_method === 'credit_card_online'
                                            ? 'Pagar e finalizar'
                                            : 'Finalizar pedido')
                                    : 'Continuar'
                            )}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
