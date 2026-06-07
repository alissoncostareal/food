<script setup>
import { ref, onMounted, reactive } from 'vue'
import api from '@/services/api'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
    Settings, Save, Instagram, MessageCircle, MapPin, Palette,
    Clock, Loader2, CheckCircle, XCircle, Camera, Calendar, Link2, StoreIcon
} from 'lucide-vue-next'

const loading = ref(true)
const saving = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

// Criamos referências para guardar os arquivos brutos (binários) selecionados
const selectedLogoFile = ref(null)
const selectedBannerFile = ref(null)

const weekDays = [
    { key: 'monday', label: 'Segunda' }, { key: 'tuesday', label: 'Terça' },
    { key: 'wednesday', label: 'Quarta' }, { key: 'thursday', label: 'Quinta' },
    { key: 'friday', label: 'Sexta' }, { key: 'saturday', label: 'Sábado' },
    { key: 'sunday', label: 'Domingo' }
]

const form = reactive({
    name: '',
    slug: '',
    description: '',
    is_open: true,
    address: '',
    delivery_fee: 0,
    primary_color: '#EF4444',
    instagram_link: '',
    whatsapp_number: '',
    logo_url: null,
    banner_url: null,
    business_hours: {
        monday: { open: '08:00', close: '22:00', closed: false },
        tuesday: { open: '08:00', close: '22:00', closed: false },
        wednesday: { open: '08:00', close: '22:00', closed: false },
        thursday: { open: '08:00', close: '22:00', closed: false },
        friday: { open: '08:00', close: '22:00', closed: false },
        saturday: { open: '08:00', close: '22:00', closed: false },
        sunday: { open: '08:00', close: '18:00', closed: true }
    }
})

const showNotify = (msg, type = 'success') => {
    toast.value = { show: true, message: msg, type }
    setTimeout(() => toast.value.show = false, 4000)
}

const handleLogoUpload = (event) => {
    const file = event.target.files[0]
    if (file) {
        selectedLogoFile.value = file
        form.logo_url = URL.createObjectURL(file) 
    }
}

