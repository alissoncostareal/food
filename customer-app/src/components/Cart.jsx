import React from 'react';
import { ShoppingBag, Minus, Plus, Ticket, ChevronRight, X, Sparkles, Trash2 } from 'lucide-react';

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
  couponsEnabled = true,
  updateCartQuantity,
  variant = 'panel',
  onEditItem,
  onClearCart,
  onCheckout
}) {
  const isFullscreen = variant === 'fullscreen';
  const hasItems = cart.length > 0;

  return (
    <div className={`flex flex-col overflow-hidden bg-white ${isFullscreen ? 'h-full' : 'h-auto rounded-3xl border border-slate-100 shadow-[0_18px_60px_rgba(15,23,42,0.08)]'}`}>
      <div className="relative overflow-hidden border-b border-slate-100 bg-white px-5 py-5">
        <div className="absolute right-0 top-0 h-24 w-24 rounded-bl-full bg-[var(--store-primary)]/10" />

        <div className="relative flex items-start justify-between gap-4">
          <div>
            <h2 className="font-black text-slate-950 tracking-tight flex items-center gap-2">
              <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[var(--store-primary)] text-white shadow-lg shadow-[color-mix(in_srgb,var(--store-primary)_22%,transparent)]">
                <ShoppingBag className="w-5 h-5" />
              </span>
              Sua sacola
            </h2>
            <p className="text-xs font-bold text-slate-500 mt-2">
              {cartCount === 0 ? 'Escolha seus favoritos para começar' : `${cartCount} item(ns) selecionado(s)`}
            </p>
          </div>

          <div className="flex flex-col items-end gap-2">
            {hasItems && (
              <span className="rounded-full border border-slate-100 bg-slate-50 px-3 py-1 text-[11px] font-black text-slate-600">
                {cartTotal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
              </span>
            )}

            {hasItems && (
              <button
                type="button"
                onClick={onClearCart}
                className="inline-flex items-center gap-1.5 rounded-full border border-red-100 bg-red-50 px-3 py-1.5 text-[11px] font-black text-red-600 transition-colors hover:bg-red-100"
              >
                <Trash2 className="h-3.5 w-3.5" />
                Esvaziar
              </button>
            )}
          </div>
        </div>
      </div>

      <div className={`overflow-y-auto bg-slate-50/50 px-4 py-4 ${isFullscreen ? 'flex-1 max-h-none' : 'max-h-[410px] lg:max-h-[360px]'}`}>
        {!hasItems ? (
          <div className="h-64 flex flex-col items-center justify-center text-center text-slate-400 rounded-3xl border border-dashed border-slate-200 bg-white">
            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-3xl bg-slate-50 text-slate-300">
              <ShoppingBag className="w-8 h-8" />
            </div>
            <p className="font-black text-sm text-slate-700">Sua sacola está vazia</p>
            <p className="text-xs font-semibold text-slate-400 mt-1 max-w-[220px]">
              Toque em um produto do cardápio para montar seu pedido.
            </p>
          </div>
        ) : (
          cart.map(item => (
            <div
              key={item.cart_item_id}
              className="mb-3 flex gap-3 cursor-pointer rounded-3xl border border-slate-100 bg-white p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-[var(--store-primary)]/25 hover:shadow-md group"
              onClick={() => onEditItem?.(item)}
            >
              {item.image && (
                <div className="w-16 h-16 rounded-2xl overflow-hidden flex-shrink-0 bg-slate-100 ring-1 ring-slate-100">
                  <img src={item.image} alt={item.name} className="w-full h-full object-cover" />
                </div>
              )}

              <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h4 className="font-black text-sm text-slate-900 truncate">{item.name}</h4>
                    {item.selected_options?.length > 0 && (
                      <p className="text-[11px] font-semibold text-slate-500 mt-1 line-clamp-2 leading-relaxed">
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
                  <div className="inline-flex items-center gap-1 rounded-2xl border border-slate-100 bg-slate-50 p-1">
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        updateCartQuantity(item.cart_item_id, false);
                      }}
                      className="flex h-8 w-8 items-center justify-center rounded-xl bg-white text-slate-500 shadow-sm transition-colors hover:text-[var(--store-primary)]"
                      aria-label="Diminuir quantidade"
                    >
                      <Minus className="w-4 h-4" />
                    </button>

                    <span className="text-sm font-black text-slate-800 min-w-7 text-center">
                      {item.quantity}
                    </span>

                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        updateCartQuantity(item.cart_item_id, true);
                      }}
                      className="flex h-8 w-8 items-center justify-center rounded-xl bg-white text-[var(--store-primary)] shadow-sm transition-colors hover:brightness-90"
                      aria-label="Aumentar quantidade"
                    >
                      <Plus className="w-4 h-4" />
                    </button>
                  </div>

                  <span className="text-[11px] font-bold text-slate-400">
                    Unit. {Number(item.price).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                  </span>
                </div>
              </div>
            </div>
          ))
        )}
      </div>

      <div className="px-4 py-4 border-t border-slate-100 bg-white space-y-4">
        {couponsEnabled && appliedCoupon ? (
          <div className="flex items-center justify-between bg-emerald-50 border border-emerald-100 rounded-2xl p-3">
            <div className="flex items-center gap-2">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-emerald-600">
                <Ticket className="w-4 h-4" />
              </div>
              <div>
                <p className="text-sm font-black text-emerald-700">{appliedCoupon.code}</p>
                <p className="text-xs font-bold text-emerald-600">
                  - {discountAmount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                </p>
              </div>
            </div>

            <button onClick={onRemoveCoupon} className="p-1.5 rounded-lg text-emerald-700 hover:bg-white hover:text-[var(--store-primary)] transition-colors">
              <X className="w-4 h-4" />
            </button>
          </div>
        ) : couponsEnabled ? (
          <div className="rounded-2xl border border-slate-100 bg-slate-50 p-3">
            <div className="mb-2 flex items-center gap-2 text-[11px] font-black uppercase tracking-wide text-slate-500">
              <Sparkles className="h-3.5 w-3.5 text-[var(--store-primary)]" />
              Tem cupom?
            </div>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Ticket className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Cupom"
                  value={coupon}
                  onChange={(e) => setCoupon(e.target.value.toUpperCase())}
                  disabled={!hasItems}
                  className="w-full pl-9 pr-3 py-3 bg-white border border-slate-200 rounded-xl text-xs font-black focus:outline-hidden focus:border-[var(--store-primary)] transition-colors disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed uppercase"
                />
              </div>

              <button
                onClick={onApplyCoupon}
                disabled={!hasItems || couponLoading}
                className="bg-slate-900 text-white text-xs font-black px-4 py-3 rounded-xl hover:bg-[var(--store-primary)] transition-colors disabled:bg-slate-200 disabled:text-slate-400 disabled:cursor-not-allowed"
              >
                {couponLoading ? '...' : 'Aplicar'}
              </button>
            </div>

            {couponError && (
              <p className="text-xs font-bold text-red-600 mt-2">{couponError}</p>
            )}
          </div>
        ) : null}

        <div className="space-y-2 rounded-2xl bg-slate-50 px-4 py-3 text-sm">
          <div className="flex justify-between text-slate-500 font-semibold">
            <span>Subtotal</span>
            <span>{subtotal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</span>
          </div>

          <div className="flex justify-between text-slate-500 font-semibold">
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

        <div className="pt-2">
          <div className="flex justify-between items-end mb-3">
            <span className="text-sm font-black text-slate-900">Total</span>
            <span className="text-2xl font-black text-slate-950">
              {(hasItems ? cartTotal : 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
            </span>
          </div>

          <button
            disabled={!hasItems}
            onClick={onCheckout}
            className="w-full bg-[var(--store-primary)] text-white py-4 rounded-2xl font-black text-sm hover:brightness-90 transition-all flex items-center justify-center gap-1 uppercase tracking-wide shadow-lg shadow-[color-mix(in_srgb,var(--store-primary)_22%,transparent)] disabled:bg-slate-200 disabled:text-slate-400 disabled:shadow-none disabled:cursor-not-allowed"
          >
            Finalizar pedido <ChevronRight className="w-4 h-4 stroke-[3]" />
          </button>
        </div>
      </div>
    </div>
  );
}
