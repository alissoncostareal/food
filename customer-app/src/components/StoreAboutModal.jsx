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
    monday: 1,
    1: 1,
    tuesday: 2,
    2: 2,
    wednesday: 3,
    3: 3,
    thursday: 4,
    4: 4,
    friday: 5,
    5: 5,
    saturday: 6,
    6: 6,
    sunday: 7,
    0: 7
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
    return rawMethods
      .map((method) => {
        if (typeof method === 'string') return method;
        return method.name || method.label || method.title || method.type;
      })
      .filter(Boolean);
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

const getStoreStatus = (store) => {
  const isOpen = Boolean(store?.opening_status?.is_open ?? store?.is_open);

  const message =
    store?.opening_status?.message ||
    store?.status_message ||
    (isOpen ? 'Aberto agora' : 'Fechado');

  return {
    isOpen,
    message: isOpen ? 'Aberto agora' : message
  };
};

export default function StoreAboutModal({ store, deliveryFee, isOpen, onClose }) {
  if (!isOpen || !store) return null;

  const scheduleEntries = getStoreScheduleEntries(store);
  const paymentMethods = getPaymentMethods(store);
  const { isOpen: isStoreOpen, message: statusMessage } = getStoreStatus(store);
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
    <div className="fixed inset-0 z-[60] flex items-center justify-center px-4 py-6">
      <div
        className="absolute inset-0 bg-black/40"
        onClick={onClose}
      />

      <div className="relative flex max-h-[82vh] w-full max-w-md flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
        <header className="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">
          <div className="min-w-0">
            <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">
              Sobre a loja
            </p>

            <h2 className="mt-0.5 truncate text-lg font-black text-slate-950">
              {store.name}
            </h2>

            <div className="mt-1.5 flex items-center gap-2">
              <span
                className={`h-2 w-2 rounded-full ${
                  isStoreOpen ? 'bg-emerald-500' : 'bg-slate-400'
                }`}
              />

              <span
                className={`text-xs font-bold ${
                  isStoreOpen ? 'text-emerald-700' : 'text-slate-500'
                }`}
              >
                {statusMessage}
              </span>
            </div>
          </div>

          <button
            onClick={onClose}
            className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition-colors hover:bg-slate-200 hover:text-slate-900"
            aria-label="Fechar"
          >
            <X className="h-4 w-4" />
          </button>
        </header>

        <div className="overflow-y-auto px-5 py-4">
          {store.description && (
            <section className="border-b border-slate-100 pb-3">
              <div className="mb-1.5 flex items-center gap-2">
                <Info className="h-4 w-4 text-[var(--store-primary)]" />
                <h3 className="text-sm font-black text-slate-900">
                  Descrição
                </h3>
              </div>

              <p className="text-sm font-medium leading-relaxed text-slate-600">
                {store.description}
              </p>
            </section>
          )}

          <section className="border-b border-slate-100 py-3">
            <div className="space-y-2.5">
              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600">
                  <Bike className="h-4 w-4 text-slate-400" />
                  Entrega
                </span>

                <span className="text-sm font-black text-slate-950">
                  {deliveryFee === 0
                    ? 'Grátis'
                    : `R$ ${Number(deliveryFee || 0).toFixed(2).replace('.', ',')}`}
                </span>
              </div>

              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600">
                  <Clock className="h-4 w-4 text-slate-400" />
                  Hoje
                </span>

                <span className="max-w-[170px] truncate text-right text-sm font-black text-slate-950">
                  {todayEntry?.hours || 'Não informado'}
                </span>
              </div>

              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600">
                  <CreditCard className="h-4 w-4 text-slate-400" />
                  Pagamento
                </span>

                <span className="text-sm font-black text-slate-950">
                  {paymentMethods.length} forma(s)
                </span>
              </div>
            </div>
          </section>

          {!isStoreOpen && nextOpening && (
            <section className="border-b border-slate-100 py-3">
              <div className="rounded-2xl bg-amber-50 px-3 py-2.5">
                <h3 className="text-sm font-black text-amber-900">
                  Próxima abertura
                </h3>

                <p className="mt-0.5 text-sm font-semibold text-amber-800">
                  Abre {nextOpening.day_label} às {nextOpening.time}
                </p>
              </div>
            </section>
          )}

          {store.address && (
            <section className="border-b border-slate-100 py-3">
              <div className="mb-1.5 flex items-center gap-2">
                <MapPin className="h-4 w-4 text-[var(--store-primary)]" />
                <h3 className="text-sm font-black text-slate-900">
                  Endereço
                </h3>
              </div>

              <p className="text-sm font-medium leading-relaxed text-slate-600">
                {store.address}
              </p>
            </section>
          )}

          <section className="border-b border-slate-100 py-3">
            <div className="mb-2 flex items-center gap-2">
              <CreditCard className="h-4 w-4 text-[var(--store-primary)]" />
              <h3 className="text-sm font-black text-slate-900">
                Pagamento
              </h3>
            </div>

            <div className="space-y-2">
              {paymentMethods.map((method) => {
                const Icon = getPaymentIcon(method);

                return (
                  <div
                    key={method}
                    className="flex items-center justify-between gap-3"
                  >
                    <span className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                      <Icon className="h-4 w-4 text-slate-400" />
                      {formatPaymentLabel(method)}
                    </span>

                    <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                  </div>
                );
              })}
            </div>
          </section>

          <section className="py-3">
            <div className="mb-2 flex items-center gap-2">
              <CalendarDays className="h-4 w-4 text-[var(--store-primary)]" />
              <h3 className="text-sm font-black text-slate-900">
                Horários
              </h3>
            </div>

            {scheduleEntries.length > 0 ? (
              <div className="space-y-2">
                {scheduleEntries.map((entry) => (
                  <div
                    key={entry.key}
                    className="flex items-center justify-between gap-3"
                  >
                    <span
                      className={`text-sm font-semibold ${
                        entry.isClosed ? 'text-slate-400' : 'text-slate-700'
                      }`}
                    >
                      {entry.day}
                    </span>

                    <span
                      className={`text-sm font-bold ${
                        entry.isClosed ? 'text-slate-400' : 'text-slate-950'
                      }`}
                    >
                      {entry.hours}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm font-medium text-slate-400">
                Horários não informados.
              </p>
            )}
          </section>
        </div>
      </div>
    </div>
  );
}