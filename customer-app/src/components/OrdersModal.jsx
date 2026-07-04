// src/components/OrdersModal.jsx
import React, { useEffect, useMemo, useState } from 'react';
import {
  ShoppingBag,
  Clock,
  ChevronRight,
  Tag,
  ChevronLeft
} from 'lucide-react';
import api from '../services/api';
import SheetModal from './SheetModal';
import CustomerLoadingPanel, { customerPanelMinHeight } from './CustomerLoadingPanel';
import { openWhatsAppUrl } from '../utils/whatsapp';

export default function OrdersModal({ isOpen, onClose, onLoginRequired, otpLoginEnabled = true, authMessage = '' }) {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [currentPage, setCurrentPage] = useState(1);
  const ordersPerPage = 5;

  const totalPages = Math.max(1, Math.ceil(orders.length / ordersPerPage));

  const paginatedOrders = useMemo(() => {
    const start = (currentPage - 1) * ordersPerPage;
    const end = start + ordersPerPage;

    return orders.slice(start, end);
  }, [orders, currentPage]);

  const paginationStart = orders.length === 0
    ? 0
    : (currentPage - 1) * ordersPerPage + 1;

  const paginationEnd = Math.min(currentPage * ordersPerPage, orders.length);

  const goToPage = (page) => {
    setCurrentPage(Math.min(Math.max(1, page), totalPages));
  };

  const formatCurrency = (value) => {
    return Number(value || 0).toLocaleString('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    });
  };

  const formatDateTime = (value) => {
    if (!value) return 'Data não informada';

    return new Date(value).toLocaleString('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  const getCouponCode = (order) => {
    return (
      order?.coupon?.code ||
      order?.coupon_display_code ||
      order?.coupon_code ||
      null
    );
  };

  const getStatusBadge = (status) => {
    const config = {
      pending: {
        label: 'Pedido enviado para a loja',
        css: 'bg-amber-50 text-amber-700 border-amber-200'
      },
      preparing: {
        label: 'Em preparo',
        css: 'bg-orange-50 text-orange-700 border-orange-200'
      },
      ready: {
        label: 'Pronto para entrega',
        css: 'bg-emerald-50 text-emerald-700 border-emerald-200'
      },
      shipped: {
        label: 'Saiu para entrega',
        css: 'bg-blue-50 text-blue-700 border-blue-200'
      },
      delivered: {
        label: 'Pedido entregue',
        css: 'bg-emerald-50 text-emerald-700 border-emerald-200'
      },
      canceled: {
        label: 'Pedido cancelado',
        css: 'bg-[var(--store-primary)]/10 text-[var(--store-primary)] border-[var(--store-primary)]/20'
      },

      confirmed: {
        label: 'Pedido aceito pela loja',
        css: 'bg-blue-50 text-blue-700 border-blue-200'
      },
      dispatching: {
        label: 'Saiu para entrega',
        css: 'bg-blue-50 text-blue-700 border-blue-200'
      },
      completed: {
        label: 'Pedido entregue',
        css: 'bg-emerald-50 text-emerald-700 border-emerald-200'
      }
    };

    const current = config[status] || {
      label: 'Status desconhecido',
      css: 'bg-slate-100 text-slate-600 border-slate-200'
    };

    return (
      <span className={`text-[10px] font-black px-2 py-0.5 rounded-full border ${current.css}`}>
        {current.label}
      </span>
    );
  };

  const getFulfillmentLabel = (order) => {
    if (order.fulfillment_type === 'pickup') return 'Retirada';
    if (order.fulfillment_type === 'delivery') return 'Entrega';
    return order.type === 'sale' ? 'Entrega' : 'Pedido';
  };

  useEffect(() => {
    if (!isOpen) return;

    const fetchOrders = async () => {
      try {
        setLoading(true);
        setError('');
        setCurrentPage(1);

        const token = localStorage.getItem('token');

        if (!token) {
          if (!otpLoginEnabled) {
            setOrders([]);
            setError('');
            return;
          }

          setError('Você precisa estar logado para ver seu histórico.');
          onLoginRequired?.();
          return;
        }

        const { data } = await api.get('/customer/orders', {
          headers: { Authorization: `Bearer ${token}` }
        });

        setOrders(data.orders || []);
      } catch (err) {
        if (err.response?.status === 401) {
          setError('Sua sessão expirou. Por favor, faça login novamente.');
          onLoginRequired?.();
        } else {
          setError('Não foi possível carregar seus pedidos.');
        }
      } finally {
        setLoading(false);
      }
    };

    fetchOrders();
  }, [isOpen, onLoginRequired, otpLoginEnabled]);

  useEffect(() => {
    if (currentPage > totalPages) {
      setCurrentPage(totalPages);
    }
  }, [currentPage, totalPages]);

  if (!isOpen) return null;

  const paginationFooter = !loading && !error && orders.length > 0 ? (
    <div className="flex items-center justify-between gap-3">
      <div className="text-[11px] font-black text-slate-400">
        {paginationStart}-{paginationEnd} de {orders.length}
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => goToPage(currentPage - 1)}
          disabled={currentPage === 1}
          className="w-9 h-9 rounded-xl border border-slate-200 flex items-center justify-center text-slate-500 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
        >
          <ChevronLeft size={16} />
        </button>

        <span className="text-xs font-black text-slate-600 min-w-[56px] text-center">
          {currentPage}/{totalPages}
        </span>

        <button
          type="button"
          onClick={() => goToPage(currentPage + 1)}
          disabled={currentPage === totalPages}
          className="w-9 h-9 rounded-xl border border-slate-200 flex items-center justify-center text-slate-500 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
        >
          <ChevronRight size={16} />
        </button>
      </div>
    </div>
  ) : null;

  return (
    <SheetModal
      isOpen={isOpen}
      onClose={onClose}
      title="Meus Pedidos"
      subtitle="Histórico de compras na loja"
      footer={paginationFooter}
    >
      {loading ? (
        <CustomerLoadingPanel message="Buscando histórico..." />
      ) : (
        <div className={`p-5 space-y-4 flex-1 ${!error && orders.length === 0 ? customerPanelMinHeight : ''}`}>
          {error ? (
            <div className={`flex items-center justify-center p-5 ${customerPanelMinHeight}`}>
              <div className="p-4 rounded-xl bg-[var(--store-primary)]/10 text-[var(--store-primary)] text-sm font-bold text-center border border-[var(--store-primary)]/20 max-w-sm">
                {error}
              </div>
            </div>
          ) : orders.length === 0 ? (
            <div className="flex flex-col items-center justify-center text-center space-y-3 h-full px-4">
              <div className="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center text-slate-400">
                <ShoppingBag size={20} />
              </div>

              {!otpLoginEnabled ? (
                <>
                  <p className="text-sm font-bold text-slate-700">Histórico em breve</p>
                  <p className="text-xs font-medium text-slate-500 leading-relaxed max-w-xs">
                    {authMessage || 'Por enquanto, faça seu pedido pelo cardápio informando nome e WhatsApp. Você recebe a confirmação no WhatsApp da loja.'}
                  </p>
                </>
              ) : (
                <p className="text-sm font-bold text-slate-500">Nenhum pedido encontrado por aqui.</p>
              )}
            </div>
          ) : (
            paginatedOrders.map((order) => {
              const couponCode = getCouponCode(order);
              const hasDiscount = Number(order.discount_amount || 0) > 0;

              return (
                <div key={order.id} className="bg-white p-4 rounded-2xl border border-slate-200/60 shadow-sm space-y-3">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-xs font-black text-slate-400">
                      #{order.id.toString().padStart(5, '0')}
                    </span>

                    {getStatusBadge(order.status)}
                  </div>

                  <div className="text-left space-y-2">
                    <div>
                      <p className="text-[10px] font-black uppercase text-slate-400 tracking-widest">
                        Itens
                      </p>

                      <p className="text-xs font-bold text-slate-500 line-clamp-2">
                        {order.items?.map(i => `${i.quantity}x ${i.product?.name || 'Item'}`).join(', ')}
                      </p>
                    </div>

                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <p className="text-[10px] font-black uppercase text-slate-400 tracking-widest">
                          Total
                        </p>
                        <p className="text-sm font-black text-slate-900">
                          {formatCurrency(order.total_amount)}
                        </p>
                      </div>

                      <div className="text-right">
                        <p className="text-[10px] font-black uppercase text-slate-400 tracking-widest">
                          Tipo
                        </p>
                        <p className="text-xs font-black text-slate-700">
                          {getFulfillmentLabel(order)}
                        </p>
                      </div>
                    </div>

                    {hasDiscount && (
                      <div className="flex items-center justify-between gap-3 p-2 rounded-xl bg-emerald-50 border border-emerald-100">
                        <div className="flex items-center gap-2 min-w-0">
                          <Tag size={14} className="text-emerald-600 flex-shrink-0" />

                          <div className="min-w-0">
                            <p className="text-[10px] font-black uppercase text-emerald-700">
                              Cupom {couponCode ? `(${couponCode})` : '(removido)'}
                            </p>

                            <p className="text-[10px] font-bold text-emerald-600">
                              Desconto aplicado
                            </p>
                          </div>
                        </div>

                        <span className="text-xs font-black text-emerald-700 whitespace-nowrap">
                          - {formatCurrency(order.discount_amount)}
                        </span>
                      </div>
                    )}

                    {Number(order.delivery_fee || 0) > 0 && (
                      <div className="flex items-center justify-between text-[11px] font-bold text-slate-400">
                        <span>Taxa de entrega</span>
                        <span>{formatCurrency(order.delivery_fee)}</span>
                      </div>
                    )}
                  </div>

                  <div className="pt-2 border-t border-slate-100 flex justify-between items-center text-[11px] font-bold text-slate-400">
                    <span className="flex items-center gap-1">
                      <Clock size={12} />
                      {formatDateTime(order.created_at)}
                    </span>

                    {order.whatsapp_url && (
                      <button
                        type="button"
                        onClick={() => openWhatsAppUrl(order.whatsapp_url)}
                        className="text-emerald-600 flex items-center gap-0.5 hover:underline"
                      >
                        Ver no Whats <ChevronRight size={12} />
                      </button>
                    )}
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}
    </SheetModal>
  );
}