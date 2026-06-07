// src/components/OrdersModal.jsx
import React, { useEffect, useState } from 'react';
import { X, ShoppingBag, Clock, ChevronRight, Loader2 } from 'lucide-react';
import api from '../services/api';

export default function OrdersModal({ isOpen, onClose, onLoginRequired }) {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    const fetchOrders = async () => {
      try {
        setLoading(true);
        setError('');
        
        const token = localStorage.getItem('token');
        if (!token) {
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
  }, [isOpen]);

  if (!isOpen) return null;

  const formatCurrency = (value) => Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

  const getStatusBadge = (status) => {
    const config = {
      pending: { label: 'Pendente', css: 'bg-amber-50 text-amber-700 border-amber-200' },
      confirmed: { label: 'Aceito', css: 'bg-blue-50 text-blue-700 border-blue-200' },
      dispatching: { label: 'A caminho', css: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
      completed: { label: 'Entregue', css: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
      canceled: { label: 'Cancelado', css: 'bg-red-50 text-red-700 border-red-200' },
    };
    const current = config[status] || { label: status, css: 'bg-slate-100 text-slate-600' };
    return <span className={`text-[10px] font-black px-2 py-0.5 rounded-full border ${current.css}`}>{current.label}</span>;
  };

  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" onClick={onClose} />

      <div className="relative bg-white w-full max-w-md h-[85vh] rounded-3xl overflow-hidden shadow-2xl flex flex-col">
        <div className="p-5 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h2 className="font-black text-xl text-slate-900">Meus Pedidos</h2>
            <p className="text-xs font-semibold text-slate-400">Histórico de compras na loja</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-xl text-slate-400 hover:bg-slate-100">
            <X size={20} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-5 space-y-4 bg-slate-50/50">
          {loading ? (
            <div className="flex flex-col items-center justify-center text-slate-400 gap-2 py-8 mx-auto">
                <Loader2 className="animate-spin text-[var(--store-primary)]" size={32} />
                <span className="text-xs font-bold">Buscando histórico...</span>
            </div>
          ) : error ? (
            <div className="p-4 rounded-xl bg-red-50 text-red-600 text-sm font-bold text-center border border-red-100">
              {error}
            </div>
          ) : orders.length === 0 ? (
            <div className="text-center py-16 space-y-3">
              <div className="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto text-slate-400">
                <ShoppingBag size={20} />
              </div>
              <p className="text-sm font-bold text-slate-500">Nenhum pedido encontrado por aqui.</p>
            </div>
          ) : (
            orders.map((order) => (
              <div key={order.id} className="bg-white p-4 rounded-2xl border border-slate-200/60 shadow-sm space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-black text-slate-400"># {order.id.toString().padStart(5, '0')}</span>
                  {getStatusBadge(order.status)}
                </div>
                
                <div className="text-left">
                  <p className="text-xs font-bold text-slate-500 truncate">
                    {order.items?.map(i => `${i.quantity}x ${i.product?.name || 'Item'}`).join(', ')}
                  </p>
                  <p className="text-sm font-black text-slate-900 mt-1">{formatCurrency(order.total_amount)}</p>
                </div>

                <div className="pt-2 border-t border-slate-100 flex justify-between items-center text-[11px] font-bold text-slate-400">
                  <span className="flex items-center gap-1">
                    <Clock size={12} />
                    {new Date(order.created_at).toLocaleDateString('pt-BR')}
                  </span>
                  {order.whatsapp_url && (
                    <a 
                      href={order.whatsapp_url} 
                      target="_blank" 
                      rel="noopener noreferrer" 
                      className="text-emerald-600 flex items-center gap-0.5 hover:underline"
                    >
                      Ver no Whats <ChevronRight size={12} />
                    </a>
                  )}
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}