// src/components/Checkout.jsx
import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    X,
    MapPin,
    Loader2,
    Home,
    Store,
    CreditCard,
    Banknote,
    Smartphone,
    CheckCircle,
    Search,
    Navigation
} from 'lucide-react';
import api from '../services/api';

const GOOGLE_MAPS_API_KEY = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;

const formatCurrency = (value) => {
    return Number(value || 0).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    });
};

const onlyDigits = (value) => String(value || '').replace(/\D/g, '');

const loadGoogleMaps = () => {
    return new Promise((resolve, reject) => {
        if (window.google?.maps?.places) {
            resolve(window.google);
            return;
        }

        const existing = document.getElementById('google-maps-places-script');

        if (existing) {
            existing.addEventListener('load', () => resolve(window.google));
            existing.addEventListener('error', reject);
            return;
        }

        if (!GOOGLE_MAPS_API_KEY) {
            reject(new Error('Chave do Google Maps não configurada.'));
            return;
        }

        const script = document.createElement('script');
        script.id = 'google-maps-places-script';
        script.src = `https://maps.googleapis.com/maps/api/js?key=${GOOGLE_MAPS_API_KEY}&libraries=places`;
        script.async = true;
        script.onload = () => resolve(window.google);
        script.onerror = reject;
        document.head.appendChild(script);
    });
};

const getAddressPart = (components, type) => {
    return components?.find(component => component.types.includes(type))?.long_name || '';
};

