<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import { fetchCurrentUser } from '@/composables/useFeatureAccess'
import { useOnStoreSwitch } from '@/composables/useOnStoreSwitch'
import {
  ArrowLeft,
  ArrowUpRight,
  CheckCircle,
  ChevronRight,
  CreditCard,
  Landmark,
  Loader2,
  Plug,
  QrCode,
  Trash2,
  Wallet
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const canConfigure = ref(false)
const connection = ref(null)
const providerForms = reactive({})
const submittingProvider = ref(null)
const selectedProvider = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const providerMeta = {
  pagarme: { icon: CreditCard, accent: 'bg-violet-50 text-violet-700 ring-violet-100' },
  mercadopago: { icon: Landmark, accent: 'bg-sky-50 text-sky-700 ring-sky-100' },
  asaas: { icon: Wallet, accent: 'bg-emerald-50 text-emerald-700 ring-emerald-100' }
}

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => { toast.value.show = false }, 3500)
}

const catalog = computed(() => connection.value?.providers_catalog || [])
const connections = computed(() => connection.value?.connections || [])

const connectedProviders = computed(() =>
  catalog.value.filter((item) => connectionFor(item.provider))
)

const selectedCatalogItem = computed(() =>
  catalog.value.find((item) => item.provider === selectedProvider.value) || null
)

const selectedConnection = computed(() =>
  selectedProvider.value ? connectionFor(selectedProvider.value) : null
)

const statusClass = computed(() => {
  if (connection.value?.payment_ready) return 'border-emerald-100 bg-emerald-50 text-emerald-700'
  return 'border-slate-200 bg-slate-50 text-slate-600'
})

const pixOnlineEnabled = computed({
  get: () => Boolean(connection.value?.online_payments_enabled),
  set: (value) => {
    if (connection.value) connection.value.online_payments_enabled = value
  }
})

const connectionFor = (provider) => connections.value.find((item) => item.provider === provider)

const initProviderForm = (providerKey) => {
  const item = catalog.value.find((entry) => entry.provider === providerKey)
  const method = item?.connection_methods?.[0]
  if (!method) return

  providerForms[providerKey] = {
    connection_method: method.key,
    credentials: Object.fromEntries(
      Object.entries(method.fields || {}).map(([key, field]) => [
        key,
        field.type === 'select' ? (field.options?.[0] || 'sandbox') : ''
      ])
    )
  }
}

const openProvider = (providerKey) => {
  selectedProvider.value = providerKey
  if (!connectionFor(providerKey)) {
    initProviderForm(providerKey)
  }
}

const closeProviderPanel = () => {
  selectedProvider.value = null
}

const fetchConnection = async () => {
  try {
    loading.value = true
    const { data } = await api.get('/merchant/payments/connection')
    connection.value = data

    for (const item of data.providers_catalog || []) {
      initProviderForm(item.provider)
    }

    if (selectedProvider.value && !catalog.value.some((item) => item.provider === selectedProvider.value)) {
      selectedProvider.value = null
    }
  } catch (error) {
    if (error.response?.status === 403) {
      router.push('/dashboard')
      return
    }
    showNotify('Erro ao carregar recebimentos.', 'error')
  } finally {
    loading.value = false
  }
}

const saveProvider = async (providerKey) => {
  const form = providerForms[providerKey]
  if (!form) return

  try {
    submittingProvider.value = providerKey
    const { data } = await api.post(`/merchant/payments/providers/${providerKey}`, {
      connection_method: form.connection_method,
      credentials: form.credentials,
      activate: true
    })
    connection.value = data.payments
    showNotify(data.message || 'Gateway conectado.')
    window.dispatchEvent(new CustomEvent('partiumenu:store-updated'))
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao conectar gateway.', 'error')
  } finally {
    submittingProvider.value = null
  }
}

const activateProvider = async (providerKey) => {
  try {
    submittingProvider.value = providerKey
    const { data } = await api.post(`/merchant/payments/providers/${providerKey}/activate`)
    connection.value = data.payments
    showNotify(data.message || 'Gateway ativado.')
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao ativar gateway.', 'error')
  } finally {
    submittingProvider.value = null
  }
}

const disconnectProvider = async (providerKey) => {
  if (!confirm('Desconectar este gateway? Pix online será desativado se estiver em uso.')) return

  try {
    submittingProvider.value = providerKey
    const { data } = await api.delete(`/merchant/payments/providers/${providerKey}`)
    connection.value = data.payments
    if (selectedProvider.value === providerKey) selectedProvider.value = null
    showNotify(data.message || 'Gateway desconectado.')
    window.dispatchEvent(new CustomEvent('partiumenu:store-updated'))
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao desconectar.', 'error')
  } finally {
    submittingProvider.value = null
  }
}

