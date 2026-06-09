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
  Bike,
  Store,
  CheckCircle2
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

  return ['pix', 'cash', 'debit_card', 'credit_card'];
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
  const statusMessage = store.status_message || store.opening_status?.message || (store.is_open ? 'Aberto agora' : 'Fechado');
  const nextOpening = store.next_opening || store.opening_status?.next_opening || null;
  const today = new Date().getDay();
  const todayEntry = scheduleEntries.find((entry) => {
    const normalized = String(entry.dayKey).toLowerCase();
    const todayKeys = {
      0: ['0', 'sunday'],
      1: ['1', 'monday'],
      2: ['2', 'tuesday'],
      3: ['3', 'wednesday'],
      4: ['4', 'thursday'],
      5: ['5', 'friday'],
      6: ['6', 'saturday']
    };

    return todayKeys[today]?.includes(normalized);
  });

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/50 backdrop-blur-sm" onClick={onClose} />

      <div className="relative bg-white w-full max-w-3xl rounded-3xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div className="relative overflow-hidden bg-slate-950 text-white p-5 sm:p-6">
          <div className="absolute right-0 top-0 h-40 w-40 rounded-bl-full bg-[var(--store-primary)]/25" />

          <div className="relative flex items-start justify-between gap-4">
            <div className="flex items-start gap-3 min-w-0">
              <div className="w-12 h-12 rounded-2xl bg-white/10 border border-white/10 text-white flex items-center justify-center flex-shrink-0">
                <Store className="w-5 h-5" />
              </div>
              <div className="min-w-0">
                <p className="text-[10px] font-black uppercase tracking-[0.18em] text-white/55">Informações da loja</p>
                <h2 className="text-2xl font-black leading-tight truncate">{store.name}</h2>
                <p className="text-sm text-white/70 mt-1">
                  {statusMessage}
                </p>
              </div>
            </div>

            <button onClick={onClose} className="p-2 rounded-2xl bg-white/10 hover:bg-white/15 transition-colors">
              <X className="w-5 h-5 text-white" />
            </button>
          </div>
        </div>

        <div className="p-5 sm:p-6 overflow-y-auto space-y-5 bg-slate-50/70">
          {store.description && (
            <section className="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm">
              <div className="flex items-center gap-2 mb-2">
                <Info className="w-4 h-4 text-[var(--store-primary)]" />
                <h3 className="text-xs font-black uppercase text-slate-900 tracking-wide">Sobre</h3>
              </div>
              <p className="text-sm font-semibold text-slate-600 leading-relaxed">{store.description}</p>
            </section>
          )}

          {!store.is_open && nextOpening && (
            <section className="rounded-3xl border border-amber-100 bg-amber-50 p-5 shadow-sm">
              <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl bg-white text-amber-600">
                  <Clock className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-xs font-black uppercase text-amber-800 tracking-wide">Próxima abertura</h3>
                  <p className="mt-1 text-sm font-black text-amber-900">
                    Abre {nextOpening.day_label} às {nextOpening.time}
                  </p>
                </div>
              </div>
            </section>
          )}

          <section className="grid sm:grid-cols-3 gap-3">
            <div className="rounded-3xl border border-slate-100 p-4 bg-white shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[var(--store-primary)]/10 text-[var(--store-primary)] mb-3">
                <Bike className="w-5 h-5" />
              </div>
              <h3 className="text-[10px] font-black uppercase text-slate-400 tracking-wide">Entrega</h3>
              <p className="mt-1 text-sm font-black text-slate-900">
                {deliveryFee === 0 ? 'Entrega grátis' : `Taxa de R$ ${deliveryFee.toFixed(2).replace('.', ',')}`}
              </p>
            </div>

            <div className="rounded-3xl border border-slate-100 p-4 bg-white shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[var(--store-primary)]/10 text-[var(--store-primary)] mb-3">
                <Clock className="w-5 h-5" />
              </div>
              <h3 className="text-[10px] font-black uppercase text-slate-400 tracking-wide">Hoje</h3>
              <p className="mt-1 text-sm font-black text-slate-900">
                {todayEntry?.hours || 'Horário não informado'}
              </p>
            </div>

            <div className="rounded-3xl border border-slate-100 p-4 bg-white shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[var(--store-primary)]/10 text-[var(--store-primary)] mb-3">
                <CreditCard className="w-5 h-5" />
              </div>
              <h3 className="text-[10px] font-black uppercase text-slate-400 tracking-wide">Pagamento</h3>
              <p className="mt-1 text-sm font-black text-slate-900">
                {paymentMethods.length} forma(s)
              </p>
            </div>
          </section>

          {store.address && (
            <section className="rounded-3xl border border-slate-100 p-5 bg-white shadow-sm">
              <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl bg-[var(--store-primary)]/10 text-[var(--store-primary)]">
                  <MapPin className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-xs font-black uppercase text-slate-900 tracking-wide">Endereço da loja</h3>
                  <p className="mt-1 text-sm font-bold text-slate-600 leading-snug">{store.address}</p>
                </div>
              </div>
            </section>
          )}

          <section className="rounded-3xl border border-slate-100 p-5 bg-white shadow-sm">
            <div className="flex items-center gap-2 mb-3">
              <CreditCard className="w-4 h-4 text-[var(--store-primary)]" />
              <h3 className="text-xs font-black uppercase text-slate-900 tracking-wide">Formas de pagamento aceitas</h3>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {paymentMethods.map((method) => {
                const Icon = getPaymentIcon(method);

                return (
                  <span
                    key={method}
                    className="inline-flex items-center justify-between gap-3 rounded-2xl bg-slate-50 border border-slate-100 px-3 py-3 text-xs font-black text-slate-700"
                  >
                    <span className="inline-flex items-center gap-2">
                      <Icon className="w-4 h-4 text-[var(--store-primary)]" />
                      {formatPaymentLabel(method)}
                    </span>
                    <CheckCircle2 className="w-4 h-4 text-emerald-500" />
                  </span>
                );
              })}
            </div>
          </section>

          <section className="rounded-3xl border border-slate-100 p-5 bg-white shadow-sm">
            <div className="flex items-center gap-2 mb-3">
              <CalendarDays className="w-4 h-4 text-[var(--store-primary)]" />
              <h3 className="text-xs font-black uppercase text-slate-900 tracking-wide">Horários de atendimento</h3>
            </div>

            {scheduleEntries.length > 0 ? (
              <div className="space-y-2">
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
        </div>
      </div>
    </div>
  );
}
