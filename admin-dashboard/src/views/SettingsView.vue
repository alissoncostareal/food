<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { fetchCurrentUser } from '@/composables/useFeatureAccess'
import {
    Settings, Loader2, CheckCircle, XCircle, User, Mail,
    Volume2, VolumeX, BellRing
} from 'lucide-vue-next'

const loading = ref(true)
const saving = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

const account = ref({ name: '', email: '' })
const newOrderSoundEnabled = ref(true)
const newOrderSoundUnlocked = ref(false)

const showNotify = (msg, type = 'success') => {
    toast.value = { show: true, message: msg, type }
    setTimeout(() => toast.value.show = false, 4000)
}

const syncSoundEvent = (enabled, test = false) => {
    window.dispatchEvent(new CustomEvent('partiumenu:sound-settings-updated', {
        detail: { enabled, test }
    }))
}

const loadPreferences = async () => {
    try {
        loading.value = true
        const [prefsResponse, user] = await Promise.all([
            api.get('/merchant/preferences'),
            fetchCurrentUser()
        ])

        account.value = {
            name: user?.name || '',
            email: user?.email || ''
        }

        const prefs = prefsResponse.data.preferences || {}
        newOrderSoundEnabled.value = prefs.new_order_sound_enabled !== false
        newOrderSoundUnlocked.value = Boolean(prefs.new_order_sound_unlocked)

        localStorage.setItem('partiumenu:new-order-sound-enabled', newOrderSoundEnabled.value ? 'true' : 'false')
        localStorage.setItem('partiumenu:new-order-sound-unlocked', newOrderSoundUnlocked.value ? 'true' : 'false')
    } catch (err) {
        console.error(err)
        showNotify('Erro ao carregar preferências.', 'error')
    } finally {
        loading.value = false
    }
}

const persistPreferences = async (payload, test = false) => {
    saving.value = true

    try {
        const { data } = await api.patch('/merchant/preferences', payload)
        const prefs = data.preferences || {}

        newOrderSoundEnabled.value = prefs.new_order_sound_enabled !== false
        newOrderSoundUnlocked.value = Boolean(prefs.new_order_sound_unlocked)

        localStorage.setItem('partiumenu:new-order-sound-enabled', newOrderSoundEnabled.value ? 'true' : 'false')
        localStorage.setItem('partiumenu:new-order-sound-unlocked', newOrderSoundUnlocked.value ? 'true' : 'false')

        syncSoundEvent(newOrderSoundEnabled.value, test)

        if (!newOrderSoundEnabled.value) {
            showNotify('Som de novos pedidos desativado.', 'error')
        } else if (test) {
            showNotify('Som de novos pedidos ativado.')
        }
    } catch (err) {
        console.error(err)
        showNotify('Erro ao salvar preferências.', 'error')
    } finally {
        saving.value = false
    }
}

const updateNewOrderSound = (enabled) => {
    persistPreferences({ new_order_sound_enabled: enabled })
}

const testNewOrderSound = () => {
    persistPreferences({
        new_order_sound_enabled: true,
        new_order_sound_unlocked: true
    }, true)
}

onMounted(loadPreferences)
</script>

<template>
        <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

        <div class="max-w-3xl mx-auto pm-page">
            <PageHeader
                title="Configurações"
                subtitle="Conta e preferências pessoais do painel."
            >
                <template #icon>
                    <Settings size="26" />
                </template>
            </PageHeader>

            <div v-if="loading" class="pm-loading">
                <Loader2 class="animate-spin" size="40" />
            </div>

            <div v-else class="space-y-8">
                <section class="pm-card p-8 space-y-6">
                    <h3 class="pm-label flex items-center gap-2 text-slate-900">
                        <User size="18" class="text-red-600" /> Minha conta
                    </h3>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div class="space-y-1">
                            <label class="pm-label ml-1">Nome</label>
                            <div class="w-full bg-gray-50 rounded-2xl p-4 font-bold text-slate-700">{{ account.name }}</div>
                        </div>
                        <div class="space-y-1">
                            <label class="pm-label ml-1 flex items-center gap-1">
                                <Mail size="12" /> E-mail
                            </label>
                            <div class="w-full bg-gray-50 rounded-2xl p-4 font-bold text-slate-700">{{ account.email }}</div>
                        </div>
                    </div>
                </section>

                <section class="pm-card p-8 space-y-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <h3 class="pm-label flex items-center gap-2 text-slate-900">
                                <BellRing size="18" class="text-red-600" /> Notificações de pedidos
                            </h3>
                            <p class="mt-2 text-sm font-bold text-slate-500">
                                Preferência salva na sua conta — vale em qualquer dispositivo onde você entrar.
                            </p>
                        </div>

                        <button
                            type="button"
                            @click="updateNewOrderSound(!newOrderSoundEnabled)"
                            :disabled="saving"
                            :class="[
                                'relative h-9 w-16 rounded-full transition-all',
                                newOrderSoundEnabled ? 'bg-red-600' : 'bg-gray-200'
                            ]"
                        >
                            <span
                                :class="[
                                    'absolute top-1 h-7 w-7 rounded-full bg-white shadow transition-all',
                                    newOrderSoundEnabled ? 'left-8' : 'left-1'
                                ]"
                            ></span>
                        </button>
                    </div>

                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div
                                :class="[
                                    'w-11 h-11 rounded-2xl flex items-center justify-center',
                                    newOrderSoundEnabled ? 'bg-red-50 text-red-600' : 'bg-gray-100 text-gray-400'
                                ]"
                            >
                                <Volume2 v-if="newOrderSoundEnabled" size="22" />
                                <VolumeX v-else size="22" />
                            </div>

                            <div>
                                <p class="text-sm font-black text-slate-900">
                                    {{ newOrderSoundEnabled ? 'Som ativado' : 'Som desativado' }}
                                </p>
                                <p class="text-xs font-bold text-slate-500">
                                    {{ newOrderSoundEnabled
                                        ? 'Alerta “trrrim” de cozinha; repete a cada ~22s enquanto houver pedido recebido. Clique na página e use Testar som.'
                                        : 'Você não receberá aviso sonoro de novos pedidos.' }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="testNewOrderSound"
                            :disabled="saving"
                            class="pm-btn-dark text-xs disabled:opacity-50"
                        >
                            <Volume2 size="16" />
                            Testar som
                        </button>
                    </div>
                </section>
            </div>
        </div>
</template>
