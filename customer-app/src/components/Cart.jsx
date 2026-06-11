import React, { useMemo, useRef } from 'react';
import {
  ShoppingBag,
  Minus,
  Plus,
  Ticket,
  ChevronRight,
  ChevronLeft,
  X,
  Sparkles,
  Trash2
} from 'lucide-react';

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
  onCheckout,
  highlightProducts = [],
  onHighlightProductClick,
  cartHighlightTitle = 'Peça também'
}) {
  const HIGHLIGHT_HIDE_UNIQUE_PRODUCTS = 5;
  const isFullscreen = variant === 'fullscreen';
  const hasItems = cart.length > 0;
  const carouselRef = useRef(null);

  const normalizeProductId = (value) => {
    const id = value?.id ?? value?.product_id ?? value;

    if (id === null || id === undefined || id === '') {
      return null;
    }

    return String(id);
  };

  const normalizeProductName = (name) =>
    String(name || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');

  const cartProductIds = useMemo(
    () => new Set(cart.map(item => normalizeProductId(item)).filter(Boolean)),
    [cart]
  );

  const cartProductNames = useMemo(
    () => new Set(cart.map(item => normalizeProductName(item.name)).filter(Boolean)),
    [cart]
  );

  const uniqueProductCount = cartProductIds.size;

  const visibleHighlights = useMemo(() => {
    return highlightProducts.filter(product => {
      if (product?.is_active === false) {
        return false;
      }

      if (cartProductIds.has(normalizeProductId(product))) {
        return false;
      }

      const highlightName = normalizeProductName(product.name);

      if (highlightName && cartProductNames.has(highlightName)) {
        return false;
      }

      return true;
    });
  }, [highlightProducts, cartProductIds, cartProductNames]);

  const shouldShowHighlights = visibleHighlights.length > 0
    && uniqueProductCount < HIGHLIGHT_HIDE_UNIQUE_PRODUCTS;

  const scrollCarousel = (direction) => {
    if (!carouselRef.current) return;

    const step = isFullscreen ? 96 : 220;

    carouselRef.current.scrollBy({
      left: direction * step,
      behavior: 'smooth'
    });
  };

  const renderHighlights = (compact = false) => {
    if (visibleHighlights.length === 0) return null;

    return (
      <div
        className={
          compact
            ? 'rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm'
            : 'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white p-3.5 shadow-sm'
        }
      >
        <div className={`flex items-center justify-between gap-2 ${compact ? 'mb-2' : 'mb-3'}`}>
          <div className="min-w-0">
            <p className={`font-black uppercase tracking-wide text-[var(--store-primary)] ${compact ? 'text-[10px]' : 'text-[11px]'}`}>
              {cartHighlightTitle}
            </p>
            {!compact && (
              <p className="text-sm font-black text-slate-900">
                {hasItems ? 'Complete seu pedido' : 'Sugestões para você'}
              </p>
            )}
          </div>

          {visibleHighlights.length > 2 && (
            <div className="flex items-center gap-1 shrink-0">
              <button
                type="button"
                onClick={() => scrollCarousel(-1)}
                className={`rounded-lg border border-slate-200 bg-white text-slate-500 hover:text-[var(--store-primary)] flex items-center justify-center ${compact ? 'h-7 w-7' : 'h-8 w-8 rounded-xl'}`}
                aria-label="Anterior"
              >
                <ChevronLeft size={compact ? 14 : 16} />
              </button>
              <button
                type="button"
                onClick={() => scrollCarousel(1)}
                className={`rounded-lg border border-slate-200 bg-white text-slate-500 hover:text-[var(--store-primary)] flex items-center justify-center ${compact ? 'h-7 w-7' : 'h-8 w-8 rounded-xl'}`}
                aria-label="Próximo"
              >
                <ChevronRight size={compact ? 14 : 16} />
              </button>
            </div>
          )}
        </div>

        <div
          ref={carouselRef}
          className="flex gap-2 overflow-x-auto pb-0.5 snap-x snap-mandatory [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
          {visibleHighlights.map(product => (
            <button
              key={product.id}
              type="button"
              onClick={() => onHighlightProductClick?.(product)}
              className={
                compact
                  ? 'snap-start shrink-0 w-[84px] rounded-xl border border-slate-200 bg-slate-50 p-1.5 text-left hover:border-[var(--store-primary)]/40 transition-all'
                  : 'snap-start shrink-0 w-[132px] sm:w-[148px] rounded-2xl border border-slate-200 bg-white p-2.5 text-left hover:border-[var(--store-primary)]/40 hover:shadow-md transition-all'
              }
            >
              <div className={`w-full rounded-lg overflow-hidden bg-slate-100 mb-1.5 ${compact ? 'h-12' : 'h-20 rounded-xl mb-2'}`}>
                {product.image ? (
                  <img src={product.image} alt={product.name} className="w-full h-full object-cover" />
                ) : (
                  <div className="w-full h-full flex items-center justify-center text-slate-300 text-[9px] font-bold">
                    Sem foto
                  </div>
                )}
              </div>
              <p className={`font-black text-slate-900 line-clamp-2 leading-snug ${compact ? 'text-[10px] min-h-[1.75rem]' : 'text-xs min-h-[2rem]'}`}>
                {product.name}
              </p>
              <p className={`font-black text-[var(--store-primary)] ${compact ? 'text-[10px] mt-0.5' : 'text-xs mt-1'}`}>
                {product.price_formatted || Number(product.price || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
              </p>
              {!compact && (
                <span className="mt-2 inline-flex items-center gap-1 rounded-lg bg-[var(--store-primary)]/10 px-2 py-1 text-[10px] font-black uppercase text-[var(--store-primary)]">
                  <Plus size={12} />
                  Adicionar
                </span>
              )}
            </button>
          ))}
        </div>
      </div>
    );
  };

  return (
    <div className={`flex flex-col overflow-hidden bg-white ${isFullscreen ? 'h-full' : 'h-auto rounded-3xl border border-slate-100 shadow-[0_18px_60px_rgba(15,23,42,0.08)]'}`}>
      <div className="relative overflow-hidden border-b border-slate-100 bg-white px-5 py-5 shrink-0">
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
                className="inline-flex items-center gap-1.5 rounded-full border border-[var(--store-primary)]/20 bg-[var(--store-primary)]/10 px-3 py-1.5 text-[11px] font-black text-[var(--store-primary)] transition-colors hover:bg-[var(--store-primary)]/15"
              >
                <Trash2 className="h-3.5 w-3.5" />
                Esvaziar
              </button>
            )}
          </div>
        </div>
      </div>

      <div className={`overflow-y-auto bg-slate-50/50 px-4 py-4 ${isFullscreen ? 'flex-1 min-h-0' : 'max-h-[410px] lg:max-h-[360px]'}`}>
        {!hasItems ? (
          <div className="h-52 flex flex-col items-center justify-center text-center text-slate-400 rounded-3xl border border-dashed border-slate-200 bg-white">
            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-3xl bg-slate-50 text-slate-300">
              <ShoppingBag className="w-8 h-8" />
            </div>
            <p className="font-black text-sm text-slate-700">Sua sacola está vazia</p>
            <p className="text-xs font-semibold text-slate-400 mt-1 max-w-[220px]">
              Toque em um produto do cardápio para montar seu pedido.
            </p>
          </div>
        ) : (
          <>
            {cart.map(item => (
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
            ))}

          </>
        )}
      </div>

      <div className="px-4 py-4 border-t border-slate-100 bg-white space-y-4 shrink-0">
        {!isFullscreen && shouldShowHighlights && renderHighlights(false)}

        {isFullscreen && shouldShowHighlights && renderHighlights(true)}

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
              <p className="text-xs font-bold text-[var(--store-primary)] mt-2">{couponError}</p>
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