export default function Checkout({
    isOpen,
    onClose,
    store,
    cart,
    subtotal,
    appliedCoupon,
    onSuccess
}) {
    const [step, setStep] = useState(1);
    const [loading, setLoading] = useState(false);
    const [profileLoading, setProfileLoading] = useState(false);
    const [locationLoading, setLocationLoading] = useState(false);
    const [mapsReady, setMapsReady] = useState(false);
    const [error, setError] = useState('');
    const [orderResult, setOrderResult] = useState(null);
    const [mapsError, setMapsError] = useState('');

    const autocompleteServiceRef = useRef(null);
    const placesServiceRef = useRef(null);
    const placesDivRef = useRef(null);

    const [form, setForm] = useState(() => {
        const savedCustomer = localStorage.getItem('@fooddash:customer');
        const customer = savedCustomer ? JSON.parse(savedCustomer) : null;

        return {
            fulfillment_type: 'delivery',
            customer_name: customer?.customer_name || '',
            customer_phone: customer?.customer_phone || '',
            address: customer?.address || '',
            address_number: customer?.address_number || '',
            address_complement: customer?.address_complement || '',
            district: customer?.district || '',
            latitude: '',
            longitude: '',
            payment_method: 'pix',
            change_for: '',
            observation: ''
        };
    });

    const [addressQuery, setAddressQuery] = useState(() => {
        const savedCustomer = localStorage.getItem('@fooddash:customer');
        const customer = savedCustomer ? JSON.parse(savedCustomer) : null;
        return customer?.address || '';
    });

    const [addressSuggestions, setAddressSuggestions] = useState([]);

    const deliveryFee = form.fulfillment_type === 'delivery'
        ? Number(store?.delivery_fee || 0)
        : 0;

    const discountAmount = Number(appliedCoupon?.discount_amount || 0);
    const total = Math.max(0, Number(subtotal || 0) + deliveryFee - discountAmount);

    const whatsappPhone = useMemo(() => {
        return store?.whatsapp_number || store?.whatsapp_phone || store?.phone || '';
    }, [store]);

    useEffect(() => {
        if (!isOpen) return;

        setStep(1);
        setError('');
        setMapsError('');
        setOrderResult(null);

        const token = localStorage.getItem('token');

        if (token) {
            setProfileLoading(true);

            api.get('/customer/profile', {
                headers: {
                    Authorization: `Bearer ${token}`
                }
            })
                .then(response => {
                    const user = response.data.customer || response.data.user;

                    if (user) {
                        setForm(prev => ({
                            ...prev,
                            customer_name: user.name || prev.customer_name,
                            customer_phone: user.phone || prev.customer_phone,
                            address: user.address || prev.address,
                            address_number: user.address_number || prev.address_number,
                            address_complement: user.address_complement || prev.address_complement,
                            district: user.district || prev.district,
                            latitude: user.latitude || prev.latitude,
                            longitude: user.longitude || prev.longitude
                        }));

                        if (user.address) {
                            setAddressQuery(user.address);
                        }
                    }
                })
                .catch(err => {
                    console.error('Erro ao recuperar dados do usuário logado:', err);
                })
                .finally(() => {
                    setProfileLoading(false);
                });
        }

        loadGoogleMaps()
            .then((google) => {
                if (!google?.maps?.places) {
                    setMapsReady(false);
                    setMapsError('Busca automática indisponível. Digite o endereço manualmente.');
                    return;
                }

                autocompleteServiceRef.current = new google.maps.places.AutocompleteService();

                if (placesDivRef.current) {
                    placesServiceRef.current = new google.maps.places.PlacesService(placesDivRef.current);
                }

                setMapsReady(true);
                setMapsError('');
            })
            .catch(() => {
                setMapsReady(false);
                setMapsError('Busca automática indisponível. Digite o endereço manualmente.');
            });
    }, [isOpen]);

    useEffect(() => {
        if (!mapsReady || !addressQuery || addressQuery.length < 3 || form.fulfillment_type !== 'delivery') {
            setAddressSuggestions([]);
            return;
        }

        const timeout = setTimeout(() => {
            autocompleteServiceRef.current?.getPlacePredictions(
                {
                    input: addressQuery,
                    componentRestrictions: { country: 'br' },
                    types: ['address']
                },
                (predictions, status) => {
                    const placesStatus = window.google?.maps?.places?.PlacesServiceStatus;

                    if (status !== placesStatus?.OK || !predictions) {
                        setAddressSuggestions([]);

                        if (status === placesStatus?.REQUEST_DENIED) {
                            setMapsError('Google Places bloqueado. Verifique billing, APIs habilitadas e restrições da chave.');
                        } else if (status === placesStatus?.OVER_QUERY_LIMIT) {
                            setMapsError('Limite de consultas do Google Places atingido. Digite o endereço manualmente.');
                        } else if (status === placesStatus?.ZERO_RESULTS) {
                            setMapsError('');
                        } else {
                            setMapsError('Busca automática indisponível. Digite o endereço manualmente.');
                        }

                        return;
                    }

                    setMapsError('');
                    setAddressSuggestions(predictions.slice(0, 5));
                }
            );
        }, 350);

        return () => clearTimeout(timeout);
    }, [addressQuery, mapsReady, form.fulfillment_type]);

    const updateForm = (key, value) => {
        setForm(prev => ({ ...prev, [key]: value }));
        setError('');
    };

    const saveLoggedCustomerProfile = async () => {
        const token = localStorage.getItem('token');

        if (!token || form.fulfillment_type !== 'delivery') return null;

        const profilePayload = {
            name: form.customer_name,
            phone: onlyDigits(form.customer_phone),
            address: form.address,
            address_number: form.address_number,
            district: form.district,
            address_complement: form.address_complement
        };

        const { data } = await api.put('/customer/profile', profilePayload, {
            headers: {
                Authorization: `Bearer ${token}`
            }
        });

        return data.user || data.customer || null;
    };

    const selectSuggestion = (suggestion) => {
        if (!placesServiceRef.current) {
            setError('Busca automática indisponível. Digite o endereço manualmente.');
            return;
        }

        placesServiceRef.current.getDetails(
            {
                placeId: suggestion.place_id,
                fields: ['formatted_address', 'geometry', 'address_components']
            },
            (place, status) => {
                const placesStatus = window.google?.maps?.places?.PlacesServiceStatus;

                if (status !== placesStatus?.OK || !place) {
                    setError('Não foi possível carregar este endereço.');
                    return;
                }

                const components = place.address_components || [];
                const street = getAddressPart(components, 'route');
                const number = getAddressPart(components, 'street_number');
                const district =
                    getAddressPart(components, 'sublocality_level_1') ||
                    getAddressPart(components, 'sublocality') ||
                    getAddressPart(components, 'neighborhood');

                const address = street || place.formatted_address || suggestion.description;

                setAddressQuery(place.formatted_address || suggestion.description);
                setAddressSuggestions([]);
                setMapsError('');

                setForm(prev => ({
                    ...prev,
                    address,
                    address_number: number || prev.address_number,
                    district,
                    latitude: place.geometry?.location?.lat() || '',
                    longitude: place.geometry?.location?.lng() || ''
                }));
            }
        );
    };

    const useCurrentLocation = () => {
        if (!navigator.geolocation) {
            setError('Seu navegador não permite usar localização.');
            return;
        }

        setLocationLoading(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                setForm(prev => ({
                    ...prev,
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude
                }));

                setError('');
                setLocationLoading(false);
            },
            (geoError) => {
                const messages = {
                    1: 'Permissão de localização negada. Libere o acesso no navegador.',
                    2: 'Localização indisponível no momento.',
                    3: 'Tempo esgotado ao tentar capturar sua localização.'
                };

                setError(messages[geoError.code] || 'Não foi possível capturar sua localização.');
                setLocationLoading(false);
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    };

    const validateStep = () => {
        if (step === 1) {
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
                if (!form.address.trim()) {
                    setError('Informe o endereço de entrega.');
                    return false;
                }

                if (!form.address_number.trim()) {
                    setError('Informe o número.');
                    return false;
                }

                if (!form.district.trim()) {
                    setError('Informe o bairro.');
                    return false;
                }
            }
        }

        if (step === 2) {
            if (!form.payment_method) {
                setError('Escolha uma forma de pagamento.');
                return false;
            }

            if (form.payment_method === 'cash' && form.change_for && Number(form.change_for) < total) {
                setError('O troco precisa ser maior que o total.');
                return false;
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

    const openWhatsAppUrl = (url) => {
        if (!url) return false;

        const opened = window.open(url, '_blank', 'noopener,noreferrer');

        return Boolean(opened);
    };

    const submitOrder = async () => {
        if (!validateStep()) return;

        try {
            setLoading(true);
            setError('');

            const payload = {
                store_id: store.id,
                fulfillment_type: form.fulfillment_type,
                customer_name: form.customer_name,
                customer_phone: onlyDigits(form.customer_phone),
                delivery_area_id: null,
                address: form.fulfillment_type === 'delivery' ? form.address : null,
                address_number: form.fulfillment_type === 'delivery' ? form.address_number : null,
                address_complement: form.fulfillment_type === 'delivery' ? form.address_complement : null,
                district: form.fulfillment_type === 'delivery' ? form.district : null,
                latitude: form.fulfillment_type === 'delivery' && form.latitude ? form.latitude : null,
                longitude: form.fulfillment_type === 'delivery' && form.longitude ? form.longitude : null,
                payment_method: form.payment_method,
                change_for: form.payment_method === 'cash' && form.change_for ? Number(form.change_for) : null,
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

            const dadosParaSessao = {
                name: form.customer_name,
                customer_name: form.customer_name,
                phone: form.customer_phone,
                customer_phone: form.customer_phone,
                address: form.address,
                address_number: form.address_number,
                address_complement: form.address_complement,
                district: form.district
            };

            const savedUser = await saveLoggedCustomerProfile();

            if (savedUser) {
                localStorage.setItem('user', JSON.stringify(savedUser));
            }

            localStorage.setItem('@fooddash:customer', JSON.stringify(dadosParaSessao));

            const { data } = await api.post('/checkout/orders', payload);

            window.dispatchEvent(new Event('customer-session-updated'));

            setOrderResult(data);
            setStep(3);

            if (data?.whatsapp_url) {
                const opened = openWhatsAppUrl(data.whatsapp_url);

                if (!opened) {
                    setError('Pedido criado. Se o WhatsApp não abrir, toque no botão verde para enviar.');
                }
            }

            if (typeof onSuccess === 'function') {
                onSuccess(data);
            }
        } catch (err) {
            setError(
                err.response?.data?.message ||
                err.response?.data?.details ||
                'Erro ao finalizar pedido.'
            );
        } finally {
            setLoading(false);
        }
    };

    const openWhatsApp = () => {
        if (orderResult?.whatsapp_url) {
            openWhatsAppUrl(orderResult.whatsapp_url);
            return;
        }

        if (whatsappPhone) {
            openWhatsAppUrl(`https://wa.me/${onlyDigits(whatsappPhone)}`);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" onClick={onClose} />

            <div className="relative w-full max-w-xl max-h-[92vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
                <div ref={placesDivRef} className="hidden" />

                <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
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

                <div className="px-8 pt-6 pb-4">
                    <div className="flex items-center justify-between w-full relative">
                        {[
                            { id: 1, label: 'Entrega' },
                            { id: 2, label: 'Pagamento' },
                            { id: 3, label: 'Confirmação' }
                        ].map((item, index) => (
                            <React.Fragment key={item.id}>
                                <div className="flex flex-col items-center relative z-10">
                                    <div
                                        className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-black transition-all duration-300 ${step >= item.id
                                            ? 'bg-[var(--store-primary)] text-white shadow-md shadow-[var(--store-primary)]/20'
                                            : 'bg-slate-100 text-slate-400'
                                            }`}
                                    >
                                        {step > item.id ? <CheckCircle size={16} strokeWidth={3} /> : item.id}
                                    </div>
                                    <span
                                        className={`text-[10px] uppercase font-black mt-2 absolute top-8 whitespace-nowrap transition-colors duration-300 ${step >= item.id ? 'text-slate-900' : 'text-slate-400'
                                            }`}
                                    >
                                        {item.label}
                                    </span>
                                </div>
                                {index < 2 && (
                                    <div
                                        className={`flex-1 h-1 mx-2 rounded-full transition-all duration-300 ${step > item.id ? 'bg-[var(--store-primary)]' : 'bg-slate-100'
                                            }`}
                                    />
                                )}
                            </React.Fragment>
                        ))}
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-5 space-y-5">
                    {error && (
                        <div className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-100 text-amber-700 text-sm font-bold">
                            {error}
                        </div>
                    )}

                    {profileLoading ? (
                        <div className="flex flex-col items-center justify-center py-16 space-y-3">
                            <Loader2 className="w-8 h-8 animate-spin text-slate-900" />
                            <p className="text-sm font-black text-slate-500">Buscando seus dados...</p>
                        </div>
                    ) : (
                        <>
                            {step === 1 && (
                                <div className="space-y-5">
                                    <div className="grid grid-cols-2 gap-2">
                                        <button
                                            type="button"
                                            onClick={() => updateForm('fulfillment_type', 'delivery')}
                                            className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${form.fulfillment_type === 'delivery'
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
                                            className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${form.fulfillment_type === 'pickup'
                                                ? 'border-[var(--store-primary)] bg-[var(--store-primary)] text-white'
                                                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                }`}
                                        >
                                            <Store size="16" />
                                            Retirada
                                        </button>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Nome</label>
                                            <input
                                                value={form.customer_name}
                                                onChange={(e) => updateForm('customer_name', e.target.value)}
                                                placeholder="Seu nome"
                                                className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <label className="text-[11px] font-black text-slate-400 uppercase">WhatsApp</label>
                                            <input
                                                value={form.customer_phone}
                                                onChange={(e) => updateForm('customer_phone', e.target.value)}
                                                placeholder="85999999999"
                                                className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                            />
                                        </div>
                                    </div>

                                    {form.fulfillment_type === 'delivery' && (
                                        <div className="space-y-3">
                                            <div className="space-y-1.5 relative">
                                                <label className="text-[11px] font-black text-slate-400 uppercase">Endereço</label>

                                                <div className="relative">
                                                    <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                                    <input
                                                        value={addressQuery}
                                                        onChange={(e) => {
                                                            setAddressQuery(e.target.value);
                                                            updateForm('address', e.target.value);
                                                        }}
                                                        placeholder="Digite rua, avenida ou condomínio"
                                                        className="w-full pl-9 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                                    />
                                                </div>

                                                {mapsError && (
                                                    <p className="text-xs font-bold text-amber-600 mt-2">
                                                        {mapsError}
                                                    </p>
                                                )}

                                                {addressSuggestions.length > 0 && (
                                                    <div className="absolute left-0 right-0 top-full mt-2 z-20 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                                                        {addressSuggestions.map(suggestion => (
                                                            <button
                                                                key={suggestion.place_id}
                                                                type="button"
                                                                onClick={() => selectSuggestion(suggestion)}
                                                                className="w-full text-left px-4 py-3 hover:bg-slate-50 border-b border-slate-50 last:border-b-0 whitespace-normal"
                                                            >
                                                                <p className="text-sm font-black text-slate-800">
                                                                    {suggestion.structured_formatting?.main_text || suggestion.description}
                                                                </p>
                                                                <p className="text-xs font-semibold text-slate-400">
                                                                    {suggestion.structured_formatting?.secondary_text}
                                                                </p>
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                <input
                                                    value={form.address_number}
                                                    onChange={(e) => updateForm('address_number', e.target.value)}
                                                    placeholder="Número"
                                                    className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                                />
                                                <input
                                                    value={form.district}
                                                    onChange={(e) => updateForm('district', e.target.value)}
                                                    placeholder="Bairro"
                                                    className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                                />
                                                <input
                                                    value={form.address_complement}
                                                    onChange={(e) => updateForm('address_complement', e.target.value)}
                                                    placeholder="Complemento"
                                                    className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                                />
                                            </div>

                                            <button
                                                type="button"
                                                onClick={useCurrentLocation}
                                                disabled={locationLoading}
                                                className="w-full h-11 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-black text-sm flex items-center justify-center gap-2 disabled:opacity-50"
                                            >
                                                {locationLoading ? <Loader2 className="animate-spin" size="16" /> : <Navigation size="16" />}
                                                Usar minha localização
                                            </button>

                                            {(form.latitude && form.longitude) && (
                                                <div className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-700 text-xs font-bold flex items-center gap-2">
                                                    <MapPin size="15" />
                                                    Localização capturada. Você pode editar o endereço acima.
                                                </div>
                                            )}

                                            <div className="px-4 py-3 rounded-xl bg-slate-50 border border-slate-100">
                                                <p className="text-xs font-black text-slate-400 uppercase">Entrega</p>
                                                <p className="text-sm font-black text-slate-900 mt-1">
                                                    Taxa: {deliveryFee === 0 ? 'A confirmar' : formatCurrency(deliveryFee)}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {form.fulfillment_type === 'pickup' && (
                                        <div className="px-4 py-3 rounded-xl bg-slate-50 border border-slate-100">
                                            <p className="text-xs font-black text-slate-400 uppercase">Retirada no local</p>
                                            <p className="text-sm font-bold text-slate-700 mt-1">
                                                {store?.address || 'Endereço da loja não informado.'}
                                            </p>
                                        </div>
                                    )}

                                    <div className="space-y-1.5">
                                        <label className="text-[11px] font-black text-slate-400 uppercase">
                                            Observação do pedido
                                        </label>

                                        <textarea
                                            value={form.observation}
                                            onChange={(e) => updateForm('observation', e.target.value)}
                                            maxLength={180}
                                            rows={3}
                                            placeholder="Ex: chamar no WhatsApp ao chegar, entregar na portaria, retirar no balcão..."
                                            className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900 resize-none"
                                        />

                                        <div className="text-right text-[10px] text-slate-400 font-medium">
                                            {form.observation.length}/180 caracteres
                                        </div>
                                    </div>
                                </div>
                            )}

                            {step === 2 && (
                                <div className="space-y-5">
                                    <div className="grid grid-cols-2 gap-2">
                                        {[
                                            ['pix', 'Pix', Smartphone],
                                            ['cash', 'Dinheiro', Banknote],
                                            ['debit_card', 'Débito', CreditCard],
                                            ['credit_card', 'Crédito', CreditCard]
                                        ].map(([value, label, Icon]) => (
                                            <button
                                                key={value}
                                                type="button"
                                                onClick={() => updateForm('payment_method', value)}
                                                className={`h-12 rounded-xl border text-sm font-black flex items-center justify-center gap-2 transition-all ${form.payment_method === value
                                                    ? 'border-[var(--store-primary)] bg-[var(--store-primary)] text-white'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                    }`}
                                            >
                                                <Icon size="16" />
                                                {label}
                                            </button>
                                        ))}
                                    </div>

                                    {form.payment_method === 'cash' && (
                                        <div className="space-y-1.5">
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Troco para quanto?</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={form.change_for}
                                                onChange={(e) => updateForm('change_for', e.target.value)}
                                                placeholder="Ex: 100"
                                                className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:bg-white focus:border-slate-900"
                                            />
                                        </div>
                                    )}

                                    <div className="space-y-2 px-4 py-3 rounded-xl bg-slate-50 border border-slate-100">
                                        <div className="flex justify-between text-sm text-slate-500">
                                            <span>Subtotal</span>
                                            <span>{formatCurrency(subtotal)}</span>
                                        </div>

                                        <div className="flex justify-between text-sm text-slate-500">
                                            <span>Entrega</span>
                                            <span>{form.fulfillment_type === 'pickup' ? 'Retirada' : (deliveryFee === 0 ? 'A confirmar' : formatCurrency(deliveryFee))}</span>
                                        </div>

                                        {discountAmount > 0 && (
                                            <div className="flex justify-between text-sm text-emerald-600 font-bold">
                                                <span>Cupom {appliedCoupon?.code}</span>
                                                <span>- {formatCurrency(discountAmount)}</span>
                                            </div>
                                        )}

                                        <div className="pt-2 border-t border-slate-200 flex justify-between text-base font-black text-slate-900">
                                            <span>Total</span>
                                            <span>{formatCurrency(total)}</span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {step === 3 && orderResult && (
                                <div className="text-center py-6 space-y-5 flex flex-col items-center">
                                    <div className="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center animate-bounce mx-auto">
                                        <CheckCircle size={32} />
                                    </div>

                                    <div className="space-y-2">
                                        <h3 className="text-xl font-black text-slate-900">Pedido criado com sucesso!</h3>
                                        <p className="text-sm font-semibold text-slate-500 max-w-sm mx-auto leading-relaxed">
                                            Seu pedido foi registrado. Se o WhatsApp não abrir automaticamente, toque no botão abaixo.
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={openWhatsApp}
                                        className="w-full h-14 bg-emerald-600 text-white rounded-xl font-black text-base flex items-center justify-center gap-2 hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-100"
                                    >
                                        Enviar no WhatsApp da loja
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </div>

                {step < 3 && (
                    <div className="px-5 py-4 border-t border-slate-100 bg-slate-50/50 flex gap-3">
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
                            onClick={step === 2 ? submitOrder : nextStep}
                            disabled={loading || profileLoading}
                            className="flex-1 h-12 bg-[var(--store-primary)] text-white rounded-xl font-black text-sm flex items-center justify-center gap-2 hover:brightness-90 transition-all disabled:opacity-50"
                        >
                            {loading ? (
                                <Loader2 className="animate-spin" size="18" />
                            ) : (
                                step === 2 ? 'Finalizar Pedido' : 'Continuar'
                            )}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}