const handleBannerUpload = (event) => {
    const file = event.target.files[0]
    if (file) {
        selectedBannerFile.value = file 
        form.banner_url = URL.createObjectURL(file)
    }
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
        form.logo_url = store.logo_url || null
        form.banner_url = store.banner_url || null
        form.instagram_link = store.instagram_link || ''
        form.whatsapp_number = store.whatsapp_number || ''

        if (store.business_hours) {
            form.business_hours = store.business_hours
        }
    } catch (err) {
        console.error(err)
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
    formData.append('instagram_link', form.instagram_link)
    formData.append('whatsapp_number', form.whatsapp_number)
    formData.append('business_hours', JSON.stringify(form.business_hours))

    // Corrigido: Agora injeta de forma independente baseado nas variáveis reativas capturadas pelos inputs
    if (selectedLogoFile.value) {
        formData.append('logo', selectedLogoFile.value)
    }

    if (selectedBannerFile.value) {
        formData.append('banner', selectedBannerFile.value)
    }

    try {
        const response = await api.post('/merchant/store/update', formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        // Limpa os arquivos temporários após salvar com sucesso
        selectedLogoFile.value = null
        selectedBannerFile.value = null

        if (response.data.store) {
            const updatedStore = response.data.store
            if (updatedStore.logo_url) form.logo_url = updatedStore.logo_url
            if (updatedStore.banner_url) form.banner_url = updatedStore.banner_url
            if (updatedStore.slug) form.slug = updatedStore.slug
        }

        showNotify('Configurações salvas com sucesso!')
    } catch (err) {
        console.error(err)
        showNotify('Erro ao salvar.', 'error')
    } finally {
        saving.value = false
    }
}

onMounted(fetchStoreData)
</script>

<template>
    <DashboardLayout>
        <div v-if="toast.show" class="fixed top-5 right-5 z-[100] animate-in slide-in-from-right">
            <div :class="['px-6 py-3 rounded-2xl shadow-lg font-black text-white flex items-center gap-3',
                toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-600']">
                <CheckCircle v-if="toast.type === 'success'" />
                <XCircle v-else />
                {{ toast.message }}
            </div>
        </div>

        <div class="max-w-6xl mx-auto space-y-8 pb-20 animate-in fade-in">

            <header
                class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-red-50 shadow-sm sticky top-4 z-40 backdrop-blur-md bg-white/90">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-red-100">
                        <Settings size="28" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-black text-gray-900 leading-none">Minha Loja</h1>
                        <p class="text-gray-500 text-xs mt-1 font-bold">Configure a identidade visual e o funcionamento.
                        </p>
                    </div>
                </div>
                <button @click="handleSave" :disabled="saving"
                    class="bg-red-600 hover:bg-red-700 text-white px-8 py-3 rounded-2xl font-black flex items-center gap-2 transition-all shadow-lg shadow-red-100 disabled:opacity-50 active:scale-95">
                    <Loader2 v-if="saving" class="animate-spin" size="20" />
                    <Save v-else size="20" />
                    Salvar Tudo
                </button>
            </header>

            <div v-if="loading" class="p-20 flex justify-center text-red-600">
                <Loader2 class="animate-spin" size="40" />
            </div>

            <div v-else class="grid lg:grid-cols-12 gap-8">

                <div class="lg:col-span-4 space-y-8">
                    <section class="bg-white p-8 rounded-3xl border border-gray-100 shadow-sm text-center">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-6">Logo Marca
                        </h3>
                        <div class="relative w-40 h-40 mx-auto group">
                            <div
                                class="w-full h-full rounded-3xl bg-gray-50 border-4 border-white shadow-xl overflow-hidden flex items-center justify-center relative group-hover:border-red-50 transition-all">
                                <img v-if="form.logo_url" :src="form.logo_url" class="w-full h-full object-cover" />
                                <StoreIcon v-else size="48" class="text-gray-200" />
                                <label
                                    class="absolute inset-0 bg-red-600/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center cursor-pointer">
                                    <Camera class="text-white" size="32" />
                                    <input type="file" class="hidden" @change="handleLogoUpload" accept="image/*" />
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="bg-white p-8 rounded-3xl border border-gray-100 shadow-sm text-center">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-6">Banner da Loja
                        </h3>
                        <div class="relative w-40 h-40 mx-auto group">
                            <div
                                class="w-full h-full rounded-3xl bg-gray-50 border-4 border-white shadow-xl overflow-hidden flex items-center justify-center relative group-hover:border-red-50 transition-all">
                                <img v-if="form.banner_url" :src="form.banner_url" class="w-full h-full object-cover" />
                                <StoreIcon v-else size="48" class="text-gray-200" />
                                <label
                                    class="absolute inset-0 bg-red-600/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center cursor-pointer">
                                    <Camera class="text-white" size="32" />
                                    <input type="file" class="hidden" @change="handleBannerUpload" accept="image/*" />
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-4">
                            Cor do Aplicativo
                        </h3>
                        
                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-2xl border border-gray-100">
                            <div class="relative w-12 h-12 rounded-xl overflow-hidden shadow-sm flex-shrink-0 border border-gray-200">
                                <input 
                                    v-model="form.primary_color" 
                                    type="color"
                                    class="absolute -inset-4 w-20 h-20 cursor-pointer" 
                                />
                            </div>
                            
                            <div class="flex-1 flex items-center">
                                <input 
                                    v-model="form.primary_color" 
                                    type="text" 
                                    maxlength="7"
                                    placeholder="#000000"
                                    class="w-full bg-transparent border-none font-mono font-black text-gray-700 focus:ring-0 uppercase text-lg p-0"
                                />
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button 
                                v-for="color in ['#E7000D', '#2563EB', '#16A34A', '#D97706', '#9333EA', '#111827']" 
                                :key="color"
                                type="button"
                                @click="form.primary_color = color"
                                class="w-8 h-8 rounded-full border-2 border-white shadow-sm ring-1 ring-gray-100 hover:scale-110 transition-transform focus:outline-none focus:ring-2 focus:ring-gray-400"
                                :style="{ backgroundColor: color }"
                                :title="color"
                            ></button>
                        </div>
                    </section>
                </div>

                <div class="lg:col-span-8 space-y-8">

                    <section class="bg-white p-8 rounded-3xl border border-gray-100 shadow-sm space-y-6">
                        <h3 class="text-xs font-black text-gray-900 uppercase flex items-center gap-2">
                            <StoreIcon size="18" class="text-red-600" /> Informações do Perfil
                        </h3>

                        <div class="space-y-4">
                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-gray-400 uppercase ml-1">Nome da Loja</label>
                                    <input v-model="form.name" type="text" placeholder="Ex: Mc Donalds"
                                        class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-bold outline-none" />
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-gray-400 uppercase ml-1 flex items-center gap-1.5 text-red-600">
                                        <Link2 size="12" /> Link da Loja (Slug URL)
                                    </label>
                                    <input v-model="form.slug" type="text" placeholder="Ex: mc-donalds"
                                        class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-black outline-none lowercase" />
                                </div>
                            </div>
                            
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-gray-400 uppercase ml-1">Descrição /
                                    Slogan</label>
                                <textarea v-model="form.description" rows="3"
                                    placeholder="Conte um pouco sobre sua loja..."
                                    class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-bold outline-none"></textarea>
                            </div>
                        </div>

                        <div class="grid md:grid-cols-2 gap-6 pt-4 border-t border-gray-50">
                            <div class="space-y-1">
                                <label
                                    class="text-[10px] font-black text-gray-400 uppercase ml-1 flex items-center gap-2">
                                    <Instagram size="12" class="text-red-600" /> Instagram
                                </label>
                                <input v-model="form.instagram_link" type="text" placeholder="@loja_oficial"
                                    class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-bold outline-none" />
                            </div>
                            <div class="space-y-1">
                                <label
                                    class="text-[10px] font-black text-gray-400 uppercase ml-1 flex items-center gap-2">
                                    <MessageCircle size="12" class="text-emerald-500" /> WhatsApp
                                </label>
                                <input v-model="form.whatsapp_number" type="text" placeholder="55859..."
                                    class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-bold outline-none" />
                            </div>
                        </div>
                    </section>

                    <section class="bg-white p-8 rounded-3xl border border-gray-100 shadow-sm space-y-6">
                        <h3 class="text-xs font-black text-gray-900 uppercase flex items-center gap-2">
                            <MapPin size="18" class="text-red-600" /> Entrega e Localização
                        </h3>
                        <div class="grid md:grid-cols-3 gap-6">
                            <div class="md:col-span-2 space-y-1">
                                <label class="text-[10px] font-black text-gray-400 uppercase ml-1">Endereço de
                                    Atendimento</label>
                                <input v-model="form.address" type="text"
                                    class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-bold text-sm outline-none" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-gray-400 uppercase ml-1 text-red-600">Taxa de Entrega
                                    (R$)</label>
                                <input v-model="form.delivery_fee" type="number" step="0.01"
                                    class="w-full bg-gray-50 border-none rounded-2xl p-4 focus:ring-2 focus:ring-red-600 font-black outline-none" />
                            </div>
                        </div>
                    </section>

                    <section class="bg-white p-8 rounded-3xl border border-gray-100 shadow-sm space-y-6">
                        <h3 class="text-xs font-black text-gray-900 uppercase flex items-center gap-2">
                            <Calendar size="18" class="text-red-600" /> Horários de Funcionamento
                        </h3>
                        <div class="space-y-3">
                            <div v-for="day in weekDays" :key="day.key"
                                class="flex flex-col md:flex-row md:items-center justify-between p-4 rounded-2xl border border-gray-50"
                                :class="form.business_hours[day.key].closed ? 'bg-gray-50/50 opacity-60' : 'bg-white shadow-sm'">

                                <div class="flex items-center gap-4 min-w-[140px]">
                                    <button
                                        @click="form.business_hours[day.key].closed = !form.business_hours[day.key].closed"
                                        :class="['w-10 h-10 rounded-xl flex items-center justify-center transition-all',
                                            form.business_hours[day.key].closed ? 'bg-gray-200 text-gray-500' : 'bg-red-600 text-white']">
                                        <Calendar size="18" />
                                    </button>
                                    <span class="font-black text-gray-700">{{ day.label }}</span>
                                </div>

                                <div class="flex items-center gap-4 mt-3 md:mt-0">
                                    <template v-if="!form.business_hours[day.key].closed">
                                        <input v-model="form.business_hours[day.key].open" type="time"
                                            class="bg-gray-50 border-none rounded-xl px-4 py-2 font-black text-gray-700 outline-none" />
                                        <span class="text-gray-300 font-black text-xs uppercase">Até</span>
                                        <input v-model="form.business_hours[day.key].close" type="time"
                                            class="bg-gray-50 border-none rounded-xl px-4 py-2 font-black text-gray-700 outline-none" />
                                    </template>
                                    <span v-else
                                        class="text-[10px] font-black text-red-600 uppercase tracking-widest px-4">Não
                                        abre neste dia</span>
                                </div>
                            </div>
                        </div>
                    </section>

                </div>
            </div>
        </div>
    </DashboardLayout>
</template>

<style scoped>
input[type="color"]::-webkit-color-swatch-wrapper {
    padding: 0;
}

input[type="color"]::-webkit-color-swatch {
    border-radius: 12px;
    border: none;
}
</style>