const saveSettings = async () => {
  try {
    saving.value = true
    const { data } = await api.put('/merchant/payments/settings', {
      online_payments_enabled: pixOnlineEnabled.value
    })
    connection.value = data.payments || data
    showNotify(data.message || 'Configurações salvas.')
    window.dispatchEvent(new CustomEvent('partiumenu:store-updated'))
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao salvar.', 'error')
  } finally {
    saving.value = false
  }
}

const loadPage = async () => {
  selectedProvider.value = null
  try {
    const user = await fetchCurrentUser({ force: true })
    canConfigure.value = Boolean(user?.permissions?.can_manage_billing || user?.role === 'store_owner')
  } catch {
    canConfigure.value = false
  }

  await fetchConnection()
}

watch(selectedProvider, (value) => {
  if (value && !connectionFor(value)) {
    initProviderForm(value)
  }
})

onMounted(loadPage)
useOnStoreSwitch(loadPage)
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <header class="pm-page-header">
        <div class="flex items-center gap-4">
          <div class="pm-page-icon">
            <Wallet size="26" />
          </div>
          <div>
            <h1 class="pm-page-title">Recebimentos</h1>
            <p class="pm-page-subtitle">
              Escolha a empresa de pagamento e conecte as chaves da sua loja.
            </p>
          </div>
        </div>

        <div
          v-if="!loading && connection"
          :class="['rounded-2xl border px-4 py-3 text-xs font-black uppercase tracking-wider', statusClass]"
        >
          {{ connection.status_label }}
        </div>
      </header>

      <div v-if="loading" class="flex justify-center py-16">
        <Loader2 class="animate-spin text-emerald-600" size="32" />
      </div>

      <template v-else-if="connection">
        <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_300px]">
          <div class="space-y-5">
            <!-- Contas já conectadas -->
            <article v-if="connectedProviders.length" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <h2 class="text-sm font-black uppercase tracking-wider text-slate-400">Contas conectadas</h2>
              <div class="mt-4 space-y-2">
                <div
                  v-for="provider in connectedProviders"
                  :key="provider.provider"
                  class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3"
                >
                  <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-3 text-left"
                    @click="openProvider(provider.provider)"
                  >
                    <div
                      :class="[
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1',
                        providerMeta[provider.provider]?.accent || 'bg-slate-100 text-slate-600 ring-slate-200'
                      ]"
                    >
                      <component :is="providerMeta[provider.provider]?.icon || Wallet" size="18" />
                    </div>
                    <div class="min-w-0">
                      <p class="text-sm font-black text-slate-900">{{ provider.label }}</p>
                      <p class="truncate text-xs font-semibold text-slate-500">
                        {{ connectionFor(provider.provider)?.account_label }}
                      </p>
                    </div>
                    <span
                      v-if="connectionFor(provider.provider)?.is_active_for_pix"
                      class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-black uppercase text-emerald-700"
                    >
                      Ativo
                    </span>
                  </button>

                  <div v-if="canConfigure" class="flex gap-2">
                    <button
                      v-if="!connectionFor(provider.provider)?.is_active_for_pix"
                      type="button"
                      class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white"
                      :disabled="submittingProvider === provider.provider"
                      @click.stop="activateProvider(provider.provider)"
                    >
                      Usar no Pix
                    </button>
                    <button
                      type="button"
                      class="rounded-xl border border-slate-200 bg-white p-2 text-slate-500 hover:text-red-600"
                      :disabled="submittingProvider === provider.provider"
                      @click.stop="disconnectProvider(provider.provider)"
                    >
                      <Trash2 size="14" />
                    </button>
                  </div>
                </div>
              </div>
            </article>

            <!-- Escolher empresa OU painel de chaves -->
            <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
              <template v-if="!selectedProvider">
                <div>
                  <h2 class="text-lg font-black text-slate-900">Escolha a empresa</h2>
                  <p class="mt-1 text-sm font-semibold text-slate-500">
                    Selecione onde sua loja já tem conta para receber Pix online.
                  </p>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                  <button
                    v-for="provider in catalog"
                    :key="provider.provider"
                    type="button"
                    :disabled="!canConfigure"
                    class="group rounded-2xl border border-slate-200 bg-white p-4 text-left transition-all hover:border-slate-300 hover:shadow-sm disabled:opacity-60"
                    @click="openProvider(provider.provider)"
                  >
                    <div
                      :class="[
                        'mb-3 flex h-11 w-11 items-center justify-center rounded-xl ring-1',
                        providerMeta[provider.provider]?.accent || 'bg-slate-50 text-slate-600 ring-slate-200'
                      ]"
                    >
                      <component :is="providerMeta[provider.provider]?.icon || Wallet" size="20" />
                    </div>
                    <p class="text-sm font-black text-slate-900">{{ provider.label }}</p>
                    <p class="mt-1 line-clamp-2 text-xs font-semibold text-slate-500">
                      {{ provider.description }}
                    </p>
                    <div class="mt-3 flex items-center justify-between">
                      <span
                        v-if="connectionFor(provider.provider)"
                        class="text-[10px] font-black uppercase text-emerald-600"
                      >
                        Conectado
                      </span>
                      <span v-else class="text-[10px] font-black uppercase text-slate-400">
                        Configurar
                      </span>
                      <ChevronRight
                        size="16"
                        class="text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500"
                      />
                    </div>
                  </button>
                </div>
              </template>

              <template v-else-if="selectedCatalogItem">
                <button
                  type="button"
                  class="mb-5 inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-slate-800"
                  @click="closeProviderPanel"
                >
                  <ArrowLeft size="14" />
                  Voltar para empresas
                </button>

                <div class="flex items-start gap-3">
                  <div
                    :class="[
                      'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1',
                      providerMeta[selectedProvider]?.accent || 'bg-slate-50 text-slate-600 ring-slate-200'
                    ]"
                  >
                    <component :is="providerMeta[selectedProvider]?.icon || Wallet" size="22" />
                  </div>
                  <div>
                    <h2 class="text-lg font-black text-slate-900">{{ selectedCatalogItem.label }}</h2>
                    <p class="mt-1 text-sm font-semibold text-slate-500">{{ selectedCatalogItem.description }}</p>
                  </div>
                </div>

                <!-- Conta já conectada: detalhes -->
                <div
                  v-if="selectedConnection"
                  class="mt-6 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-4"
                >
                  <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p class="text-sm font-black text-emerald-900">
                        {{ selectedConnection.account_label || 'Conta conectada' }}
                      </p>
                      <p class="mt-1 text-xs font-semibold text-emerald-700">
                        {{ selectedConnection.status_label }}
                        · {{ selectedConnection.connection_method_label }}
                      </p>
                      <p
                        v-if="selectedConnection.is_active_for_pix"
                        class="mt-2 inline-flex items-center gap-1 text-xs font-black text-emerald-800"
                      >
                        <CheckCircle size="14" />
                        Gateway ativo para Pix online
                      </p>
                    </div>

                    <div v-if="canConfigure" class="flex gap-2">
                      <button
                        v-if="!selectedConnection.is_active_for_pix"
                        type="button"
                        class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white"
                        :disabled="submittingProvider === selectedProvider"
                        @click="activateProvider(selectedProvider)"
                      >
                        Usar no Pix
                      </button>
                      <button
                        type="button"
                        class="rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-black text-emerald-800"
                        :disabled="submittingProvider === selectedProvider"
                        @click="disconnectProvider(selectedProvider)"
                      >
                        Desconectar
                      </button>
                    </div>
                  </div>
                </div>

                <div
                  v-if="selectedConnection && selectedCatalogItem?.webhook_url"
                  class="mt-4 rounded-2xl border border-amber-100 bg-amber-50 px-4 py-4"
                >
                  <p class="text-xs font-black uppercase tracking-wider text-amber-700">URL do webhook</p>
                  <code class="mt-2 block break-all rounded-xl bg-white px-3 py-2 text-xs font-mono text-slate-800 ring-1 ring-amber-100">
                    {{ selectedCatalogItem.webhook_url }}
                  </code>
                  <p
                    v-if="selectedProvider === 'pagarme'"
                    class="mt-2 text-xs font-semibold text-amber-800"
                  >
                    Reconecte o Pagar.me com o Webhook secret se ainda não configurou.
                  </p>
                </div>

                <!-- Formulário de chaves -->
                <div v-else-if="canConfigure" class="mt-6 space-y-4">
                  <div
                    v-if="selectedCatalogItem?.webhook_url"
                    class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-4"
                  >
                    <p class="text-xs font-black uppercase tracking-wider text-amber-700">Webhook (obrigatório em produção)</p>
                    <p class="mt-2 text-sm font-semibold text-amber-900">
                      Cadastre esta URL no painel do {{ selectedCatalogItem.label }} para confirmar Pix e cartão automaticamente:
                    </p>
                    <code class="mt-2 block break-all rounded-xl bg-white px-3 py-2 text-xs font-mono text-slate-800 ring-1 ring-amber-100">
                      {{ selectedCatalogItem.webhook_url }}
                    </code>
                    <p
                      v-if="selectedProvider === 'pagarme'"
                      class="mt-2 text-xs font-semibold text-amber-800"
                    >
                      Copie o <strong>Webhook secret</strong> gerado no Pagar.me e cole no campo abaixo.
                    </p>
                    <p
                      v-else-if="selectedProvider === 'asaas'"
                      class="mt-2 text-xs font-semibold text-amber-800"
                    >
                      O Asaas envia o token de autenticação automaticamente usando a API Key da sua conta.
                    </p>
                    <p
                      v-else-if="selectedProvider === 'mercadopago'"
                      class="mt-2 text-xs font-semibold text-amber-800"
                    >
                      O Mercado Pago confirma o pagamento consultando a API com o Access Token da loja.
                    </p>
                  </div>

                  <div class="rounded-2xl py-3">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-400">Chaves da API</p>
                    <p class="mt-1 text-sm font-semibold text-slate-600">
                      Cole as credenciais da conta comercial da sua loja no {{ selectedCatalogItem.label }}.
                    </p>
                  </div>

                  <template v-for="method in selectedCatalogItem.connection_methods" :key="method.key">
                    <div class="space-y-3">
                      <div
                        v-for="(field, fieldKey) in method.fields"
                        :key="fieldKey"
                        class="space-y-1.5"
                      >
                        <label class="text-xs font-bold text-slate-600">{{ field.label }}</label>

                        <p v-if="field.hint" class="text-xs font-semibold text-slate-500">{{ field.hint }}</p>

                        <select
                          v-if="field.type === 'select'"
                          v-model="providerForms[selectedProvider].credentials[fieldKey]"
                          class="pm-input w-full"
                        >
                          <option v-for="option in field.options" :key="option" :value="option">
                            {{ option }}
                          </option>
                        </select>

                        <input
                          v-else
                          v-model="providerForms[selectedProvider].credentials[fieldKey]"
                          :type="field.type === 'password' ? 'password' : 'text'"
                          class="pm-input w-full font-mono text-xs"
                          :placeholder="field.label"
                        >
                      </div>

                      <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white hover:bg-slate-800 disabled:opacity-50 sm:w-auto"
                        :disabled="submittingProvider === selectedProvider"
                        @click="saveProvider(selectedProvider)"
                      >
                        <Loader2 v-if="submittingProvider === selectedProvider" size="16" class="animate-spin" />
                        <Plug v-else size="16" />
                        Conectar {{ selectedCatalogItem.label }}
                      </button>
                    </div>
                  </template>
                </div>

                <p v-else class="mt-6 text-sm font-semibold text-slate-500">
                  Somente o dono da loja pode configurar as chaves.
                </p>
              </template>
            </article>

            <!-- Pix online -->
            <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                  <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                    <QrCode size="22" />
                  </div>
                  <div>
                    <h2 class="text-lg font-black text-slate-900">Pix online no checkout</h2>
                    <p class="mt-1 text-sm font-semibold text-slate-500">
                      Ative depois de conectar uma empresa acima.
                    </p>
                  </div>
                </div>

                <button
                  v-if="canConfigure"
                  type="button"
                  role="switch"
                  :aria-checked="pixOnlineEnabled"
                  :disabled="!connection.payment_ready && !pixOnlineEnabled"
                  :class="[
                    'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors disabled:opacity-40',
                    pixOnlineEnabled ? 'bg-emerald-600' : 'bg-slate-200'
                  ]"
                  @click="pixOnlineEnabled = !pixOnlineEnabled"
                >
                  <span
                    :class="[
                      'inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform',
                      pixOnlineEnabled ? 'translate-x-6' : 'translate-x-1'
                    ]"
                  />
                </button>
              </div>

              <div
                v-if="!connection.payment_ready"
                class="mt-4 rounded-2xlpx-4 py-3 text-sm font-semibold text-slate-600"
              >
                Escolha uma empresa e conecte as chaves para liberar Pix online.
              </div>

              <div
                v-else-if="connection.pix_online_active"
                class="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 flex items-center gap-2"
              >
                <CheckCircle size="16" />
                Pix online ativo via {{ connection.active_provider?.provider_label }}.
              </div>

              <button
                v-if="canConfigure"
                type="button"
                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black text-white disabled:opacity-50"
                :disabled="saving"
                @click="saveSettings"
              >
                <Loader2 v-if="saving" size="14" class="animate-spin" />
                Salvar Pix online
              </button>
            </article>
          </div>

          <aside class="space-y-4">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-6">
              <h3 class="text-sm font-black text-slate-900">Como funciona</h3>
              <ol class="mt-4 space-y-3 text-sm font-semibold text-slate-600">
                <li>1. Escolha Pagar.me, Mercado Pago ou Asaas</li>
                <li>2. Informe as chaves da sua conta</li>
                <li>3. Cadastre a URL de webhook no painel do gateway</li>
                <li>4. Ative o Pix online no checkout</li>
              </ol>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6">
              <button
                type="button"
                class="inline-flex items-center gap-2 text-xs font-black text-emerald-700"
                @click="router.push('/loja')"
              >
                Formas de pagamento na entrega
                <ArrowUpRight size="14" />
              </button>
            </div>
          </aside>
        </section>
      </template>
    </div>
</template>
