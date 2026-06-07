<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import api from '@/services/api'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
    Plus, Pencil, Trash2, X, Loader2, CheckCircle, XCircle,
    TicketPercent, Copy, CalendarDays, ToggleLeft, ToggleRight
} from 'lucide-vue-next'

const coupons = ref([])
const loading = ref(true)
const errors = ref(null)
const search = ref('')

const toast = ref({ show: false, message: '', type: 'success' })
const showNotify = (msg, type = 'success') => {
    toast.value = { show: true, message: msg, type }
    setTimeout(() => toast.value.show = false, 4000)
}
const normalizeBoolean = (value) => {
    return value === true || value === 1 || value === '1'
}

const normalizeCoupon = (coupon) => ({
    ...coupon,
    id: coupon.id ?? coupon.coupon_id,
    is_active: normalizeBoolean(coupon.is_active)
})
const modal = reactive({ show: false, isEdit: false, saving: false, currentId: null })
const deleteModal = reactive({ show: false, id: null, loading: false })

const form = reactive({
    code: '',
    description: '',
    type: 'percentage',
    value: '',
    min_order_amount: '',
    max_discount_amount: '',
    usage_limit: '',
    expires_at: '',
    is_active: true
})

const filteredCoupons = computed(() => {
    const term = search.value.trim().toLowerCase()
    if (!term) return coupons.value
    return coupons.value.filter(coupon =>
        coupon.code?.toLowerCase().includes(term) ||
        coupon.description?.toLowerCase().includes(term)
    )
})

