import React from 'react';
import {
  X,
  Info,
  Clock,
  CalendarDays,
  CreditCard,
  Banknote,
  Wallet,
  MapPin,
  Bike
} from 'lucide-react';

const dayNames = {
  0: 'Domingo',
  1: 'Segunda',
  2: 'Terça',
  3: 'Quarta',
  4: 'Quinta',
  5: 'Sexta',
  6: 'Sábado',
  sunday: 'Domingo',
  monday: 'Segunda',
  tuesday: 'Terça',
  wednesday: 'Quarta',
  thursday: 'Quinta',
  friday: 'Sexta',
  saturday: 'Sábado'
};

const formatTime = (value) => {
  if (!value) return null;
  return String(value).slice(0, 5);
};

const getStoreScheduleEntries = (store) => {
  const rawSchedule =
    store?.opening_hours ||
    store?.business_hours ||
    store?.working_hours ||
    store?.store_hours ||
    store?.schedules ||
    store?.hours ||
    [];

  const dayOrder = {
    monday: 1, 1: 1,
    tuesday: 2, 2: 2,
    wednesday: 3, 3: 3,
    thursday: 4, 4: 4,
    friday: 5, 5: 5,
    saturday: 6, 6: 6,
    sunday: 7, 0: 7
  };

  let entries = [];

  if (Array.isArray(rawSchedule)) {
    entries = rawSchedule.map((entry, index) => {
      const dayKey = entry.day_of_week ?? entry.weekday ?? entry.day ?? index;
      const opensAt = formatTime(entry.opens_at || entry.open_time || entry.open || entry.start || entry.from);
      const closesAt = formatTime(entry.closes_at || entry.close_time || entry.close || entry.end || entry.to);
      const isClosed = entry.is_closed || entry.closed || entry.closed_at || (!opensAt && !closesAt);

      return {
        key: `${dayKey}-${index}`,
        dayKey: String(dayKey).toLowerCase(),
        day: dayNames[String(dayKey).toLowerCase()] || dayNames[dayKey] || String(dayKey),
        hours: isClosed ? 'Fechado' : `${opensAt || '--:--'} - ${closesAt || '--:--'}`,
        isClosed
      };
    });
  } else if (rawSchedule && typeof rawSchedule === 'object') {
    entries = Object.entries(rawSchedule).map(([day, value]) => {
      if (typeof value === 'string') {
        return {
          key: day,
          dayKey: String(day).toLowerCase(),
          day: dayNames[String(day).toLowerCase()] || day,
          hours: value,
          isClosed: value.toLowerCase().includes('fechado') || value.toLowerCase().includes('closed')
        };
      }

      const opensAt = formatTime(value?.opens_at || value?.open_time || value?.open || value?.start || value?.from);
      const closesAt = formatTime(value?.closes_at || value?.close_time || value?.close || value?.end || value?.to);
      const isClosed = value?.is_closed || value?.closed || (!opensAt && !closesAt);

      return {
        key: day,
        dayKey: String(day).toLowerCase(),
        day: dayNames[String(day).toLowerCase()] || day,
        hours: isClosed ? 'Fechado' : `${opensAt || '--:--'} - ${closesAt || '--:--'}`,
        isClosed
      };
    });
  }

  return entries.sort((a, b) => {
    const orderA = dayOrder[a.dayKey] || 99;
    const orderB = dayOrder[b.dayKey] || 99;
    return orderA - orderB;
  });
};

const getPaymentMethods = (store) => {
  const rawMethods =
    store?.payment_methods ||
    store?.payments ||
    store?.accepted_payments ||
    store?.paymentMethods ||
    [];

  if (Array.isArray(rawMethods)) {
    return rawMethods.map((method) => {
      if (typeof method === 'string') return method;
      return method.name || method.label || method.title || method.type;
    }).filter(Boolean);
  }

  if (typeof rawMethods === 'string') {
    return rawMethods.split(',').map(item => item.trim()).filter(Boolean);
  }

  if (rawMethods && typeof rawMethods === 'object') {
    return Object.entries(rawMethods)
      .filter(([, enabled]) => Boolean(enabled))
      .map(([name]) => name);
  }

  return [];
};

const formatPaymentLabel = (method) => {
  const normalized = String(method).toLowerCase();

  const labels = {
    pix: 'Pix',
    cash: 'Dinheiro',
    dinheiro: 'Dinheiro',
    credit_card: 'Cartão de crédito',
    credito: 'Cartão de crédito',
    debit_card: 'Cartão de débito',
    debito: 'Cartão de débito',
    card: 'Cartão',
    online: 'Pagamento online'
  };

  return labels[normalized] || method;
};

