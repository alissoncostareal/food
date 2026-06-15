<script setup>
import { ref, onMounted, reactive, watch, computed, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { getApiErrorMessage } from '@/utils/apiError'
import { postFormData } from '@/utils/uploadForm'
import AppToast from '@/components/ui/AppToast.vue'
import StoreSetupProgressCard from '@/components/StoreSetupProgressCard.vue'
import {
    Store as StoreIcon, Save, Instagram, MessageCircle, MapPin,
    Clock, Loader2, CheckCircle, XCircle, Camera, Link2, Copy,
    Building2, Plus, Palette, ExternalLink, Truck, Sparkles,
    CreditCard, Banknote, Smartphone, Wallet, Zap
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const setupProgressLoading = ref(true)
const setupProgress = ref(null)
const saving = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })
const activeSection = ref('identidade')

const selectedLogoFile = ref(null)
const selectedBannerFile = ref(null)
const syncingColor = ref(false)
const menuAppBaseUrl = (import.meta.env.VITE_MENU_APP_URL || 'https://app.partiumenu.com.br').replace(/\/+$/, '')

const canManageBranches = ref(false)
const paymentConnection = ref(null)
const branches = ref([])
const branchLimits = ref({ max_stores: 1, current_stores: 1, can_create_branch: false })
const branchForm = reactive({ name: '', slug: '' })
const creatingBranch = ref(false)

const sections = computed(() => {
    const items = [
        { id: 'identidade', label: 'Identidade', icon: StoreIcon },
        { id: 'visual', label: 'Aparência', icon: Palette },
        { id: 'operacao', label: 'Operação', icon: Truck }
    ]

    if (canManageBranches.value) {
        items.push({ id: 'filiais', label: 'Filiais', icon: Building2 })
    }

    return items
})

const branchUsagePercent = computed(() => {
    const max = branchLimits.value.max_stores || 1
    const current = branchLimits.value.current_stores || 1
    return Math.min(100, Math.round((current / max) * 100))
})

const formatMoney = (value) =>
    Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

const extraBranchPriceLabel = computed(() => {
    const price = Number(branchLimits.value.extra_branch_monthly_price || 0)
    return price > 0 ? `${formatMoney(price)}/mês` : 'Consulte o suporte'
})

const presetColors = [
    { name: 'Vermelho', hex: '#E7000D' },
    { name: 'Vermelho vivo', hex: '#DC2626' },
    { name: 'Laranja', hex: '#EA580C' },
    { name: 'Laranja iFood', hex: '#EA1D2C' },
    { name: 'Coral', hex: '#F97316' },
    { name: 'Amarelo', hex: '#EAB308' },
    { name: 'Verde', hex: '#16A34A' },
    { name: 'Verde menta', hex: '#059669' },
    { name: 'Azul', hex: '#2563EB' },
    { name: 'Azul marinho', hex: '#1E40AF' },
    { name: 'Roxo', hex: '#9333EA' },
    { name: 'Rosa', hex: '#DB2777' },
    { name: 'Vinho', hex: '#9F1239' },
    { name: 'Teal', hex: '#0D9488' },
    { name: 'Marrom', hex: '#92400E' },
    { name: 'Grafite', hex: '#111827' },
    { name: 'Cinza', hex: '#374151' },
    { name: 'Preto', hex: '#000000' }
]

const secondaryPresetColors = [
    { name: 'Grafite', hex: '#1E293B' },
    { name: 'Ardósia', hex: '#0F172A' },
    { name: 'Preto', hex: '#111827' },
    { name: 'Cinza', hex: '#374151' },
    { name: 'Marinho', hex: '#1E3A8A' },
    { name: 'Vinho', hex: '#7F1D1D' },
    { name: 'Verde escuro', hex: '#14532D' },
    { name: 'Teal', hex: '#134E4A' },
    { name: 'Roxo', hex: '#581C87' }
]

const weekDays = [
    { key: 'monday', label: 'Seg', full: 'Segunda' },
    { key: 'tuesday', label: 'Ter', full: 'Terça' },
    { key: 'wednesday', label: 'Qua', full: 'Quarta' },
    { key: 'thursday', label: 'Qui', full: 'Quinta' },
    { key: 'friday', label: 'Sex', full: 'Sexta' },
    { key: 'saturday', label: 'Sáb', full: 'Sábado' },
    { key: 'sunday', label: 'Dom', full: 'Domingo' }
]

const paymentMethodOptions = [
    { key: 'pix_online', label: 'Pix online', description: 'Cliente paga no checkout com QR Code', icon: Zap, onlineOnly: true },
    { key: 'credit_card_online', label: 'Cartão online', description: 'Crédito no checkout (Pagar.me)', icon: CreditCard, onlineOnly: true },
    { key: 'pix', label: 'Pix na entrega', description: 'Transferência na entrega ou retirada', icon: Smartphone },
    { key: 'cash', label: 'Dinheiro', description: 'Pagamento físico na entrega', icon: Banknote },
    { key: 'debit_card', label: 'Débito', description: 'Cartão de débito na entrega', icon: CreditCard },
    { key: 'credit_card', label: 'Crédito', description: 'Cartão de crédito na entrega', icon: Wallet }
]

const defaultDayHours = (overrides = {}) => ({
    open: '08:00',
    close: '22:00',
    closed: false,
    all_day: false,
    ...overrides
})