const formatCurrency = (value) => {
    const amount = Number(value) || 0
    return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

const formatDiscount = (coupon) => {
    if (coupon.type === 'percentage') return `${Number(coupon.value || 0)}%`
    return formatCurrency(coupon.value)
}

const formatDate = (date) => {
    if (!date) return 'Sem validade'
    return new Date(date).toLocaleDateString('pt-BR')
}

const resetForm = () => {
    form.code = ''
    form.description = ''
    form.type = 'percentage'
    form.value = ''
    form.min_order_amount = ''
    form.max_discount_amount = ''
    form.usage_limit = ''
    form.expires_at = ''
    form.is_active = true
}

const fetchCoupons = async () => {
    try {
        loading.value = true
        const { data } = await api.get('/merchant/coupons')

        const items = data.data || data || []

        coupons.value = items.map(normalizeCoupon)
    } catch (err) {
        showNotify('Erro ao carregar cupons.', 'error')
    } finally {
        loading.value = false
    }
}

const openModal = (coupon = null) => {
    errors.value = null
    resetForm()

    if (coupon) {
        modal.isEdit = true
        modal.currentId = coupon.id
        form.code = coupon.code || ''
        form.description = coupon.description || ''
        form.type = coupon.type || 'percentage'
        form.value = coupon.value ?? ''
        form.min_order_amount = coupon.min_order_amount ?? ''
        form.max_discount_amount = coupon.max_discount_amount ?? ''
        form.usage_limit = coupon.usage_limit ?? ''
        form.expires_at = coupon.expires_at ? String(coupon.expires_at).slice(0, 10) : ''
        form.is_active = Boolean(coupon.is_active)
    } else {
        modal.isEdit = false
        modal.currentId = null
    }

    modal.show = true
}

const closeModal = () => {
    modal.show = false
    errors.value = null
}

const payloadFromForm = () => ({
    code: form.code.trim().toUpperCase(),
    description: form.description,
    type: form.type,
    value: Number(form.value || 0),
    min_order_amount: form.min_order_amount === '' ? null : Number(form.min_order_amount),
    max_discount_amount: form.max_discount_amount === '' ? null : Number(form.max_discount_amount),
    usage_limit: form.usage_limit === '' ? null : Number(form.usage_limit),
    expires_at: form.expires_at || null,
    is_active: form.is_active
})

const handleSubmit = async () => {
    modal.saving = true
    errors.value = null

    try {
        if (modal.isEdit) {
            await api.put(`/merchant/coupons/${modal.currentId}`, payloadFromForm())
            showNotify('Cupom atualizado!')
        } else {
            await api.post('/merchant/coupons', payloadFromForm())
            showNotify('Cupom criado!')
        }

        await fetchCoupons()
        closeModal()
    } catch (err) {
        if (err.response?.status === 422) errors.value = err.response.data.errors
        const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao salvar cupom.'
        showNotify(msg, 'error')
    } finally {
        modal.saving = false
    }
}

const toggleCoupon = async (coupon) => {
    const couponId = coupon.id ?? coupon.coupon_id

    if (!couponId) {
        console.error('Cupom sem ID:', coupon)
        showNotify('Cupom inválido: ID não encontrado.', 'error')
        return
    }

    try {
        const { data } = await api.patch(`/merchant/coupons/${couponId}/toggle`)
        const saved = normalizeCoupon(data.data || data)

        coupons.value = coupons.value.map(item =>
            item.id === saved.id ? saved : item
        )

        showNotify(saved.is_active ? 'Cupom ativado!' : 'Cupom pausado!')
    } catch (err) {
        showNotify('Erro ao alterar status do cupom.', 'error')
    }
}

const copyCode = async (code) => {
    await navigator.clipboard.writeText(code)
    showNotify(`Cupom ${code} copiado!`)
}

const confirmDelete = (id) => {
    deleteModal.id = id
    deleteModal.show = true
}

const handleDelete = async () => {
    deleteModal.loading = true

    try {
        await api.delete(`/merchant/coupons/${deleteModal.id}`)
        coupons.value = coupons.value.filter(coupon => coupon.id !== deleteModal.id)
        deleteModal.show = false
        showNotify('Cupom removido.')
    } catch (err) {
        const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao remover cupom.'
        showNotify(msg, 'error')
    } finally {
        deleteModal.loading = false
    }
}

onMounted(fetchCoupons)
</script>

<template>
    <DashboardLayout>
        <div class="space-y-8 animate-in fade-in duration-500 pb-10">
            <header
                class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-red-100 shadow-sm">
                <div class="flex items-center gap-4">
                    <div
                        class="w-12 h-12 bg-red-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-red-100">
                        <TicketPercent size="28" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-black text-gray-900">Gerenciar Cupons</h1>
                        <p class="text-gray-500 text-sm">Crie descontos, limite usos e acompanhe os cupons ativos da
                            loja.</p>
                    </div>
                </div>

                <button @click="openModal()"
                    class="bg-red-600 hover:bg-red-700 text-white px-6 py-4 rounded-2xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-red-100 active:scale-95">
                    <Plus size="20" />
                    Novo Cupom
                </button>
            </header>

            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <input v-model="search" type="text" placeholder="Buscar por código ou descrição"
                        class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-bold transition-all">
                </div>

                <div v-if="loading" class="p-20 flex justify-center text-red-600">
                    <Loader2 class="animate-spin" size="32" />
                </div>

                <div v-else-if="coupons.length === 0" class="p-20 text-center">
                    <TicketPercent class="mx-auto text-gray-200 mb-4" size="48" />
                    <p class="text-gray-400 font-medium">Nenhum cupom cadastrado.</p>
                    <button @click="openModal()"
                        class="mt-6 bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-2xl font-black text-sm transition-all">
                        Criar primeiro cupom
                    </button>
                </div>

                <div v-else class="divide-y divide-gray-50">
                    <div v-for="coupon in filteredCoupons" :key="coupon.id"
                        class="grid grid-cols-1 lg:grid-cols-[1.2fr_1fr_1fr_160px] gap-4 items-center px-6 py-5 bg-white hover:bg-red-50/30 transition-colors">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-black text-gray-900 text-lg tracking-tight">{{ coupon.code }}</span>
                                <button @click="copyCode(coupon.code)"
                                    class="p-1.5 bg-gray-100 text-gray-400 hover:bg-gray-900 hover:text-white rounded-lg transition-all"
                                    title="Copiar código">
                                    <Copy size="14" />
                                </button>
                            </div>
                            <p class="text-xs text-gray-400 font-bold truncate">{{ coupon.description || 'Sem descrição'
                                }}</p>
                        </div>

                        <div>
                            <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest">Desconto</p>
                            <p class="font-black text-red-600">{{ formatDiscount(coupon) }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <span
                                class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-gray-100 text-gray-500 text-[10px] font-black uppercase">
                                <CalendarDays size="12" /> {{ formatDate(coupon.expires_at) }}
                            </span>
                            <span
                                :class="coupon.is_active ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-400'"
                                class="px-3 py-1 rounded-full text-[10px] font-black uppercase">
                                {{ coupon.is_active ? 'Ativo' : 'Pausado' }}
                            </span>
                        </div>

                        <div class="flex justify-start lg:justify-end gap-2">
                            <button @click="toggleCoupon(coupon)" :class="[
                                'p-2.5 rounded-xl transition-all',
                                coupon.is_active
                                    ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white'
                                    : 'bg-gray-100 text-gray-400 hover:bg-gray-900 hover:text-white'
                            ]" :title="coupon.is_active ? 'Pausar cupom' : 'Ativar cupom'">
                                <ToggleRight v-if="coupon.is_active" size="18" />
                                <ToggleLeft v-else size="18" />
                            </button>
                            <button @click="openModal(coupon)"
                                class="p-2.5 bg-gray-100 text-gray-500 hover:bg-gray-900 hover:text-white rounded-xl transition-all"
                                title="Editar">
                                <Pencil size="18" />
                            </button>
                            <button @click="confirmDelete(coupon.id ?? coupon.coupon_id)"
                                class="p-2.5 bg-red-50 text-red-600 hover:bg-red-600 hover:text-white rounded-xl transition-all"
                                title="Remover">
                                <Trash2 size="18" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <transition name="slide-fade">
            <div v-if="modal.show" class="fixed inset-0 z-[60] flex justify-end">
                <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" @click="closeModal"></div>
                <div
                    class="relative w-full max-w-lg bg-white h-screen shadow-2xl flex flex-col p-8 animate-slide-in overflow-y-auto">
                    <div class="flex justify-between items-center mb-8">
                        <h2 class="text-2xl font-black text-gray-900">{{ modal.isEdit ? 'Editar Cupom' : 'Novo Cupom' }}
                        </h2>
                        <button @click="closeModal"
                            class="p-2 bg-gray-50 rounded-full hover:bg-red-600 hover:text-white transition-all">
                            <X size="20" />
                        </button>
                    </div>

                    <form @submit.prevent="handleSubmit" class="space-y-5">
                        <div class="space-y-1">
                            <label class="text-xs font-black text-gray-400 uppercase">Código</label>
                            <input v-model="form.code" type="text"
                                class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black uppercase transition-all"
                                placeholder="Ex: PRIMEIRA10">
                            <p v-if="errors?.code" class="text-[10px] text-red-600 font-bold uppercase tracking-widest">
                                {{ errors.code[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-black text-gray-400 uppercase">Descrição</label>
                            <textarea v-model="form.description" rows="3"
                                class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-bold transition-all resize-none"
                                placeholder="Ex: Desconto para primeira compra"></textarea>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-black text-gray-400 uppercase">Tipo</label>
                                <select v-model="form.type"
                                    class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all">
                                    <option value="percentage">Percentual</option>
                                    <option value="fixed">Valor fixo</option>
                                </select>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-black text-gray-400 uppercase">Valor</label>
                                <input v-model="form.value" type="number" min="0" step="0.01"
                                    class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-black text-gray-400 uppercase">Pedido mínimo</label>
                                <input v-model="form.min_order_amount" type="number" min="0" step="0.01"
                                    class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-black text-gray-400 uppercase">Desconto máximo</label>
                                <input v-model="form.max_discount_amount" type="number" min="0" step="0.01"
                                    class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-black text-gray-400 uppercase">Limite de usos</label>
                                <input v-model="form.usage_limit" type="number" min="0"
                                    class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-black text-gray-400 uppercase">Validade</label>
                                <input v-model="form.expires_at" type="date"
                                    class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all">
                            </div>
                        </div>

                        <label class="flex items-center justify-between p-4 bg-gray-50 rounded-2xl cursor-pointer">
                            <span class="text-sm font-black text-gray-700">Cupom ativo</span>
                            <input v-model="form.is_active" type="checkbox" class="w-5 h-5 accent-red-600">
                        </label>

                        <button type="submit" :disabled="modal.saving"
                            class="w-full bg-red-600 text-white py-5 rounded-[2rem] font-black text-lg hover:bg-red-700 transition-all shadow-xl shadow-red-100 active:scale-95 flex justify-center items-center disabled:opacity-50">
                            <Loader2 v-if="modal.saving" class="animate-spin mr-2" size="24" />
                            {{ modal.isEdit ? 'SALVAR ALTERAÇÕES' : 'CRIAR CUPOM' }}
                        </button>
                    </form>
                </div>
            </div>
        </transition>

        <transition name="toast">
            <div v-if="toast.show"
                class="fixed bottom-10 right-10 z-[100] flex items-center p-6 rounded-[2rem] shadow-2xl bg-gray-900 text-white border border-white/10">
                <div
                    :class="['w-10 h-10 rounded-full flex items-center justify-center mr-4 shadow-inner', toast.type === 'success' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400']">
                    <CheckCircle v-if="toast.type === 'success'" size="24" />
                    <XCircle v-else size="24" />
                </div>
                <span class="text-sm font-black tracking-tight">{{ toast.message }}</span>
            </div>
        </transition>

        <transition name="slide-fade">
            <div v-if="deleteModal.show" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-gray-950/40 backdrop-blur-md" @click="deleteModal.show = false"></div>
                <div
                    class="relative bg-white w-full max-w-md rounded-[3rem] p-10 shadow-2xl text-center border border-gray-100">
                    <div
                        class="w-20 h-20 bg-red-50 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-6">
                        <Trash2 size="40" />
                    </div>
                    <h3 class="text-2xl font-black text-gray-900 leading-tight">Remover Cupom?</h3>
                    <p class="text-gray-500 font-bold text-sm mt-3 leading-relaxed">Essa ação não pode ser desfeita.</p>
                    <div class="flex flex-col gap-2 mt-8">
                        <button @click="handleDelete" :disabled="deleteModal.loading"
                            class="w-full py-5 bg-red-600 hover:bg-black text-white rounded-2xl font-black transition-all flex justify-center items-center shadow-lg active:scale-95 disabled:opacity-50">
                            <Loader2 v-if="deleteModal.loading" class="animate-spin mr-2" size="20" />
                            {{ deleteModal.loading ? 'EXCLUINDO...' : 'SIM, EXCLUIR AGORA' }}
                        </button>
                        <button @click="deleteModal.show = false"
                            class="w-full py-4 hover:bg-gray-50 rounded-2xl font-black text-gray-400 transition-all uppercase text-xs tracking-widest">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </DashboardLayout>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }

    to {
        transform: translateX(0);
    }
}

.animate-slide-in {
    animation: slide-in 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-fade-enter-active {
    transition: all 0.3s ease-out;
}

.slide-fade-enter-from {
    opacity: 0;
    transform: translateY(10px);
}

.toast-enter-active,
.toast-leave-active {
    transition: all 0.25s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
</style>