const getPaymentIcon = (method) => {
  const normalized = String(method).toLowerCase();

  if (normalized.includes('cash') || normalized.includes('dinheiro')) return Banknote;
  if (normalized.includes('pix') || normalized.includes('online')) return Wallet;

  return CreditCard;
};

export default function StoreAboutModal({ store, deliveryFee, isOpen, onClose }) {
  if (!isOpen || !store) return null;

  const scheduleEntries = getStoreScheduleEntries(store);
  const paymentMethods = getPaymentMethods(store);

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/50 backdrop-blur-sm" onClick={onClose} />

      <div className="relative bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div className="p-5 sm:p-6 border-b border-slate-100 flex items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <div className="w-11 h-11 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
              <Info className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-xl font-black text-slate-950 leading-tight">Sobre a loja</h2>
              <p className="text-sm text-slate-500 mt-1">{store.name}</p>
            </div>
          </div>

          <button onClick={onClose} className="p-2 rounded-full hover:bg-slate-100 transition-colors">
            <X className="w-5 h-5 text-slate-500" />
          </button>
        </div>

        <div className="p-5 sm:p-6 overflow-y-auto space-y-5">
          {store.description && (
            <section className="rounded-2xl border border-slate-100 p-4">
              <p className="text-sm text-slate-600 leading-relaxed">{store.description}</p>
            </section>
          )}

          <section className="grid sm:grid-cols-2 gap-3">
            <div className="rounded-2xl border border-slate-100 p-4 bg-white">
              <div className="flex items-center gap-2 mb-2">
                <Bike className="w-4 h-4 text-emerald-600" />
                <h3 className="text-xs font-black uppercase text-slate-900">Entrega</h3>
              </div>
              <p className="text-sm font-bold text-slate-600">
                {deliveryFee === 0 ? 'Entrega grátis' : `Taxa de R$ ${deliveryFee.toFixed(2).replace('.', ',')}`}
              </p>
            </div>

            {store.address && (
              <div className="rounded-2xl border border-slate-100 p-4 bg-white">
                <div className="flex items-center gap-2 mb-2">
                  <MapPin className="w-4 h-4 text-red-500" />
                  <h3 className="text-xs font-black uppercase text-slate-900">Endereço</h3>
                </div>
                <p className="text-sm font-bold text-slate-600 leading-snug">{store.address}</p>
              </div>
            )}
          </section>

          <section className="rounded-2xl border border-slate-100 p-4 bg-white">
            <div className="flex items-center gap-2 mb-3">
              <CalendarDays className="w-4 h-4 text-red-600" />
              <h3 className="text-xs font-black uppercase text-slate-900">Horários de atendimento</h3>
            </div>

            {scheduleEntries.length > 0 ? (
              <div className="grid sm:grid-cols-2 gap-2">
                {scheduleEntries.map((entry) => (
                  <div
                    key={entry.key}
                    className={`flex items-center justify-between gap-3 rounded-xl px-3 py-2 border ${
                      entry.isClosed
                        ? 'bg-slate-50 border-slate-100 text-slate-400'
                        : 'bg-white border-slate-100 text-slate-700'
                    }`}
                  >
                    <span className="text-xs font-black">{entry.day}</span>
                    <span className="flex items-center gap-1.5 text-xs font-bold">
                      <Clock className="w-3.5 h-3.5 text-slate-400" />
                      {entry.hours}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm font-semibold text-slate-400">Horários não informados.</p>
            )}
          </section>

          <section className="rounded-2xl border border-slate-100 p-4 bg-white">
            <div className="flex items-center gap-2 mb-3">
              <CreditCard className="w-4 h-4 text-red-600" />
              <h3 className="text-xs font-black uppercase text-slate-900">Meios de pagamento</h3>
            </div>

            {paymentMethods.length > 0 ? (
              <div className="flex flex-wrap gap-2">
                {paymentMethods.map((method) => {
                  const Icon = getPaymentIcon(method);

                  return (
                    <span
                      key={method}
                      className="inline-flex items-center gap-2 rounded-full bg-slate-50 border border-slate-100 px-3 py-2 text-xs font-black text-slate-700"
                    >
                      <Icon className="w-3.5 h-3.5 text-red-500" />
                      {formatPaymentLabel(method)}
                    </span>
                  );
                })}
              </div>
            ) : (
              <p className="text-sm font-semibold text-slate-400">Meios de pagamento não informados.</p>
            )}
          </section>
        </div>
      </div>
    </div>
  );
}