const createDefaultBusinessHours = () => ({
    monday: defaultDayHours(),
    tuesday: defaultDayHours(),
    wednesday: defaultDayHours(),
    thursday: defaultDayHours(),
    friday: defaultDayHours(),
    saturday: defaultDayHours(),
    sunday: defaultDayHours({ open: '08:00', close: '18:00', closed: true })
})

const form = reactive({
    name: '',
    slug: '',
    description: '',
    is_open: true,
    address: '',
    delivery_fee: 0,
    accepted_payment_methods: ['pix', 'cash', 'debit_card', 'credit_card'],
    online_payments_enabled: false,
    primary_color: '#EF4444',
    secondary_color: '#1E293B',
    instagram_link: '',
    whatsapp_number: '',
    logo_url: null,
    banner_url: null,
    business_hours: createDefaultBusinessHours()
})

const rgbForm = reactive({ red: 239, green: 68, blue: 68 })

const publicMenuUrl = computed(() => {
    const cleanSlug = String(form.slug || '').trim().replace(/^\/+|\/+$/g, '')
    return cleanSlug ? `${menuAppBaseUrl}/${cleanSlug}` : menuAppBaseUrl
})

const openDaysCount = computed(() =>
    weekDays.filter((day) => !form.business_hours[day.key].closed).length
)

const normalizeBusinessHours = (hours) => {
    const defaults = createDefaultBusinessHours()
    const normalized = {}

    weekDays.forEach(({ key }) => {
        const day = { ...defaults[key], ...(hours?.[key] || {}) }

        if (day.closed) {
            day.all_day = false
        } else if (day.all_day === undefined || day.all_day === null) {
            day.all_day = day.open === '00:00' && day.close === '23:59'
        }

        if (day.all_day) {
            day.open = '00:00'
            day.close = '23:59'
        }

        normalized[key] = day
    })

    return normalized
}

const toggleAllDay = (dayKey) => {
    const day = form.business_hours[dayKey]
    day.all_day = !day.all_day

    if (day.all_day) {
        day.open = '00:00'
        day.close = '23:59'
    }
}

const syncAllDayFromTimes = (dayKey) => {
    const day = form.business_hours[dayKey]
    if (day.closed) return
    day.all_day = day.open === '00:00' && day.close === '23:59'
}

const toggleDayClosed = (dayKey) => {
    const day = form.business_hours[dayKey]
    day.closed = !day.closed
    if (day.closed) {
        day.all_day = false
    }
}

const enabledPaymentCount = computed(() => form.accepted_payment_methods.length)

const togglePaymentMethod = (key) => {
    if ((key === 'pix_online' || key === 'credit_card_online') && !form.online_payments_enabled) {
        showNotify('Ative o Pix online antes de selecionar pagamentos online.', 'error')
        return
    }

    const index = form.accepted_payment_methods.indexOf(key)

    if (index >= 0) {
        if (form.accepted_payment_methods.length === 1) {
            showNotify('A loja precisa aceitar pelo menos uma forma de pagamento.', 'error')
            return
        }
        form.accepted_payment_methods.splice(index, 1)
        return
    }

    form.accepted_payment_methods.push(key)
}

const normalizeHexColor = (value) => {
    const clean = String(value || '').trim().replace('#', '').toUpperCase()
    if (/^[0-9A-F]{3}$/.test(clean)) return `#${clean.split('').map(c => c + c).join('')}`
    if (/^[0-9A-F]{6}$/.test(clean)) return `#${clean}`
    return null
}

const hexToRgb = (hex) => {
    const normalized = normalizeHexColor(hex)
    if (!normalized) return null
    return {
        red: parseInt(normalized.slice(1, 3), 16),
        green: parseInt(normalized.slice(3, 5), 16),
        blue: parseInt(normalized.slice(5, 7), 16)
    }
}

const rgbToHex = ({ red, green, blue }) =>
    [red, green, blue].map(v => Number(v || 0).toString(16).padStart(2, '0')).join('').toUpperCase()

const clampRgb = (value) => {
    const parsed = Number(value)
    if (Number.isNaN(parsed)) return 0
    return Math.min(255, Math.max(0, Math.round(parsed)))
}

const syncRgbFromHex = (hex) => {
    const rgb = hexToRgb(hex)
    if (!rgb) return
    syncingColor.value = true
    rgbForm.red = rgb.red
    rgbForm.green = rgb.green
    rgbForm.blue = rgb.blue
    syncingColor.value = false
}

watch(() => form.primary_color, (value) => {
    const normalized = normalizeHexColor(value)
    if (!normalized) return
    if (form.primary_color !== normalized) {
        form.primary_color = normalized
        return
    }
    syncRgbFromHex(normalized)
})

watch(rgbForm, () => {
    if (syncingColor.value) return
    rgbForm.red = clampRgb(rgbForm.red)
    rgbForm.green = clampRgb(rgbForm.green)
    rgbForm.blue = clampRgb(rgbForm.blue)
    form.primary_color = `#${rgbToHex(rgbForm)}`
}, { deep: true })

const showNotify = (msg, type = 'success') => {
    toast.value = { show: true, message: msg, type }
    setTimeout(() => { toast.value.show = false }, 4000)
}

const copyMenuUrl = async () => {
    if (!form.slug) {
        showNotify('Informe o slug da loja antes de copiar o link.', 'error')
        return
    }
    try {
        await navigator.clipboard.writeText(publicMenuUrl.value)
        showNotify('Link do cardápio copiado!')
    } catch {
        showNotify('Não foi possível copiar o link.', 'error')
    }
}

