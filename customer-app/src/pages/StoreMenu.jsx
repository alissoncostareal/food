// src/pages/StoreMenu.jsx
import React, { useState, useEffect, useLayoutEffect, useMemo } from 'react';
import { useParams } from 'react-router-dom';
import api from '../services/api';
import Cart from '../components/Cart';
import StoreTopMenu from '../components/StoreTopMenu.jsx';
import Checkout from '../components/Checkout.jsx';
import { calculateCouponDiscount } from '../utils/coupon';
import {
  applyStoreTheme,
  readStoreThemeCache,
  writeStoreThemeCache,
  storeThemeStyle
} from '../utils/storeTheme';
import CustomerLoadingPanel from '../components/CustomerLoadingPanel';
import {
  readCartFromStorage,
  writeCartToStorage,
  readLocalCustomer,
  persistCustomerSession,
  clearCustomerSession
} from '../utils/customerSession';
import {
  Plus,
  Minus,
  X,
  Info,
  ShoppingBag,
  ArrowLeft
} from 'lucide-react';

const getOptionImageUrl = (item) => {
  if (typeof item.image_url === 'string' && item.image_url) return item.image_url;
  if (typeof item.image === 'string' && item.image) return item.image;
  if (typeof item.image_path === 'string' && item.image_path) return item.image_path;
  if (typeof item.photo === 'string' && item.photo) return item.photo;
  if (typeof item.image_url?.url === 'string' && item.image_url.url) return item.image_url.url;

  return null;
};

const updateFavicon = (url) => {
  if (!url) return;

  let link = document.querySelector("link[rel*='icon']");

  if (!link) {
    link = document.createElement('link');
    link.rel = 'icon';
    document.head.appendChild(link);
  }

  link.href = `${url}?t=${Date.now()}`;
};

const getSelectedCount = (selectedValue) => {
  if (!selectedValue) return 0;
  return Array.isArray(selectedValue) ? selectedValue.length : 1;
};

const isProductOutOfStock = (product) => {
  return Boolean(product?.manage_stock) && Number(product?.stock_quantity || 0) <= 0;
};

const isProductPurchasable = (product) => {
  return Boolean(product?.is_active) && !isProductOutOfStock(product);
};

const getProductUnavailableReason = (product) => {
  if (!product?.is_active) return 'Indisponível';
  if (isProductOutOfStock(product)) return 'Fora de estoque';

  return null;
};

