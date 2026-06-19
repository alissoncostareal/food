import { CheckCircle, Smartphone, X } from 'lucide-react';
import { resolveWhatsAppUrl } from './whatsapp';

export default function PixPaidBanner({ session, store, onClose, onOpenCheckout }) {
  if (!session || session.status !== 'paid') {
    return null;
  }

  const order = session.order || {};
  const whatsappUrl = resolveWhatsAppUrl(
    {
      whatsapp_url: session.whatsappUrl || order.whatsapp_url,
      store_whatsapp_number: order.store_whatsapp_number,
    },
    order,
    store
  );

  return (
    <div className="fixed inset-x-0 bottom-0 z-[85] p-4 pointer-events-none">
      <div className="pointer-events-auto mx-auto max-w-xl rounded-2xl border border-emerald-200 bg-white p-4 shadow-2xl shadow-emerald-100/80">
        <div className="flex items-start gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
            <CheckCircle size={22} />
          </div>

          <div className="min-w-0 flex-1">
            <p className="text-sm font-black text-slate-900">Pagamento Pix confirmado!</p>
            <p className="mt-1 text-xs font-semibold text-slate-500">
              Pedido #{order.display_code || order.id} enviado para a loja.
            </p>

            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
              {whatsappUrl ? (
                <a
                  href={whatsappUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-black text-white hover:bg-emerald-700"
                >
                  <Smartphone size={16} />
                  Enviar no WhatsApp
                </a>
              ) : null}

              <button
                type="button"
                onClick={onOpenCheckout}
                className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black text-slate-600 hover:bg-slate-50"
              >
                Ver pedido
              </button>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Fechar"
          >
            <X size={16} />
          </button>
        </div>
      </div>
    </div>
  );
}