const openMenuPreview = () => {
    if (!form.slug) {
        showNotify('Salve um slug válido para abrir o cardápio.', 'error')
        return
    }
    window.open(publicMenuUrl.value, '_blank', 'noopener,noreferrer')
}

const revokePreviewUrl = (url) => {
    if (typeof url === 'string' && url.startsWith('blob:')) {
        URL.revokeObjectURL(url)
    }
}

const handleLogoUpload = (event) => {
    const file = event.target.files[0]
    if (file) {
        revokePreviewUrl(form.logo_url)
        selectedLogoFile.value = file
        form.logo_url = URL.createObjectURL(file)
    }
}

const handleBannerUpload = (event) => {
    const file = event.target.files[0]
    if (file) {
        revokePreviewUrl(form.banner_url)
        selectedBannerFile.value = file
        form.banner_url = URL.createObjectURL(file)
    }
}

const showPixSetupBanner = computed(() => {
    if (!form.online_payments_enabled || !paymentConnection.value) return false

    return !paymentConnection.value.payment_ready
})

const fetchPaymentConnection = async () => {
    if (!canManageBranches.value) return

    try {
        const { data } = await api.get('/merchant/payments/connection')
        paymentConnection.value = data
    } catch {
        paymentConnection.value = null
    }
}

const fetchSetupProgress = async () => {
    try {
        setupProgressLoading.value = true
        const { data } = await api.get('/merchant/store/setup-progress')
        setupProgress.value = data
    } catch {
        setupProgress.value = null
    } finally {
        setupProgressLoading.value = false
    }
}

const scrollToSetupAnchor = (anchor) => {
    const scroll = (attempt = 0) => {
        if (anchor) {
            const el = document.getElementById(anchor)

            if (el) {
                const { height, width } = el.getBoundingClientRect()

                if (height > 0 && width > 0) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
                    return
                }
            }

            if (attempt < 20) {
                requestAnimationFrame(() => scroll(attempt + 1))
            }

            return
        }

        window.scrollTo({ top: 0, behavior: 'smooth' })
    }

    nextTick(() => scroll())
}

const handleSetupSection = (payload) => {
    const target = typeof payload === 'string'
        ? { section: payload, anchor: null }
        : (payload || {})

    const { section, anchor } = target

    if (section) {
        activeSection.value = section
    }

    scrollToSetupAnchor(anchor || null)
}

const fetchStoreData = async () => {
    try {
        loading.value = true
        const { data } = await api.get('/merchant/store')
        const store = data.data || data

        form.name = store.name || ''
        form.slug = store.slug || ''
        form.description = store.description || ''
        form.is_open = Boolean(store.is_open)
        form.address = store.address || ''
        form.delivery_fee = store.delivery_fee || 0
        form.primary_color = store.primary_color || '#EF4444'
        form.secondary_color = store.secondary_color || '#1E293B'
        syncRgbFromHex(form.primary_color)
        form.logo_url = store.logo_url || null
        form.banner_url = store.banner_url || null
        form.instagram_link = store.instagram_link || ''
        form.whatsapp_number = store.whatsapp_number || ''
        form.accepted_payment_methods = Array.isArray(store.accepted_payment_methods) && store.accepted_payment_methods.length
            ? [...store.accepted_payment_methods]
            : (Array.isArray(store.payment_methods) && store.payment_methods.length
                ? [...store.payment_methods]
                : ['pix', 'cash', 'debit_card', 'credit_card'])
        form.online_payments_enabled = Boolean(store.online_payments_enabled)
        if (store.business_hours) {
            form.business_hours = normalizeBusinessHours(store.business_hours)
        }
    } catch {
        showNotify('Erro ao carregar dados da loja.', 'error')
    } finally {
        loading.value = false
    }
}

const handleSave = async () => {
    saving.value = true
    const formData = new FormData()

    formData.append('name', form.name)
    formData.append('slug', form.slug)
    formData.append('description', form.description)
    formData.append('is_open', form.is_open ? 1 : 0)
    formData.append('address', form.address)
    formData.append('delivery_fee', form.delivery_fee)
    formData.append('primary_color', form.primary_color)
    formData.append('secondary_color', form.secondary_color)
    formData.append('instagram_link', form.instagram_link)
    formData.append('whatsapp_number', form.whatsapp_number)
    formData.append('business_hours', JSON.stringify(form.business_hours))
    formData.append('accepted_payment_methods', JSON.stringify(form.accepted_payment_methods))
    formData.append('online_payments_enabled', form.online_payments_enabled ? 1 : 0)

    if (selectedLogoFile.value) formData.append('logo', selectedLogoFile.value)
    if (selectedBannerFile.value) formData.append('banner', selectedBannerFile.value)

    try {
        const { data: responseData } = await postFormData('/merchant/store/update', formData)

        selectedLogoFile.value = null
        selectedBannerFile.value = null

        revokePreviewUrl(form.logo_url)
        revokePreviewUrl(form.banner_url)

        if (responseData?.store) {
            const updated = responseData.store.data || responseData.store

            if (updated.logo_url) form.logo_url = updated.logo_url
            if (updated.banner_url) form.banner_url = updated.banner_url
            if (updated.slug) form.slug = updated.slug
        }

        await fetchStoreData()

        showNotify('Alterações salvas com sucesso!')
        window.dispatchEvent(new CustomEvent('partiumenu:store-updated'))
        await fetchSetupProgress()
    } catch (error) {
        showNotify(getApiErrorMessage(error, 'Erro ao salvar. Verifique os campos.'), 'error')
    } finally {
        saving.value = false
    }
}