export default function StoreMenu({ 
  setGlobalStore,
  onLogin, 
  onOpenOrders, 
  onOpenSettings
 }) {
  const { store_slug } = useParams();

  const [store, setStore] = useState(null);
  const [deliverySummary, setDeliverySummary] = useState(null);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);

  const [isCheckoutOpen, setIsCheckoutOpen] = useState(false);

  const [cart, setCart] = useState(() => readCartFromStorage(store_slug));

  const [isCartOpen, setIsCartOpen] = useState(false);
  const [activeCategory, setActiveCategory] = useState(null);

  const [selectedProduct, setSelectedProduct] = useState(null);
  const [selectedOptions, setSelectedOptions] = useState({});
  const [productQuantity, setProductQuantity] = useState(1);
  const [optionErrors, setOptionErrors] = useState({});
  const [observation, setObservation] = useState('');
  const [editingCartItemId, setEditingCartItemId] = useState(null);
  const [coupon, setCoupon] = useState('');
  const [appliedCoupon, setAppliedCoupon] = useState(null);
  const [couponLoading, setCouponLoading] = useState(false);
  const [couponError, setCouponError] = useState('');
  const [cartHighlights, setCartHighlights] = useState([]);

  const cartHighlightProducts = useMemo(() => {
    const fromApi = Array.isArray(cartHighlights) ? cartHighlights : [];
    const fromCategories = categories
      .flatMap(category => category.products || [])
      .filter(product => product.show_in_cart);

    const merged = fromApi.length > 0 ? fromApi : fromCategories;

    return merged
      .filter(isProductPurchasable)
      .sort((a, b) => {
        const orderA = Number(a.cart_highlight_order ?? 999);
        const orderB = Number(b.cart_highlight_order ?? 999);

        if (orderA !== orderB) return orderA - orderB;

        return String(a.name || '').localeCompare(String(b.name || ''));
      })
      .slice(0, 12);
  }, [cartHighlights, categories]);

  useEffect(() => {
    if (store_slug) {
      writeCartToStorage(store_slug, cart);
    }
  }, [cart, store_slug]);

  const [currentUser, setCurrentUser] = useState(() => readLocalCustomer());

  const [hasAuthToken, setHasAuthToken] = useState(() => Boolean(localStorage.getItem('token')));

  useEffect(() => {
    const handleSessionUpdate = () => {
      setCurrentUser(readLocalCustomer());
      setHasAuthToken(Boolean(localStorage.getItem('token')));
    };

    window.addEventListener('customer-session-updated', handleSessionUpdate);
    return () => window.removeEventListener('customer-session-updated', handleSessionUpdate);
  }, []);

  const cachedStoreTheme = useMemo(
    () => (store_slug ? readStoreThemeCache(store_slug) : null),
    [store_slug]
  );

  useLayoutEffect(() => {
    if (cachedStoreTheme) {
      applyStoreTheme(cachedStoreTheme);
    }
  }, [cachedStoreTheme]);

  useEffect(() => {
    async function fetchStoreData() {
      try {
        setLoading(true);
        const response = await api.get(`/stores/${store_slug}`);
        const {
          store,
          categories: apiCategories,
          cart_highlights: apiCartHighlights,
          is_open,
          status_message,
          opening_status,
          next_opening,
          delivery_summary
        } = response.data;

        setCartHighlights(Array.isArray(apiCartHighlights) ? apiCartHighlights : (apiCartHighlights?.data || []));
        setDeliverySummary(delivery_summary || null);

        if (store) {
          setStore({ ...store, is_open, status_message, opening_status, next_opening, delivery_summary });
          applyStoreTheme(store);
          writeStoreThemeCache(store_slug, store);
          const storeIcon = store.logo_url || store.image || store.photo || store.logo_path;
          if (storeIcon) {
            updateFavicon(storeIcon);
          }
          document.title = `${store.name} | PartiuMenu`;

          const activeCategories = (apiCategories || []).filter(
            cat => cat.products && cat.products.length > 0
          );

          setCategories(activeCategories);

          const purchasableProductIds = new Set(
            activeCategories
              .flatMap(category => category.products || [])
              .filter(isProductPurchasable)
              .map(product => product.id)
          );

          setCart(currentCart => currentCart.filter(item => purchasableProductIds.has(item.id)));

          if (typeof setGlobalStore === 'function') {
            setGlobalStore({
              name: store.name,
              color: store.primary_color,
              secondaryColor: store.secondary_color
            });
          }
          if (activeCategories.length > 0) {
            setActiveCategory(activeCategories[0].id);
          }
        }
      } catch (error) {
        console.error('Erro ao buscar dados da loja:', error);
      } finally {
        setLoading(false);
      }
    }

    if (store_slug) {
      fetchStoreData();
    }

    return () => {
      if (typeof setGlobalStore === 'function') {
        setGlobalStore({ name: '', color: '' });
      }
      updateFavicon('/favicon.png');
      document.title = 'PartiuMenu';
    };
  }, [store_slug, setGlobalStore]);

  const scrollToCategory = (categoryId) => {
    setActiveCategory(categoryId);
    const element = document.getElementById(`category-section-${categoryId}`);

    if (element) {
      const offset = window.innerWidth < 768 ? 70 : 80;
      const bodyRect = document.body.getBoundingClientRect().top;
      const elementRect = element.getBoundingClientRect().top;
      const elementPosition = elementRect - bodyRect;
      const offsetPosition = elementPosition - offset;

      window.scrollTo({
        top: offsetPosition,
        behavior: 'smooth'
      });
    }
  };

  const handleProductClick = (product) => {
    if (!isProductPurchasable(product)) return;

    setEditingCartItemId(null);
    setSelectedProduct(product);
    setProductQuantity(1);
    setOptionErrors({});
    setObservation('');

    const initialOptions = {};
    const productGroups = product.option_groups || [];

    productGroups.forEach(group => {
      initialOptions[group.id] = group.max_selected === 1 ? null : [];
    });

    setSelectedOptions(initialOptions);
  };

  const handleOptionSelect = (group, option) => {
    setOptionErrors(prev => {
      const next = { ...prev };
      delete next[group.id];
      return next;
    });

    setSelectedOptions(prev => {
      const currentGroupState = prev[group.id];

      if (group.max_selected === 1) {
        return { ...prev, [group.id]: option };
      }

      const list = Array.isArray(currentGroupState) ? currentGroupState : [];
      const isSelected = list.some(item => item.id === option.id);

      if (isSelected) {
        return { ...prev, [group.id]: list.filter(item => item.id !== option.id) };
      }

      if (list.length >= group.max_selected) {
        return prev;
      }

      return { ...prev, [group.id]: [...list, option] };
    });
  };

  const validateRequiredOptionGroups = () => {
    const errors = {};
    const productGroups = selectedProduct?.option_groups || [];

    productGroups.forEach(group => {
      const minSelected = Number(group.min_selected || 0);
      const selectedCount = getSelectedCount(selectedOptions[group.id]);

      if (minSelected > 0 && selectedCount < minSelected) {
        errors[group.id] = `Selecione pelo menos ${minSelected} opção${minSelected > 1 ? 'ões' : ''}.`;
      }
    });

    setOptionErrors(errors);
    return {
      isValid: Object.keys(errors).length === 0,
      errors
    };
  };

  const handleAddCustomProductToCart = () => {
    if (!selectedProduct) return;

    if (!isProductPurchasable(selectedProduct)) {
      setSelectedProduct(null);
      return;
    }

    const validation = validateRequiredOptionGroups();

    if (!validation.isValid) {
      const firstInvalidGroupId = Object.keys(validation.errors)[0];

      setTimeout(() => {
        const target = document.getElementById(`option-group-${firstInvalidGroupId}`);
        target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }, 0);

      return;
    }

    const flatOptions = [];

    Object.keys(selectedOptions).forEach(groupId => {
      const val = selectedOptions[groupId];

      if (val) {
        if (Array.isArray(val)) {
          flatOptions.push(...val);
        } else {
          flatOptions.push(val);
        }
      }
    });

    const totalOptionsPrice = flatOptions.reduce((acc, opt) => acc + parseFloat(opt.price || 0), 0);
    const finalUnitPrice = parseFloat(selectedProduct.price) + totalOptionsPrice;
    const optionFingerprint = flatOptions.map(o => o.id).sort().join('-');
    const cartItemId = `${selectedProduct.id}-${optionFingerprint || 'plain'}-${btoa(unescape(encodeURIComponent(observation)))}`;

    setCart(currentCart => {
      if (editingCartItemId) {
        return currentCart.map(item =>
          item.cart_item_id === editingCartItemId
            ? {
              ...selectedProduct,
              cart_item_id: cartItemId,
              price: finalUnitPrice,
              selected_options: flatOptions,
              quantity: productQuantity,
              observation: observation
            }
            : item
        );
      }

      const existing = currentCart.find(item => item.cart_item_id === cartItemId);
      if (existing) {
        return currentCart.map(item =>
          item.cart_item_id === cartItemId
            ? { ...item, quantity: item.quantity + productQuantity }
            : item
        );
      }

      return [
        ...currentCart,
        {
          ...selectedProduct,
          cart_item_id: cartItemId,
          price: finalUnitPrice,
          selected_options: flatOptions,
          quantity: productQuantity,
          observation: observation
        }
      ];
    });

    setSelectedProduct(null);
    setOptionErrors({});
    setEditingCartItemId(null);
    setObservation('');
  };

  const updateCartQuantity = (cartItemId, increment) => {
    setCart(currentCart => {
      return currentCart.map(item => {
        if (item.cart_item_id === cartItemId) {
          const newQty = item.quantity + (increment ? 1 : -1);
          return newQty > 0 ? { ...item, quantity: newQty } : null;
        }

        return item;
      }).filter(Boolean);
    });
  };

  const handleEditCartItem = (cartItem) => {
    let originalProduct = null;
    for (const category of categories) {
      const found = category.products.find(p => p.id === cartItem.id);
      if (found) {
        originalProduct = found;
        break;
      }
    }

    if (!originalProduct) return;

    setSelectedProduct(originalProduct);
    setProductQuantity(cartItem.quantity);
    setObservation(cartItem.observation || '');
    setEditingCartItemId(cartItem.cart_item_id);
    setOptionErrors({});

    const restoredOptions = {};
    const productGroups = originalProduct.option_groups || [];

    productGroups.forEach(group => {
      const savedInGroup = cartItem.selected_options.filter(opt =>
        group.items.some(item => item.id === opt.id)
      );

      if (group.max_selected === 1) {
        restoredOptions[group.id] = savedInGroup.length > 0 ? savedInGroup[0] : null;
      } else {
        restoredOptions[group.id] = savedInGroup;
      }
    });

    setSelectedOptions(restoredOptions);
    setIsCartOpen(false);
  };

  const cartCount = cart.reduce((acc, item) => acc + item.quantity, 0);
  const subtotal = cart.reduce((acc, item) => acc + (item.price * item.quantity), 0);
  const deliveryFee = store ? parseFloat(store.delivery_fee || 0) : 0;
  const couponsEnabled = Boolean(store?.plan?.features?.coupons);
  const discountAmount = calculateCouponDiscount(appliedCoupon, subtotal);
  const cartTotal = Math.max(0, subtotal + deliveryFee - discountAmount);

  const handleApplyCoupon = async () => {
    if (!couponsEnabled) {
      setAppliedCoupon(null);
      setCoupon('');
      setCouponError('');
      return;
    }

    if (!coupon.trim()) {
      setCouponError('Informe um cupom.');
      return;
    }

    if (!store?.id) {
      setCouponError('Loja não encontrada.');
      return;
    }

    if (cart.length === 0) {
      setCouponError('Adicione itens antes de aplicar um cupom.');
      return;
    }

    try {
      setCouponLoading(true);
      setCouponError('');

      const { data } = await api.post(`/stores/${store_slug}/coupons/validate`, {
        code: coupon.trim().toUpperCase(),
        subtotal
      });

      setAppliedCoupon({
        ...data.coupon,
        discount_amount: Number(data.coupon.discount_amount || 0),
        value: Number(data.coupon.value || 0),
        min_order_amount: data.coupon.min_order_amount !== null ? Number(data.coupon.min_order_amount) : null,
        max_discount_amount: data.coupon.max_discount_amount !== null ? Number(data.coupon.max_discount_amount) : null
      });
      setCoupon(data.coupon.code);
    } catch (error) {
      setAppliedCoupon(null);
      setCouponError(
        error.response?.data?.message ||
        error.response?.data?.details ||
        'Cupom inválido.'
      );
    } finally {
      setCouponLoading(false);
    }
  };

  const handleRemoveCoupon = () => {
    setAppliedCoupon(null);
    setCoupon('');
    setCouponError('');
  };

  const handleClearCart = () => {
    setCart([]);
    setAppliedCoupon(null);
    setCoupon('');
    setCouponError('');
  };

  useEffect(() => {
    if (!couponsEnabled) {
      setAppliedCoupon(null);
      setCoupon('');
      setCouponError('');
      return;
    }

    if (!appliedCoupon) return;

    if (cart.length === 0) {
      setAppliedCoupon(null);
      setCoupon('');
      setCouponError('');
      return;
    }

    const minOrderAmount = Number(appliedCoupon.min_order_amount || 0);

    if (minOrderAmount > 0 && subtotal < minOrderAmount) {
      setAppliedCoupon(null);
      setCoupon('');
      setCouponError(
        `Cupom removido: pedido mínimo de ${minOrderAmount.toLocaleString('pt-BR', {
          style: 'currency',
          currency: 'BRL'
        })}.`
      );
      return;
    }

    const nextDiscount = calculateCouponDiscount(appliedCoupon, subtotal);

    if (nextDiscount <= 0) {
      setAppliedCoupon(null);
      setCoupon('');
      setCouponError('Cupom removido: ele não se aplica mais a este carrinho.');
    }
  }, [subtotal, cart.length, appliedCoupon, couponsEnabled]);

  if (loading) {
    return (
      <div
        className="min-h-screen bg-[#fafafa] flex flex-col"
        style={storeThemeStyle(cachedStoreTheme)}
      >
        <CustomerLoadingPanel message="Carregando cardápio..." size="lg" className="min-h-screen" />
      </div>
    );
  }

  if (!store || categories.length === 0) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
        <div className="text-center bg-white p-6 rounded-2xl shadow-xs max-w-sm border border-gray-100">
          <Info className="w-8 h-8 text-amber-500 mx-auto mb-2" />
          <p className="text-slate-700 font-bold">Nenhum produto ou loja cadastrada.</p>
        </div>
      </div>
    );
  }

  return (
    <div 
      className="min-h-screen bg-[#fafafa] text-slate-800 antialiased pb-20"
      style={{
        '--store-primary': store?.primary_color || undefined,
        '--store-secondary': store?.secondary_color || undefined
      }}
    >
      <StoreTopMenu
        store={store}
        deliveryFee={deliveryFee}
        deliverySummary={deliverySummary}
        isAuthenticated={hasAuthToken}
        user={currentUser}
        onHome={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
        onLogin={onLogin}
        onOpenOrders={onOpenOrders}
        onOpenSettings={onOpenSettings}
        onLogout={() => {
          clearCustomerSession();
          setHasAuthToken(false);
          setCurrentUser(null);
        }}
      />

      <div className="sticky top-0 bg-white/95 backdrop-blur-md border-b border-slate-100 z-20 shadow-sm">
        <div className="max-w-7xl mx-auto px-4 flex gap-2 overflow-x-auto py-3 no-scrollbar">
          {categories.map((category) => (
            <button
              key={category.id}
              onClick={() => scrollToCategory(category.id)}
              className={`px-4 py-2 rounded-full text-xs font-bold tracking-wide transition-all whitespace-nowrap ${
                activeCategory === category.id
                  ? 'bg-[var(--store-primary)] text-white shadow-sm'
                  : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:ring-slate-300 hover:bg-slate-50'
              }`}
            >
              {category.name}
            </button>
          ))}
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 mt-6 grid grid-cols-1 lg:grid-cols-[1fr_340px] xl:grid-cols-[1fr_380px] gap-8 items-start">
        <div className="space-y-10">
          {categories.map((category) => (
            <div key={category.id} id={`category-section-${category.id}`} className="scroll-mt-28 md:scroll-mt-24">
              <h2 className="text-base font-black text-slate-900 mb-4 tracking-tight">
                {category.name}
              </h2>

              <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                {category.products.map((product) => {
                  const unavailableReason = getProductUnavailableReason(product);
                  const purchasable = !unavailableReason;

                  return (
                    <div
                      key={product.id}
                      className={`bg-white border rounded-2xl overflow-hidden flex flex-col justify-between transition-all group relative ${
                        purchasable
                          ? 'border-slate-100 hover:shadow-md hover:border-slate-200 cursor-pointer'
                          : 'border-slate-200 cursor-not-allowed opacity-75'
                      }`}
                      onClick={() => handleProductClick(product)}
                      aria-disabled={!purchasable}
                    >
                      {product.image && (
                        <div className="w-full h-48 bg-gray-50 border-b border-gray-50 overflow-hidden relative">
                          <img
                            src={product.image}
                            alt={product.name}
                            className={`w-full h-full object-cover transition-transform duration-300 ${
                              purchasable ? 'group-hover:scale-105' : 'brightness-50'
                            }`}
                          />

                          {purchasable ? (
                            <div className="absolute bottom-2 right-2 bg-[var(--store-primary)] text-white p-2 rounded-xl shadow-md">
                              <Plus className="w-4 h-4 stroke-[3]" />
                            </div>
                          ) : (
                            <div className="absolute inset-0 bg-slate-950/45 flex items-center justify-center px-4">
                              <span className="bg-slate-950/80 text-white text-[11px] font-black uppercase tracking-wider px-3 py-2 rounded-full">
                                {unavailableReason}
                              </span>
                            </div>
                          )}
                        </div>
                      )}

                      <div className={`p-4 flex-1 flex flex-col justify-between space-y-2 text-left ${purchasable ? '' : 'bg-slate-100'}`}>
                        <div>
                          <div className="flex items-start justify-between gap-2">
                            <h3 className={`font-extrabold transition-colors text-sm line-clamp-1 text-left ${
                              purchasable
                                ? 'text-slate-900 group-hover:text-[var(--store-primary)]'
                                : 'text-slate-500'
                            }`}>
                              {product.name}
                            </h3>

                            {!purchasable && (
                              <span className="shrink-0 rounded-full bg-slate-800 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-white">
                                Off
                              </span>
                            )}
                          </div>

                          <p className={`text-xs line-clamp-2 leading-relaxed mt-1 text-left ${purchasable ? 'text-slate-400' : 'text-slate-500'}`}>
                            {product.description}
                          </p>
                        </div>

                        <div className="flex items-center justify-between gap-3">
                          <span className={`font-extrabold ${purchasable ? 'text-slate-900' : 'text-slate-500'}`}>
                            {product.price_formatted}
                          </span>

                          {!purchasable && (
                            <span className="text-[10px] font-black uppercase text-slate-500">
                              {unavailableReason}
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </div>

        <div className="hidden lg:block lg:sticky lg:top-[64px] self-start">
          <Cart
            cart={cart}
            cartCount={cartCount}
            subtotal={subtotal}
            deliveryFee={deliveryFee}
            discountAmount={discountAmount}
            cartTotal={cartTotal}
            coupon={coupon}
            setCoupon={setCoupon}
            appliedCoupon={appliedCoupon}
            couponLoading={couponLoading}
            couponError={couponError}
            onApplyCoupon={handleApplyCoupon}
            onRemoveCoupon={handleRemoveCoupon}
            couponsEnabled={couponsEnabled}
            updateCartQuantity={updateCartQuantity}
            onEditItem={handleEditCartItem}
            onClearCart={handleClearCart}
            onCheckout={() => setIsCheckoutOpen(true)}
            highlightProducts={cartHighlightProducts}
            onHighlightProductClick={handleProductClick}
            cartHighlightTitle="Destaques da loja"
          />
        </div>
      </div>

      {cartCount === 0 && (
        <button
          onClick={() => setIsCartOpen(true)}
          className="md:hidden fixed right-4 bottom-[4.5rem] z-40 w-14 h-14 rounded-2xl bg-[var(--store-primary)] text-white shadow-2xl shadow-[color-mix(in_srgb,var(--store-primary)_24%,transparent)] flex items-center justify-center transition-all animate-bounce"
          aria-label="Abrir sacola"
        >
          <ShoppingBag className="w-6 h-6" />
        </button>
      )}

      {cartCount > 0 && (
        <button
          onClick={() => setIsCartOpen(true)}
          className="md:hidden fixed left-0 right-0 bottom-14 z-40 bg-[#0F172A] text-white shadow-2xl px-4 py-3 flex items-center justify-between active:scale-[0.98] transition-transform"
        >
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-[var(--store-primary)] text-white flex items-center justify-center shadow-lg shadow-[color-mix(in_srgb,var(--store-primary)_22%,transparent)]">
              <ShoppingBag className="w-5 h-5" />
            </div>
            <div className="text-left">
              <p className="text-xs font-black uppercase">{cartCount} item(ns)</p>
              <p className="text-[11px] font-bold text-slate-300">Ver sacola</p>
            </div>
          </div>

          <span className="rounded-2xl bg-white/10 px-3 py-2 text-sm font-black text-white">
            {cartTotal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
          </span>
        </button>
      )}

      {selectedProduct && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/40 backdrop-blur-xs" onClick={() => {
            setSelectedProduct(null);
            setEditingCartItemId(null);
          }} />
          <div className="relative bg-white w-full max-w-lg rounded-2xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden z-10 animate-in fade-in zoom-in-95 duration-150">
            <div className="p-4 border-b border-gray-100 flex justify-between items-start">
              <div className="min-w-0 text-left">
                <h2 className="font-black text-lg text-slate-900">{selectedProduct.name}</h2>
                <p className="text-xs text-slate-400 mt-0.5 text-left">{selectedProduct.description}</p>
              </div>
              <button onClick={() => {
                setSelectedProduct(null);
                setEditingCartItemId(null);
                setOptionErrors({});
              }} className="p-1 rounded-full hover:bg-gray-100">
                <X className="w-5 h-5 text-gray-500" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-6">
              {selectedProduct.image && (
                <div className="w-full h-44 rounded-xl overflow-hidden border border-gray-100">
                  <img src={selectedProduct.image} alt={selectedProduct.name} className="w-full h-full object-cover" />
                </div>
              )}

              {(selectedProduct.option_groups || []).map((group) => {
                const minSelected = Number(group.min_selected || 0);
                const hasError = Boolean(optionErrors[group.id]);

                return (
                  <div
                    key={group.id}
                    id={`option-group-${group.id}`}
                    className={`space-y-3 bg-gray-50/50 p-3 rounded-xl border ${hasError ? 'border-[var(--store-primary)]/40 bg-[var(--store-primary)]/10' : 'border-gray-100'
                      }`}
                  >
                    <div className="flex justify-between items-baseline gap-3">
                      <h3 className="font-bold text-sm text-slate-900 uppercase tracking-wide">{group.name}</h3>
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-sm ${hasError ? 'bg-[var(--store-primary)]/15 text-[var(--store-primary)]' : 'bg-slate-200 text-slate-600'
                        }`}>
                        {minSelected > 0 ? 'Obrigatório' : 'Opcional'} (Máx: {group.max_selected})
                      </span>
                    </div>

                    {hasError && (
                      <p className="text-[11px] font-bold text-[var(--store-primary)]">
                        {optionErrors[group.id]}
                      </p>
                    )}

                    <div className="space-y-2">
                      {group.items?.map((item) => {
                        const isSelected = group.max_selected === 1
                          ? selectedOptions[group.id]?.id === item.id
                          : selectedOptions[group.id]?.some(o => o.id === item.id);

                        const optionImageUrl = getOptionImageUrl(item);

                        return (
                          <div
                            key={item.id}
                            onClick={() => handleOptionSelect(group, item)}
                            className={`flex items-center justify-between gap-3 p-3 rounded-xl border bg-white cursor-pointer select-none transition-all ${isSelected
                              ? 'border-[var(--store-primary)] ring-1 ring-[var(--store-primary)]/20 bg-[var(--store-primary)]/5'
                              : 'border-gray-100 hover:border-gray-200'
                              }`}
                          >
                            <div className="flex items-center gap-3 min-w-0">
                              <input
                                type="checkbox"
                                checked={isSelected || false}
                                readOnly
                                className="text-[var(--store-primary)] focus:ring-[var(--store-primary)] rounded-full w-4 h-4 pointer-events-none flex-shrink-0"
                              />

                              {optionImageUrl && (
                                <img
                                  src={optionImageUrl}
                                  alt={item.name}
                                  className="w-12 h-12 rounded-lg object-cover border border-gray-100 bg-gray-50 flex-shrink-0"
                                />
                              )}

                              <span className="text-xs font-semibold text-slate-800 truncate">
                                {item.name}
                              </span>
                            </div>

                            {parseFloat(item.price) > 0 && (
                              <span className="text-xs font-black text-slate-600 whitespace-nowrap">
                                + {parseFloat(item.price).toLocaleString('pt-BR', {
                                  style: 'currency',
                                  currency: 'BRL'
                                })}
                              </span>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </div>
                );
              })}

              {(!selectedProduct.option_groups || selectedProduct.option_groups.length === 0) && (
                <p className="text-left text-xs text-slate-400 py-6">Este item não possui adicionais. Escolha a quantidade desejada abaixo.</p>
              )}

              <div className="space-y-2 bg-gray-50/50 p-3 rounded-xl border border-gray-100">
                <div className="flex justify-between items-center">
                  <h3 className="font-bold text-sm text-slate-900 uppercase tracking-wide">Alguma observação?</h3>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-sm bg-slate-200 text-slate-600">
                    Opcional
                  </span>
                </div>
                <textarea
                  value={observation}
                  onChange={(e) => setObservation(e.target.value)}
                  placeholder="Ex: tirar a cebola, maionese à parte, ponto da carne bem passado, etc."
                  maxLength={140}
                  rows={3}
                  className="w-full p-3 bg-white border border-gray-200 rounded-xl text-xs text-slate-700 placeholder-slate-400 focus:outline-none focus:border-[var(--store-primary)] focus:ring-1 focus:ring-[var(--store-primary)]/20 resize-none transition-all"
                />
                <div className="text-right text-[10px] text-slate-400 font-medium">
                  {observation.length}/140 caracteres
                </div>
              </div>
            </div>

            <div className="p-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-4">
              <div className="flex items-center gap-3 bg-white border border-gray-200 px-3 py-2 rounded-xl">
                <button onClick={() => setProductQuantity(q => Math.max(1, q - 1))} className="text-[var(--store-primary)] p-1">
                  <Minus className="w-3.5 h-3.5 stroke-[3]" />
                </button>
                <span className="font-extrabold text-sm text-slate-800 w-4 text-center">{productQuantity}</span>
                <button onClick={() => setProductQuantity(q => q + 1)} className="text-[var(--store-primary)] p-1">
                  <Plus className="w-3.5 h-3.5 stroke-[3]" />
                </button>
              </div>

              <button
                onClick={handleAddCustomProductToCart}
                className="bg-[var(--store-primary)] hover:brightness-90 text-white py-3 rounded-xl font-black text-xs uppercase tracking-wider flex justify-between items-center px-4 shadow-md shadow-[color-mix(in_srgb,var(--store-primary)_22%,transparent)]"
              >
                <span>{editingCartItemId ? 'Atualizar item' : 'Adicionar à sacola'}</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {isCartOpen && (
        <div className="fixed inset-0 z-50 flex justify-end lg:hidden">
          <div className="absolute inset-0 bg-slate-950/45 backdrop-blur-xs" onClick={() => setIsCartOpen(false)} />
          <div className="relative w-full bg-white h-full shadow-2xl flex flex-col z-10 overflow-hidden animate-in slide-in-from-right-4 duration-200">
            <div className="flex items-center px-4 py-3 border-b border-slate-100 bg-white shrink-0">
              <button
                type="button"
                onClick={() => {
                  setIsCartOpen(false);
                  window.scrollTo({ top: 0, behavior: 'smooth' });
                }}
                className="inline-flex items-center gap-2 rounded-xl px-2 py-2 text-sm font-bold text-slate-600 transition-colors hover:text-[var(--store-primary)]"
              >
                <ArrowLeft className="w-4 h-4" />
                Continuar comprando
              </button>
            </div>

            <div className="flex-1 overflow-hidden">
              <Cart
                variant="fullscreen"
                cart={cart}
                cartCount={cartCount}
                subtotal={subtotal}
                deliveryFee={deliveryFee}
                discountAmount={discountAmount}
                cartTotal={cartTotal}
                coupon={coupon}
                setCoupon={setCoupon}
                appliedCoupon={appliedCoupon}
                couponLoading={couponLoading}
                couponError={couponError}
                onApplyCoupon={handleApplyCoupon}
                onRemoveCoupon={handleRemoveCoupon}
                couponsEnabled={couponsEnabled}
                updateCartQuantity={updateCartQuantity}
                onEditItem={handleEditCartItem}
                onClearCart={handleClearCart}
                onCheckout={() => setIsCheckoutOpen(true)}
                highlightProducts={cartHighlightProducts}
                onHighlightProductClick={(product) => {
                  handleProductClick(product);
                  setIsCartOpen(false);
                }}
                cartHighlightTitle="Destaques da loja"
              />
            </div>
          </div>
        </div>
      )}

      <Checkout
        isOpen={isCheckoutOpen}
        onClose={() => setIsCheckoutOpen(false)}
        store={store}
        cart={cart}
        subtotal={subtotal}
        appliedCoupon={appliedCoupon}
        discountAmount={discountAmount}
        coupon={coupon}
        setCoupon={setCoupon}
        couponLoading={couponLoading}
        couponError={couponError}
        onApplyCoupon={handleApplyCoupon}
        onRemoveCoupon={handleRemoveCoupon}
        couponsEnabled={couponsEnabled}
        onSuccess={(orderData) => {
          if (orderData?.customer || orderData?.user) {
            persistCustomerSession(orderData.customer || orderData.user);
          } else if (orderData?.order) {
            const order = orderData.order;

            persistCustomerSession({
              name: order.customer_name || order.user?.name || '',
              phone: order.customer_phone || order.user?.phone || '',
              address: order.user?.address || order.address || '',
              address_number: order.user?.address_number || order.address_number || '',
              district: order.user?.district || order.district || '',
              address_complement: order.user?.address_complement || order.address_complement || ''
            });
          }

          if (orderData?.order) {
            setCart([]);
            setAppliedCoupon(null);
            setCoupon('');
            setIsCartOpen(false);
            setIsCheckoutOpen(false);
          }
        }}
      />

    </div>
  );
}
