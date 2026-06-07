import React from 'react';
import { ShoppingBag, Minus, Plus, Ticket, ChevronRight, X } from 'lucide-react';

export default function Cart({
  cart,
  cartCount,
  subtotal,
  deliveryFee,
  discountAmount = 0,
  cartTotal,
  coupon,
  setCoupon,
  appliedCoupon,
  couponLoading = false,
  couponError = '',
  onApplyCoupon,
  onRemoveCoupon,
  updateCartQuantity,
  variant = 'panel',
  onEditItem,
  onCheckout
}) {
  const isFullscreen = variant === 'fullscreen';
  const hasItems = cart.length > 0;

  return (
    <div className={`flex flex-col bg-white overflow-hidden ${isFullscreen ? 'h-full' : 'h-auto rounded-2xl border border-gray-100 shadow-sm'}`}>
      <div className="px-4 py-4 border-b border-gray-100">
        <h2 className="font-black text-slate-900 tracking-tight flex items-center gap-2">
          <ShoppingBag className="w-5 h-5 text-slate-700" />
          Sua sacola
        </h2>
        <p className="text-xs font-medium text-slate-400 mt-1">
          {cartCount === 0 ? 'Nenhum item adicionado' : `${cartCount} item(ns) selecionado(s)`}
        </p>
      </div>

      <div className={`overflow-y-auto px-4 py-2 divide-y divide-gray-100 ${isFullscreen ? 'flex-1 max-h-none' : 'max-h-[380px] lg:max-h-[320px]'}`}>
        {!hasItems ? (
          <div className="h-56 flex flex-col items-center justify-center text-center text-slate-400">
            <ShoppingBag className="w-9 h-9 text-slate-300 mb-3" />
            <p className="font-bold text-sm text-slate-500">Sua sacola está vazia</p>
            <p className="text-xs font-medium text-slate-400 mt-1 max-w-[220px]">
              Adicione um item para continuar.
            </p>
          </div>
        ) : (
          cart.map(item => (
            <div
              key={item.cart_item_id}
              className="py-4 flex gap-3 cursor-pointer hover:bg-slate-50/60 transition-colors px-2 rounded-xl -mx-2 group"
              onClick={() => onEditItem?.(item)}
            >
              {item.image && (
                <div className="w-14 h-14 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100">
                  <img src={item.image} alt={item.name} className="w-full h-full object-cover" />
                </div>
              )}

              <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h4 className="font-bold text-sm text-slate-900 truncate">{item.name}</h4>
                    {item.selected_options?.length > 0 && (
                      <p className="text-[11px] text-slate-400 mt-1 line-clamp-2 leading-relaxed">
                        + {item.selected_options.map(o => o.name).join(', ')}
                      </p>
                    )}
                  </div>

                  <p className="text-sm font-black text-slate-900 whitespace-nowrap">
                    {(item.price * item.quantity).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                  </p>
                </div>

                {item.observation && (
                  <p className="text-[11px] text-slate-500 bg-slate-50 rounded-lg px-2.5 py-1.5 mt-2 font-medium border border-slate-100 italic break-words">
                    <span className="font-bold text-slate-400 not-italic">Obs: </span>
                    "{item.observation}"
                  </p>
                )}

                <div className="flex items-center justify-between mt-3">
                  <div className="inline-flex items-center gap-3">
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        updateCartQuantity(item.cart_item_id, false);
                      }}
                      className="text-slate-400 hover:text-red-600 transition-colors"
                    >
                      <Minus className="w-4 h-4" />
                    </button>

                    <span className="text-sm font-black text-slate-800 min-w-4 text-center">
                      {item.quantity}
                    </span>

                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        updateCartQuantity(item.cart_item_id, true);
                      }}
                      className="text-slate-400 hover:text-red-600 transition-colors"
                    >
                      <Plus className="w-4 h-4" />
                    </button>
                  </div>

                  <span className="text-[11px] text-slate-400">
                    Unit. {Number(item.price).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                  </span>
                </div>
              </div>
            </div>
          ))
        )}
      </div>

      <div className="px-4 py-4 border-t border-gray-100 space-y-4">
        {appliedCoupon ? (
          <div className="flex items-center justify-between bg-emerald-50 border border-emerald-100 rounded-xl p-3">
            <div className="flex items-center gap-2">
              <Ticket className="w-4 h-4 text-emerald-600" />
              <div>
                <p className="text-sm font-black text-emerald-700">{appliedCoupon.code}</p>
                <p className="text-xs font-bold text-emerald-600">
                  - {discountAmount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                </p>
              </div>
            </div>

            <button onClick={onRemoveCoupon} className="p-1.5 rounded-lg text-emerald-700 hover:bg-white hover:text-red-600 transition-colors">
              <X className="w-4 h-4" />
            </button>
          </div>
        ) : (
          <div>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Ticket className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Cupom"
                  value={coupon}
                  onChange={(e) => setCoupon(e.target.value.toUpperCase())}
                  disabled={!hasItems}
                  className="w-full pl-9 pr-3 py-2.5 bg-white border border-gray-200 rounded-lg text-xs font-medium focus:outline-hidden focus:border-slate-400 transition-colors disabled:bg-gray-50 disabled:text-gray-400 disabled:cursor-not-allowed uppercase"
                />
              </div>

              <button
                onClick={onApplyCoupon}
                disabled={!hasItems || couponLoading}
                className="bg-[var(--store-primary)] text-white text-xs font-bold px-4 py-2.5 rounded-lg hover:brightness-90 transition-colors disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed"
              >
                {couponLoading ? '...' : 'Aplicar'}
              </button>
            </div>

            {couponError && (
              <p className="text-xs font-bold text-red-600 mt-2">{couponError}</p>
            )}
          </div>
        )}

        <div className="space-y-2 text-sm">
          <div className="flex justify-between text-slate-500">
            <span>Subtotal</span>
            <span>{subtotal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</span>
          </div>

          <div className="flex justify-between text-slate-500">
            <span>Entrega</span>
            <span>{deliveryFee === 0 ? 'Grátis' : deliveryFee.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</span>
          </div>

          {discountAmount > 0 && (
            <div className="flex justify-between text-emerald-600 font-bold">
              <span>Desconto</span>
              <span>- {discountAmount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</span>
            </div>
          )}
        </div>

        <div className="pt-3 border-t border-gray-100">
          <div className="flex justify-between items-end mb-3">
            <span className="text-sm font-bold text-slate-900">Total</span>
            <span className="text-xl font-black text-slate-900">
              {(hasItems ? cartTotal : 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
            </span>
          </div>

          <button
            disabled={!hasItems}
            onClick={onCheckout}
            className="w-full bg-[var(--store-primary)] text-white py-3 rounded-lg font-black text-sm hover:brightness-90 transition-all flex items-center justify-center gap-1 uppercase tracking-wide disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed"
          >
            Finalizar pedido <ChevronRight className="w-4 h-4 stroke-[3]" />
          </button>
        </div>
      </div>
    </div>
  );
}