const fetchBranches = async () => {
    if (!canManageBranches.value) return
    try {
        const { data } = await api.get('/merchant/stores/branches')
        branches.value = data.branches || []
        branchLimits.value = data.limits || branchLimits.value
    } catch (err) {
        console.error(err)
    }
}

const switchToBranch = async (branchId) => {
    try {
        await api.post('/merchant/stores/switch', { store_id: branchId })
        const { clearCachedUser } = await import('@/composables/useFeatureAccess')
        clearCachedUser()
        showNotify('Filial selecionada. Configure o cardápio desta unidade.')
        window.dispatchEvent(new CustomEvent('partiumenu:store-switched'))
        router.push('/loja')
    } catch (err) {
        showNotify(err.response?.data?.message || 'Erro ao alternar filial.', 'error')
    }
}

const createBranch = async () => {
    if (!branchForm.name.trim()) {
        showNotify('Informe o nome da filial.', 'error')
        return
    }
    creatingBranch.value = true
    try {
        await api.post('/merchant/stores/branches', {
            name: branchForm.name,
            slug: branchForm.slug || undefined
        })
        branchForm.name = ''
        branchForm.slug = ''
        showNotify('Filial criada com sucesso!')
        await fetchBranches()
    } catch (err) {
        showNotify(err.response?.data?.message || 'Erro ao criar filial.', 'error')
    } finally {
        creatingBranch.value = false
    }
}

onMounted(async () => {
    await Promise.all([fetchStoreData(), fetchSetupProgress()])
    try {
        const { fetchCurrentUser } = await import('@/composables/useFeatureAccess')
        const user = await fetchCurrentUser()
        canManageBranches.value = Boolean(user?.permissions?.can_manage_billing)
        if (canManageBranches.value) {
            await fetchBranches()
            await fetchPaymentConnection()
        }
    } catch {
        canManageBranches.value = false
    }
})
</script>

<template>
        <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

        <div class="max-w-5xl mx-auto pb-28 pm-page">

            <!-- Loading -->
            <div v-if="loading" class="py-32 flex flex-col items-center gap-4 text-red-600">
                <Loader2 class="animate-spin" size="36" />
                <p class="text-sm font-bold text-gray-400">Carregando sua loja…</p>
            </div>

            <template v-else>
                <!-- Hero preview -->
                <div class="pm-card overflow-hidden">
                    <div
                        class="relative h-36 sm:h-44 bg-gradient-to-br from-gray-100 to-gray-200"
                        :style="form.banner_url ? {} : { background: `linear-gradient(135deg, ${form.primary_color}22, ${form.primary_color}44)` }"
                    >
                        <img
                            v-if="form.banner_url"
                            :src="form.banner_url"
                            alt="Banner"
                            class="w-full h-full object-cover"
                        />
                        <div class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent" />
                    </div>

                    <div class="relative px-6 pb-6">
                        <div class="flex flex-col sm:flex-row sm:items-end gap-4 -mt-10 sm:-mt-12">
                            <div class="relative w-20 h-20 sm:w-24 sm:h-24 rounded-2xl border-4 border-white shadow-lg overflow-hidden bg-white flex-shrink-0 group">
                                <img v-if="form.logo_url" :src="form.logo_url" class="w-full h-full object-contain p-1" alt="Logo" />
                                <div v-else class="w-full h-full flex items-center justify-center bg-gray-50">
                                    <StoreIcon size="32" class="text-gray-300" />
                                </div>
                                <label class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center cursor-pointer">
                                    <Camera class="text-white" size="22" />
                                    <input type="file" class="hidden" @change="handleLogoUpload" accept="image/*" />
                                </label>
                            </div>

                            <div class="flex-1 min-w-0 pt-1 sm:pt-0 sm:pb-1">
                                <h1 class="text-xl sm:text-2xl font-black text-gray-900 truncate">
                                    {{ form.name || 'Sua loja' }}
                                </h1>
                                <p class="text-xs font-bold text-gray-400 mt-0.5 truncate">{{ publicMenuUrl }}</p>
                            </div>

                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button
                                    id="setup-store-status"
                                    type="button"
                                    @click="form.is_open = !form.is_open"
                                    :class="[
                                        'inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all',
                                        form.is_open
                                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                                            : 'bg-gray-100 text-gray-500 ring-1 ring-gray-200'
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'w-2 h-2 rounded-full',
                                            form.is_open ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'
                                        ]"
                                    />
                                    {{ form.is_open ? 'Aberta' : 'Fechada' }}
                                </button>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <button
                                type="button"
                                @click="copyMenuUrl"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-xs font-black hover:bg-red-600 transition-colors"
                            >
                                <Copy size="14" />
                                Copiar link
                            </button>
                            <button
                                type="button"
                                @click="openMenuPreview"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white text-gray-700 text-xs font-black ring-1 ring-gray-200 hover:ring-red-300 hover:text-red-600 transition-all"
                            >
                                <ExternalLink size="14" />
                                Ver cardápio
                            </button>
                            <div class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-50 text-xs font-bold text-gray-500 ring-1 ring-gray-100">
                                <Clock size="14" class="text-red-500" />
                                {{ openDaysCount }} dias abertos / semana
                            </div>
                            <div
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold ring-1 ring-gray-100"
                                :style="{ backgroundColor: `${form.primary_color}12`, color: form.primary_color }"
                            >
                                <span class="w-3 h-3 rounded-full ring-2 ring-white shadow" :style="{ backgroundColor: form.primary_color }" />
                                Cor do app
                            </div>
                        </div>
                    </div>
                </div>

                <StoreSetupProgressCard
                    :progress="setupProgress"
                    :loading="setupProgressLoading"
                    @go-section="handleSetupSection"
                />

                <!-- Section tabs -->
                <div class="flex gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                    <button
                        v-for="section in sections"
                        :key="section.id"
                        type="button"
                        @click="activeSection = section.id"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all',
                            activeSection === section.id
                                ? 'bg-red-600 text-white shadow-md shadow-red-200'
                                : 'bg-white text-gray-500 ring-1 ring-gray-100 hover:ring-gray-200 hover:text-gray-800'
                        ]"
                    >
                        <component :is="section.icon" size="15" />
                        {{ section.label }}
                    </button>
                </div>

                <!-- IDENTIDADE -->
                <div v-show="activeSection === 'identidade'" class="space-y-5 animate-in">
                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-base font-black text-gray-900">Informações básicas</h2>
                            <p class="text-xs font-bold text-gray-400 mt-1">Nome, link e descrição que seus clientes vão ver.</p>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-5">
                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="text-[11px] font-black text-gray-400 uppercase tracking-wide">Nome da loja</label>
                                <input
                                    id="setup-name"
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Ex: Pizzaria do Centro"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all"
                                />
                            </div>

                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="text-[11px] font-black text-gray-400 uppercase tracking-wide flex items-center gap-1">
                                    <Link2 size="11" class="text-red-500" /> Link do cardápio
                                </label>
                                <div class="flex gap-2">
                                    <div class="flex-1 flex items-center bg-gray-50 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-red-500/30 focus-within:bg-white transition-all">
                                        <span class="pl-4 text-xs font-bold text-gray-400 whitespace-nowrap hidden sm:block">{{ menuAppBaseUrl }}/</span>
                                        <input
                                            v-model="form.slug"
                                            type="text"
                                            placeholder="minha-loja"
                                            class="flex-1 bg-transparent px-3 sm:px-1 py-3.5 text-sm font-black text-gray-800 outline-none lowercase"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        @click="copyMenuUrl"
                                        class="px-4 rounded-xl bg-gray-900 text-white hover:bg-red-600 transition-colors flex-shrink-0"
                                        title="Copiar link"
                                    >
                                        <Copy size="16" />
                                    </button>
                                </div>
                            </div>

                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="text-[11px] font-black text-gray-400 uppercase tracking-wide">Descrição</label>
                                <textarea
                                    id="setup-description"
                                    v-model="form.description"
                                    rows="3"
                                    placeholder="Uma frase curta sobre sua loja…"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all resize-none"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-base font-black text-gray-900">Redes sociais</h2>
                            <p class="text-xs font-bold text-gray-400 mt-1">Links exibidos no cardápio digital.</p>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-5">
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-gray-400 uppercase tracking-wide flex items-center gap-1.5">
                                    <Instagram size="12" class="text-pink-500" /> Instagram
                                </label>
                                <input
                                    v-model="form.instagram_link"
                                    type="text"
                                    placeholder="@sualoja ou link completo"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-gray-400 uppercase tracking-wide flex items-center gap-1.5">
                                    <MessageCircle size="12" class="text-emerald-500" /> WhatsApp
                                </label>
                                <input
                                    v-model="form.whatsapp_number"
                                    type="text"
                                    placeholder="5511999999999"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- VISUAL -->
                <div v-show="activeSection === 'visual'" class="space-y-5 animate-in">
                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-base font-black text-gray-900">Imagens da loja</h2>
                            <p class="text-xs font-bold text-gray-400 mt-1">Logo e banner aparecem no topo do cardápio.</p>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-5">
                            <div class="space-y-3" id="setup-logo">
                                <p class="text-[11px] font-black text-gray-400 uppercase tracking-wide">Logo</p>
                                <div class="relative aspect-square max-w-[180px] rounded-2xl overflow-hidden bg-gray-50 ring-1 ring-gray-100 group">
                                    <img v-if="form.logo_url" :src="form.logo_url" class="w-full h-full object-contain p-1" alt="Logo" />
                                    <div v-else class="w-full h-full flex flex-col items-center justify-center gap-2 text-gray-300">
                                        <StoreIcon size="36" />
                                        <span class="text-[10px] font-black uppercase">Sem logo</span>
                                    </div>
                                    <label class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center gap-1 cursor-pointer">
                                        <Camera class="text-white" size="24" />
                                        <span class="text-[10px] font-black text-white uppercase">Alterar</span>
                                        <input type="file" class="hidden" @change="handleLogoUpload" accept="image/*" />
                                    </label>
                                </div>
                            </div>

                            <div class="space-y-3" id="setup-banner">
                                <p class="text-[11px] font-black text-gray-400 uppercase tracking-wide">Banner</p>
                                <div class="relative aspect-[16/9] rounded-2xl overflow-hidden bg-gray-50 ring-1 ring-gray-100 group">
                                    <img v-if="form.banner_url" :src="form.banner_url" class="w-full h-full object-cover" alt="Banner" />
                                    <div v-else class="w-full h-full flex flex-col items-center justify-center gap-2 text-gray-300">
                                        <Sparkles size="36" />
                                        <span class="text-[10px] font-black uppercase">Sem banner</span>
                                    </div>
                                    <label class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center gap-1 cursor-pointer">
                                        <Camera class="text-white" size="24" />
                                        <span class="text-[10px] font-black text-white uppercase">Alterar</span>
                                        <input type="file" class="hidden" @change="handleBannerUpload" accept="image/*" />
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-base font-black text-gray-900">Cor principal</h2>
                            <p class="text-xs font-bold text-gray-400 mt-1">Usada nos botões e destaques do cardápio.</p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-5">
                            <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl ring-1 ring-gray-100 flex-1">
                                <div class="relative w-14 h-14 rounded-xl overflow-hidden ring-2 ring-white shadow flex-shrink-0">
                                    <input v-model="form.primary_color" type="color" class="absolute -inset-4 w-24 h-24 cursor-pointer" />
                                </div>
                                <input
                                    v-model="form.primary_color"
                                    type="text"
                                    maxlength="7"
                                    class="flex-1 bg-transparent font-mono font-black text-lg text-gray-800 outline-none uppercase"
                                />
                            </div>

                            <div class="grid grid-cols-3 sm:grid-cols-6 lg:grid-cols-9 gap-2 flex-1">
                                <button
                                    v-for="color in presetColors"
                                    :key="color.hex"
                                    type="button"
                                    @click="form.primary_color = color.hex"
                                    :title="color.name"
                                    class="flex flex-col items-center gap-1.5 p-2 rounded-xl ring-1 ring-gray-100 hover:ring-red-200 transition-all group"
                                    :class="form.primary_color === color.hex ? 'ring-2 ring-red-500 bg-red-50' : 'bg-white'"
                                >
                                    <span
                                        class="w-8 h-8 rounded-lg shadow-sm ring-1 ring-black/5"
                                        :style="{ backgroundColor: color.hex }"
                                    />
                                    <span class="text-[9px] font-black text-gray-500 group-hover:text-gray-800 truncate w-full text-center">{{ color.name }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-base font-black text-gray-900">Cor secundária</h2>
                            <p class="text-xs font-bold text-gray-400 mt-1">Complementa a identidade visual. Será usada em barras, menus e detalhes escuros do cardápio.</p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-5">
                            <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl ring-1 ring-gray-100 flex-1">
                                <div class="relative w-14 h-14 rounded-xl overflow-hidden ring-2 ring-white shadow flex-shrink-0">
                                    <input v-model="form.secondary_color" type="color" class="absolute -inset-4 w-24 h-24 cursor-pointer" />
                                </div>
                                <input
                                    v-model="form.secondary_color"
                                    type="text"
                                    maxlength="7"
                                    class="flex-1 bg-transparent font-mono font-black text-lg text-gray-800 outline-none uppercase"
                                />
                            </div>

                            <div class="grid grid-cols-3 sm:grid-cols-6 lg:grid-cols-9 gap-2 flex-1">
                                <button
                                    v-for="color in secondaryPresetColors"
                                    :key="color.hex"
                                    type="button"
                                    @click="form.secondary_color = color.hex"
                                    :title="color.name"
                                    class="flex flex-col items-center gap-1.5 p-2 rounded-xl ring-1 ring-gray-100 hover:ring-gray-200 transition-all group"
                                    :class="form.secondary_color === color.hex ? 'ring-2 ring-gray-800 bg-gray-50' : 'bg-white'"
                                >
                                    <span
                                        class="w-8 h-8 rounded-lg shadow-sm ring-1 ring-black/5"
                                        :style="{ backgroundColor: color.hex }"
                                    />
                                    <span class="text-[9px] font-black text-gray-500 group-hover:text-gray-800 truncate w-full text-center">{{ color.name }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- OPERAÇÃO -->
                <div v-show="activeSection === 'operacao'" class="space-y-5 animate-in">
                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-base font-black text-gray-900">Entrega e endereço</h2>
                            <p class="text-xs font-bold text-gray-400 mt-1">Informações de atendimento e taxa de entrega.</p>
                        </div>

                        <div class="grid sm:grid-cols-3 gap-5">
                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="text-[11px] font-black text-gray-400 uppercase tracking-wide flex items-center gap-1">
                                    <MapPin size="11" class="text-red-500" /> Endereço
                                </label>
                                <input
                                    id="setup-address"
                                    v-model="form.address"
                                    type="text"
                                    placeholder="Rua, número, bairro, cidade"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-red-500 uppercase tracking-wide">Taxa de entrega (R$)</label>
                                <input
                                    v-model="form.delivery_fee"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-black text-gray-800 outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="showPixSetupBanner"
                        class="rounded-3xl border border-amber-200 bg-amber-50 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
                    >
                        <div>
                            <p class="text-sm font-black text-amber-900">Pix online ativo, mas recebimentos não configurados</p>
                            <p class="text-xs font-semibold text-amber-800 mt-1">
                                Conecte sua conta em <strong>Recebimentos</strong> (Pagar.me, Mercado Pago ou Asaas).
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-900 px-4 py-2.5 text-xs font-black text-white hover:bg-amber-950 shrink-0"
                            @click="router.push('/payments')"
                        >
                            Ir para Recebimentos
                            <ExternalLink size="14" />
                        </button>
                    </div>

                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-black text-gray-900">Pix online</h2>
                                <p class="text-xs font-bold text-gray-400 mt-1">
                                    Cliente paga no checkout com QR Code antes de confirmar o pedido.
                                    <button
                                        type="button"
                                        class="ml-1 text-red-500 hover:text-red-600 font-black"
                                        @click="router.push('/payments')"
                                    >
                                        Configurar em Recebimentos →
                                    </button>
                                </p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="form.online_payments_enabled"
                                @click="form.online_payments_enabled = !form.online_payments_enabled"
                                :class="[
                                    'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors',
                                    form.online_payments_enabled ? 'bg-red-500' : 'bg-gray-200'
                                ]"
                            >
                                <span
                                    :class="[
                                        'inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform',
                                        form.online_payments_enabled ? 'translate-x-6' : 'translate-x-1'
                                    ]"
                                />
                            </button>
                        </div>
                    </div>

                    <div id="setup-payments" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-black text-gray-900">Formas de pagamento</h2>
                                <p class="text-xs font-bold text-gray-400 mt-1">
                                    Escolha o que sua loja aceita. Aparece no cardápio e no checkout.
                                </p>
                            </div>
                            <span class="text-xs font-black text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg whitespace-nowrap">
                                {{ enabledPaymentCount }} ativa(s)
                            </span>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <button
                                v-for="method in paymentMethodOptions.filter((item) => !item.onlineOnly || form.online_payments_enabled)"
                                :key="method.key"
                                type="button"
                                @click="togglePaymentMethod(method.key)"
                                :class="[
                                    'flex items-start gap-3 p-4 rounded-2xl text-left transition-all ring-1',
                                    form.accepted_payment_methods.includes(method.key)
                                        ? 'bg-red-50 ring-red-200 shadow-sm'
                                        : 'bg-gray-50 ring-gray-100 opacity-70 hover:opacity-100'
                                ]"
                            >
                                <div
                                    :class="[
                                        'w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0',
                                        form.accepted_payment_methods.includes(method.key)
                                            ? 'bg-red-600 text-white'
                                            : 'bg-white text-gray-400 ring-1 ring-gray-200'
                                    ]"
                                >
                                    <component :is="method.icon" size="18" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-black text-gray-900">{{ method.label }}</p>
                                    <p class="text-xs font-bold text-gray-400 mt-0.5">{{ method.description }}</p>
                                </div>
                                <span
                                    :class="[
                                        'w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5',
                                        form.accepted_payment_methods.includes(method.key)
                                            ? 'bg-red-600 text-white'
                                            : 'bg-white ring-2 ring-gray-200'
                                    ]"
                                >
                                    <CheckCircle v-if="form.accepted_payment_methods.includes(method.key)" size="12" />
                                </span>
                            </button>
                        </div>
                    </div>

                    <div id="setup-hours" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-black text-gray-900">Horários de funcionamento</h2>
                                <p class="text-xs font-bold text-gray-400 mt-1">Toque no dia para marcar como fechado. Marque 24h para funcionamento contínuo.</p>
                            </div>
                            <span class="text-xs font-black text-red-600 bg-red-50 px-3 py-1.5 rounded-lg whitespace-nowrap">
                                {{ openDaysCount }}/7 dias
                            </span>
                        </div>

                        <div class="space-y-2">
                            <div
                                v-for="day in weekDays"
                                :key="day.key"
                                :class="[
                                    'grid grid-cols-[auto_1fr_auto] sm:grid-cols-[100px_1fr_auto] items-center gap-3 p-3 rounded-xl transition-all',
                                    form.business_hours[day.key].closed
                                        ? 'bg-gray-50 opacity-60'
                                        : 'bg-white ring-1 ring-gray-100'
                                ]"
                            >
                                <button
                                    type="button"
                                    @click="toggleDayClosed(day.key)"
                                    :class="[
                                        'w-9 h-9 rounded-lg flex items-center justify-center text-xs font-black transition-all',
                                        form.business_hours[day.key].closed
                                            ? 'bg-gray-200 text-gray-400'
                                            : 'bg-red-600 text-white shadow-sm'
                                    ]"
                                    :title="form.business_hours[day.key].closed ? 'Marcar como aberto' : 'Marcar como fechado'"
                                >
                                    {{ day.label }}
                                </button>

                                <span class="text-sm font-bold text-gray-700 hidden sm:block">{{ day.full }}</span>

                                <div v-if="!form.business_hours[day.key].closed" class="flex items-center gap-2 justify-end flex-wrap">
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer select-none shrink-0">
                                        <input
                                            type="checkbox"
                                            :checked="form.business_hours[day.key].all_day"
                                            class="rounded border-gray-300 text-red-600 focus:ring-red-500/30"
                                            @change="toggleAllDay(day.key)"
                                        />
                                        <span class="text-[10px] font-black text-gray-500 uppercase">24h</span>
                                    </label>

                                    <template v-if="!form.business_hours[day.key].all_day">
                                        <input
                                            v-model="form.business_hours[day.key].open"
                                            type="time"
                                            class="bg-gray-50 rounded-lg px-2 py-1.5 text-xs font-black text-gray-700 outline-none focus:ring-2 focus:ring-red-500/30"
                                            @change="syncAllDayFromTimes(day.key)"
                                        />
                                        <span class="text-[10px] font-black text-gray-300">→</span>
                                        <input
                                            v-model="form.business_hours[day.key].close"
                                            type="time"
                                            class="bg-gray-50 rounded-lg px-2 py-1.5 text-xs font-black text-gray-700 outline-none focus:ring-2 focus:ring-red-500/30"
                                            @change="syncAllDayFromTimes(day.key)"
                                        />
                                    </template>
                                    <span v-else class="text-xs font-bold text-gray-600 whitespace-nowrap">
                                        Aberto 24 horas
                                    </span>
                                </div>
                                <span v-else class="text-[10px] font-black text-gray-400 uppercase tracking-wide text-right">
                                    Fechado
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILIAIS -->
                <div v-show="activeSection === 'filiais' && canManageBranches" class="space-y-5 animate-in">
                    <div id="setup-branches" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-6">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <h2 class="text-base font-black text-gray-900">Filiais</h2>
                                <p class="text-xs font-bold text-gray-400 mt-1">Gerencie unidades adicionais da sua marca.</p>
                                <p
                                    v-if="Number(branchLimits.extra_branch_monthly_price || 0) > 0"
                                    class="mt-2 text-xs font-bold text-gray-500"
                                >
                                    Filial extra além do plano:
                                    <span class="font-black text-gray-900">{{ extraBranchPriceLabel }}</span>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-black text-gray-900">
                                    {{ branchLimits.current_stores }}<span class="text-gray-300">/{{ branchLimits.max_stores }}</span>
                                </p>
                                <p class="text-[10px] font-black text-gray-400 uppercase">lojas no plano</p>
                            </div>
                        </div>

                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :class="branchUsagePercent >= 100 ? 'bg-amber-500' : 'bg-red-500'"
                                :style="{ width: `${branchUsagePercent}%` }"
                            />
                        </div>

                        <div v-if="branches.length" class="space-y-2">
                            <div
                                v-for="branch in branches"
                                :key="branch.id"
                                class="flex items-center gap-4 p-4 rounded-xl bg-gray-50 ring-1 ring-gray-100"
                            >
                                <div class="w-10 h-10 rounded-xl bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0">
                                    <Building2 size="18" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-black text-gray-900 truncate">{{ branch.name }}</p>
                                    <p class="text-xs font-bold text-gray-400 truncate">{{ menuAppBaseUrl }}/{{ branch.slug }}</p>
                                </div>
                                <button
                                    type="button"
                                    @click="switchToBranch(branch.id)"
                                    class="text-[10px] font-black uppercase tracking-widest text-red-600 bg-red-50 px-2.5 py-1 rounded-lg hover:bg-red-100 transition-colors flex-shrink-0"
                                >
                                    Gerenciar
                                </button>
                            </div>
                        </div>

                        <div v-else class="py-8 text-center">
                            <Building2 size="32" class="mx-auto text-gray-200 mb-2" />
                            <p class="text-sm font-bold text-gray-400">Nenhuma filial cadastrada ainda.</p>
                        </div>

                        <div v-if="branchLimits.can_create_branch" class="pt-4 border-t border-gray-100 space-y-4">
                            <p class="text-xs font-black text-gray-500 uppercase tracking-wide">Nova filial</p>
                            <div class="grid sm:grid-cols-2 gap-3">
                                <input
                                    v-model="branchForm.name"
                                    type="text"
                                    placeholder="Nome da filial"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all"
                                />
                                <input
                                    v-model="branchForm.slug"
                                    type="text"
                                    placeholder="link-opcional"
                                    class="w-full bg-gray-50 rounded-xl px-4 py-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-red-500/30 focus:bg-white transition-all lowercase"
                                />
                            </div>
                            <button
                                type="button"
                                @click="createBranch"
                                :disabled="creatingBranch"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-black hover:bg-red-600 transition-colors disabled:opacity-50"
                            >
                                <Loader2 v-if="creatingBranch" class="animate-spin" size="16" />
                                <Plus v-else size="16" />
                                Adicionar filial
                            </button>
                        </div>

                        <div v-else class="flex items-start gap-3 p-4 rounded-xl bg-amber-50 ring-1 ring-amber-100">
                            <Sparkles size="18" class="text-amber-600 flex-shrink-0 mt-0.5" />
                            <div>
                                <p class="text-sm font-black text-amber-800">Limite de lojas atingido</p>
                                <p class="text-xs font-bold text-amber-600 mt-0.5">
                                    Faça upgrade em
                                    <button type="button" class="underline hover:text-amber-900" @click="router.push('/billing')">Meu Plano</button>
                                    ou contrate filial extra por {{ extraBranchPriceLabel }}.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Sticky save bar -->
        <div
            v-if="!loading"
            class="fixed bottom-0 left-64 right-0 z-30 px-6 py-4 bg-white/90 backdrop-blur-md border-t border-gray-100 shadow-[0_-4px_24px_rgba(0,0,0,0.06)]"
        >
            <div class="max-w-5xl mx-auto flex items-center justify-between gap-4">
                <p class="text-xs font-bold text-gray-400 hidden sm:block">
                    Alterações não salvas serão perdidas ao sair da página.
                </p>
                <button
                    type="button"
                    @click="handleSave"
                    :disabled="saving"
                    class="ml-auto inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-red-600 text-white text-sm font-black hover:bg-red-700 transition-all shadow-lg shadow-red-200 disabled:opacity-50 active:scale-[0.98]"
                >
                    <Loader2 v-if="saving" class="animate-spin" size="18" />
                    <Save v-else size="18" />
                    Salvar alterações
                </button>
            </div>
        </div>
</template>

<style scoped>
[id^="setup-"] {
    scroll-margin-top: 6rem;
}

input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
input[type="color"]::-webkit-color-swatch { border-radius: 10px; border: none; }

.scrollbar-none::-webkit-scrollbar { display: none; }
.scrollbar-none { -ms-overflow-style: none; scrollbar-width: none; }

.animate-in { animation: fadeUp 0.25s ease-out; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.toast-enter-active, .toast-leave-active { transition: all 0.25s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(16px); }
</style>
