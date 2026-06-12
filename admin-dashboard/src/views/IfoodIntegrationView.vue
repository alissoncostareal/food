<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import FeatureAccessLoading from '@/components/auth/FeatureAccessLoading.vue'
import { useFeatureAccess } from '@/composables/useFeatureAccess'
import {
  ArrowUpRight,
  CheckCircle,
  ExternalLink,
  Info,
  Loader2,
  Lock,
  PackageCheck,
  ShieldCheck,
  Unplug,
  Download,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const disconnecting = ref(false)
const importing = ref(false)
const importStats = ref(null)
const generatingCode = ref(false)
const exchangingCode = ref(false)
const oauthSession = ref(null)
const authorizationCodeInput = ref('')
const authorizedMerchants = ref([])
const { isLoading: featureLoading, isLocked, isUnlocked, refresh: refreshFeatureAccess } = useFeatureAccess('ifood_integration')
const accessDenied = ref(false)
const isBlocked = computed(() => isLocked.value || accessDenied.value)
const platform = ref(null)
const storeConnection = ref(null)
const merchantIdInput = ref('')
const testResult = ref(null)
const savingSettings = ref(false)
const autoConfirm = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

const statusLabels = {
  disconnected: 'Desconectado',
  pending: 'Aguardando validação',
  connected: 'Conectado',
  error: 'Erro na conexão'
}

const statusClass = computed(() => {
  const status = storeConnection.value?.status || 'disconnected'

  if (status === 'connected') return 'border-red-100 bg-red-50 text-red-700'
  if (status === 'pending') return 'border-amber-100 bg-amber-50 text-amber-700'
  if (status === 'error') return 'border-red-100 bg-red-50 text-red-700'

  return 'border-slate-200 bg-slate-50 text-slate-500'
})

const statusLabel = computed(() => {
  if (loading.value) return 'Carregando'
  return statusLabels[storeConnection.value?.status] || 'Desconectado'
})

const canTest = computed(() => {
  return Boolean(merchantIdInput.value.trim())
})

const sandboxMerchants = ref([])

const canImport = computed(() => storeConnection.value?.status === 'connected')

const selectMerchant = (merchant) => {
  merchantIdInput.value = merchant.id
}

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

onMounted(async () => {
  await refreshFeatureAccess()

  if (isUnlocked.value) {
    await fetchConnection()
  } else {
    loading.value = false
  }
})

const fetchConnection = async () => {
  if (isBlocked.value) {
    loading.value = false
    return
  }

  try {
    loading.value = true
    const { data } = await api.get('/merchant/integrations/ifood/connection')
    platform.value = data.platform
    storeConnection.value = data.store
    merchantIdInput.value = data.store?.merchant_id || ''
    autoConfirm.value = Boolean(data.store?.auto_confirm)
    sandboxMerchants.value = data.sandbox_merchants || []

    if (data.store?.has_token) {
      await loadAuthorizedMerchants()
    }
  } catch (error) {
    if (error.response?.status === 403) {
      accessDenied.value = true
      return
    }

    console.error('Erro ao carregar conexão iFood:', error)
    showNotify('Erro ao carregar integração iFood.', 'error')
  } finally {
    loading.value = false
  }
}

const loadAuthorizedMerchants = async () => {
  try {
    const { data } = await api.get('/merchant/integrations/ifood/oauth/merchants')
    authorizedMerchants.value = data.merchants || []
  } catch (error) {
    authorizedMerchants.value = []
  }
}

const generateUserCode = async () => {
  try {
    generatingCode.value = true
    oauthSession.value = null

    const { data } = await api.post('/merchant/integrations/ifood/oauth/user-code')
    oauthSession.value = data.oauth
    storeConnection.value = data.store
    showNotify(data.message || 'Código gerado.')
  } catch (error) {
    const message =
      error.response?.data?.details ||
      error.response?.data?.message ||
      'Erro ao gerar código iFood.'

    showNotify(message, 'error')
  } finally {
    generatingCode.value = false
  }
}

const exchangeAuthorizationCode = async () => {
  try {
    exchangingCode.value = true
    testResult.value = null
    importStats.value = null

    const { data } = await api.post('/merchant/integrations/ifood/oauth/exchange', {
      authorization_code: authorizationCodeInput.value.trim()
    })

    storeConnection.value = data.store
    authorizedMerchants.value = data.merchants || []
    authorizationCodeInput.value = ''

    if (data.store?.merchant_id) {
      merchantIdInput.value = data.store.merchant_id
    }

    showNotify(data.message || 'Autorização concluída.')
  } catch (error) {
    const message =
      error.response?.data?.details ||
      error.response?.data?.message ||
      'Erro ao validar código de autorização.'

    showNotify(message, 'error')
  } finally {
    exchangingCode.value = false
  }
}

const saveMerchantId = async () => {
  try {
    saving.value = true
    testResult.value = null

    const { data } = await api.put('/merchant/integrations/ifood/connection', {
      merchant_id: merchantIdInput.value.trim()
    })

    storeConnection.value = data.store
    showNotify(data.message || 'Merchant ID salvo.')
  } catch (error) {
    const message =
      error.response?.data?.message ||
      error.response?.data?.details ||
      'Erro ao salvar Merchant ID.'

    showNotify(message, 'error')
  } finally {
    saving.value = false
  }
}

const testConnection = async () => {
  try {
    testing.value = true
    testResult.value = null
    importStats.value = null

    const trimmed = merchantIdInput.value.trim()

    if (!trimmed) {
      showNotify('Informe o Merchant ID antes de testar.', 'error')
      return
    }

    if (trimmed !== (storeConnection.value?.merchant_id || '')) {
      const { data: saveData } = await api.put('/merchant/integrations/ifood/connection', {
        merchant_id: trimmed
      })
      storeConnection.value = saveData.store
    }

    const { data } = await api.post('/merchant/integrations/ifood/connection/test')
    storeConnection.value = data.store
    testResult.value = data.connection
    showNotify(data.message || 'Conexão validada.')
  } catch (error) {
    if (error.response?.data?.store) {
      storeConnection.value = error.response.data.store
    }

    const message =
      error.response?.data?.details ||
      error.response?.data?.message ||
      'Erro ao testar conexão iFood.'

    showNotify(message, 'error')
  } finally {
    testing.value = false
  }
}

const disconnect = async () => {
  try {
    disconnecting.value = true
    testResult.value = null
    importStats.value = null

    const { data } = await api.post('/merchant/integrations/ifood/connection/disconnect')
    storeConnection.value = data.store
    merchantIdInput.value = ''
    showNotify(data.message || 'Integração desconectada.')
  } catch (error) {
    showNotify('Erro ao desconectar integração.', 'error')
  } finally {
    disconnecting.value = false
  }
}

const importCatalog = async () => {
  try {
    importing.value = true
    importStats.value = null

    const { data } = await api.post('/merchant/integrations/ifood/catalog/import')
    importStats.value = data.stats
    showNotify(data.message || 'Catálogo importado.')
  } catch (error) {
    const message =
      error.response?.data?.details ||
      error.response?.data?.message ||
      'Erro ao importar catálogo do iFood.'

    showNotify(message, 'error')
  } finally {
    importing.value = false
  }
}

const saveAutoConfirm = async () => {
  try {
    savingSettings.value = true

    const { data } = await api.put('/merchant/integrations/ifood/settings', {
      auto_confirm: autoConfirm.value
    })

    storeConnection.value = data.store
    autoConfirm.value = Boolean(data.store?.auto_confirm)
    showNotify(data.message || 'Preferências iFood salvas.')
  } catch (error) {
    autoConfirm.value = Boolean(storeConnection.value?.auto_confirm)
    const message =
      error.response?.data?.details ||
      error.response?.data?.message ||
      'Erro ao salvar preferências iFood.'

    showNotify(message, 'error')
  } finally {
    savingSettings.value = false
  }
}
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <header class="pm-page-header">
        <div class="flex items-center gap-4">
          <div class="pm-page-icon">
            <PackageCheck size="26" />
          </div>
          <div>
            <h1 class="pm-page-title">Integração iFood</h1>
            <p class="pm-page-subtitle">Conecte sua loja do iFood para receber pedidos e sincronizar catálogo.</p>
          </div>
        </div>

        <div :class="['rounded-2xl border px-4 py-3 text-xs font-black uppercase tracking-wider', statusClass]">
          {{ statusLabel }}
        </div>
      </header>

      <FeatureAccessLoading v-if="featureLoading" />

      <section v-else-if="isBlocked" class="relative overflow-hidden rounded-3xl border border-slate-800 bg-slate-950 p-8 text-white shadow-xl">
        <div class="relative z-10 max-w-2xl">
          <div class="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-red-500 shadow-lg shadow-red-950/30">
            <Lock size="22" />
          </div>

          <h2 class="text-3xl font-black leading-tight">
            Integração iFood disponível no Premium
          </h2>

          <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-300">
            Conecte catálogo, pedidos e eventos do iFood direto no painel da sua loja.
          </p>

          <button
            type="button"
            class="mt-7 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-700 active:scale-95"
            @click="router.push('/billing')"
          >
            Ver plano Premium
            <ArrowUpRight size="18" />
          </button>
        </div>

        <PackageCheck class="absolute -right-10 -bottom-10 text-white/5" size="190" />
      </section>

      <template v-else>
        <section v-if="sandboxMerchants.length" class="rounded-3xl border border-amber-100 bg-amber-50 p-6">
          <h2 class="text-sm font-black text-amber-900">Modo parceiro · sandbox</h2>
          <p class="mt-2 text-sm font-bold leading-relaxed text-amber-800">
            Vocês não precisam ter loja no iFood. O Developer Portal cria uma <strong class="font-black">loja de teste</strong>
            vinculada ao app centralizado — use ela para validar importação antes de lojistas reais conectarem via OAuth.
          </p>
          <p class="mt-3 text-xs font-bold text-amber-700">
            Pule os passos 1–2 se usar a loja abaixo: só cole o Merchant ID e teste a conexão.
          </p>
          <div class="mt-4 flex flex-wrap gap-2">
            <button
              v-for="merchant in sandboxMerchants"
              :key="merchant.id"
              type="button"
              class="rounded-2xl border border-amber-200 bg-white px-4 py-2 text-left transition hover:bg-amber-100"
              @click="selectMerchant(merchant)"
            >
              <span class="block text-sm font-black text-slate-900">{{ merchant.name || 'Loja de teste' }}</span>
              <span class="mt-1 block font-mono text-[11px] font-bold text-slate-500">{{ merchant.id }}</span>
            </button>
          </div>
          <p class="mt-4 text-xs font-bold text-amber-700">
            O <strong class="font-black">clientId</strong> do app não é o Merchant ID. Merchant ID fica em
            <strong class="font-black">Meus aplicativos → Lojas</strong> (UUID da loja de teste).
          </p>
          <p class="mt-2 text-xs font-bold text-amber-700">
            Para cadastrar manualmente: use o Gestor de Pedidos Web (sandbox) → Cardápio da loja de teste.
          </p>
        </section>

        <section class="rounded-3xl border border-blue-100 bg-blue-50 p-6">
          <div class="flex gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-blue-600">
              <ShieldCheck size="20" />
            </div>
            <div>
              <h2 class="text-sm font-black text-blue-900">Como cada lojista conecta</h2>
              <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm font-bold leading-relaxed text-blue-800">
                <li>Gere o código e autorize o PartiuMenu no portal iFood.</li>
                <li>Cole o código de autorização que o iFood devolver.</li>
                <li>Informe o Merchant ID da loja e teste a conexão.</li>
              </ol>
              <p class="mt-3 text-xs font-bold text-blue-700">
                Só o Merchant ID não basta: o iFood exige que o lojista autorize o app antes de liberar catálogo e pedidos.
              </p>
            </div>
          </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
          <div class="flex items-center gap-3 border-b border-slate-100 pb-6">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
              <ShieldCheck size="23" />
            </div>
            <div>
              <h2 class="text-lg font-black text-slate-900">Passo 1 — Autorizar no iFood</h2>
              <p class="text-xs font-bold text-slate-400">OAuth distribuído · ambiente {{ platform?.environment || 'sandbox' }}</p>
            </div>
          </div>

          <div v-if="!platform?.configured" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800">
            App distribuído iFood não configurado no backend (IFOOD_DISTRIBUTED_CLIENT_ID/SECRET).
          </div>

          <div class="mt-6 space-y-5">
            <button
              type="button"
              :disabled="generatingCode || !platform?.configured"
              class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white transition-all hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
              @click="generateUserCode"
            >
              <Loader2 v-if="generatingCode" class="animate-spin" size="16" />
              {{ generatingCode ? 'Gerando...' : 'Gerar código de autorização' }}
            </button>

            <div v-if="oauthSession" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Código para o portal iFood</p>
              <p class="mt-2 font-mono text-2xl font-black tracking-widest text-slate-900">{{ oauthSession.user_code }}</p>
              <a
                :href="oauthSession.verification_url_complete"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-3 inline-flex items-center gap-2 text-sm font-black text-red-600 hover:underline"
              >
                Abrir portal iFood para autorizar
                <ExternalLink size="14" />
              </a>
              <p class="mt-2 text-xs font-bold text-slate-500">Expira em {{ oauthSession.expires_in }}s</p>
            </div>

            <div>
              <label for="auth-code" class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Passo 2 — Código de autorização (retornado pelo iFood)
              </label>
              <input
                id="auth-code"
                v-model="authorizationCodeInput"
                type="text"
                placeholder=""
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm font-bold uppercase tracking-widest text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-2 focus:ring-red-100"
              />
              <button
                type="button"
                :disabled="exchangingCode || !authorizationCodeInput.trim()"
                class="mt-3 inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition-all hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                @click="exchangeAuthorizationCode"
              >
                <Loader2 v-if="exchangingCode" class="animate-spin" size="16" />
                Confirmar autorização
              </button>
            </div>
          </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
          <div class="flex items-center gap-3 border-b border-slate-100 pb-6">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <PackageCheck size="23" />
            </div>
            <div>
              <h2 class="text-lg font-black text-slate-900">Passo 3 — Merchant ID da loja</h2>
              <p class="text-xs font-bold text-slate-400">
                {{ storeConnection?.has_token ? 'Autorização OK' : 'Autorize no passo 1 antes de testar' }}
              </p>
            </div>
          </div>

          <div v-if="authorizedMerchants.length" class="mt-6 rounded-2xl border border-red-100 bg-red-50 p-4">
            <p class="text-xs font-black uppercase tracking-widest text-red-700">Lojas autorizadas no iFood</p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-for="merchant in authorizedMerchants"
                :key="merchant.id"
                type="button"
                class="rounded-2xl border border-red-200 bg-white px-4 py-2 text-left transition hover:bg-red-50"
                @click="selectMerchant(merchant)"
              >
                <span class="block text-sm font-black text-slate-900">{{ merchant.name || 'Sem nome' }}</span>
                <span class="mt-1 block font-mono text-[11px] font-bold text-slate-500">{{ merchant.id }}</span>
              </button>
            </div>
          </div>

          <form class="mt-6 space-y-5" @submit.prevent="testConnection">
            <div>
              <label for="merchant-id" class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Merchant ID (UUID)
              </label>
              <input
                id="merchant-id"
                v-model="merchantIdInput"
                type="text"
                placeholder=""
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-2 focus:ring-red-100"
              />
              <p class="mt-2 text-xs font-bold text-slate-500">
                Clique em uma loja autorizada acima ou cole o UUID manualmente.
              </p>
            </div>

            <div class="flex flex-wrap gap-3">
              <button
                type="button"
                :disabled="saving || !merchantIdInput.trim()"
                class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-700 transition-all hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                @click="saveMerchantId"
              >
                <Loader2 v-if="saving" class="animate-spin" size="16" />
                Salvar Merchant ID
              </button>

              <button
                type="submit"
                :disabled="testing || !canTest"
                class="inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition-all hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Loader2 v-if="testing" class="animate-spin" size="16" />
                <ExternalLink v-else size="16" />
                {{ testing ? 'Testando...' : 'Testar conexão' }}
              </button>

              <button
                v-if="storeConnection?.status === 'connected'"
                type="button"
                :disabled="disconnecting"
                class="inline-flex items-center justify-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-black text-red-700 transition-all hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                @click="disconnect"
              >
                <Loader2 v-if="disconnecting" class="animate-spin" size="16" />
                <Unplug v-else size="16" />
                Desconectar
              </button>
            </div>
          </form>

          <div v-if="storeConnection?.last_error" class="mt-5 rounded-2xl border border-red-100 bg-red-50 p-4">
            <p class="text-xs font-black uppercase tracking-widest text-red-700">Último erro</p>
            <p class="mt-1 text-sm font-bold text-red-900">{{ storeConnection.last_error }}</p>
          </div>

          <div v-if="testResult" class="mt-5 rounded-2xl border border-red-100 bg-red-50 p-4">
            <p class="text-xs font-black uppercase tracking-widest text-red-700">Conexão validada</p>
            <p class="mt-1 text-sm font-bold text-red-900">
              Loja: {{ testResult.merchant_name || 'Nome não retornado' }}
            </p>
            <p class="mt-1 font-mono text-xs font-bold text-red-800">
              {{ testResult.merchant_id }}
            </p>
          </div>

          <div v-if="storeConnection?.connected_at" class="mt-5 flex items-start gap-2 text-xs font-bold text-slate-500">
            <Info size="14" class="mt-0.5 shrink-0" />
            Conectado em {{ new Date(storeConnection.connected_at).toLocaleString('pt-BR') }}
          </div>
        </section>

        <section v-if="canImport" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
          <div class="flex items-center gap-3 border-b border-slate-100 pb-6">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">
              <CheckCircle size="23" />
            </div>
            <div>
              <h2 class="text-lg font-black text-slate-900">Operação de pedidos</h2>
              <p class="text-xs font-bold text-slate-400">Aceite automático e sincronização de status com o iFood</p>
            </div>
          </div>

          <label class="mt-6 flex cursor-pointer items-start gap-4 rounded-2xl border border-slate-100 bg-slate-50 p-4 transition hover:bg-slate-100/80">
            <input
              v-model="autoConfirm"
              type="checkbox"
              class="mt-1 h-5 w-5 rounded border-slate-300 text-red-600 focus:ring-red-500"
              :disabled="savingSettings"
              @change="saveAutoConfirm"
            />
            <span>
              <span class="block text-sm font-black text-slate-900">Aceitar pedidos iFood automaticamente</span>
              <span class="mt-1 block text-sm font-bold leading-relaxed text-slate-500">
                Quando um pedido chegar, confirma no iFood e move para <strong class="font-black">Em preparo</strong> sem ação manual.
                Status como pronto, despacho e cancelamento continuam sincronizados quando você atualizar no painel.
              </span>
            </span>
          </label>

          <p v-if="savingSettings" class="mt-3 flex items-center gap-2 text-xs font-bold text-slate-500">
            <Loader2 class="animate-spin" size="14" />
            Salvando...
          </p>
        </section>

        <section v-if="canImport" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
          <div class="flex items-center gap-3 border-b border-slate-100 pb-6">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-red-600">
              <Download size="23" />
            </div>
            <div>
              <h2 class="text-lg font-black text-slate-900">Importar catálogo</h2>
              <p class="text-xs font-bold text-slate-400">Categorias, produtos e preços do iFood → PartiuMenu</p>
            </div>
          </div>

          <p class="mt-6 text-sm font-bold leading-relaxed text-slate-500">
            Importa categorias, produtos (nome, descrição, preço, foto, ativo), grupos de complementos e opções
            no formato do PartiuMenu.
          </p>

          <div class="mt-5">
            <button
              type="button"
              :disabled="importing"
              class="inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition-all hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              @click="importCatalog"
            >
              <Loader2 v-if="importing" class="animate-spin" size="16" />
              <Download v-else size="16" />
              {{ importing ? 'Importando...' : 'Importar do iFood' }}
            </button>
          </div>

          <div v-if="importStats" class="mt-5 rounded-2xl border border-red-100 bg-red-50 p-4 text-sm font-bold text-red-900">
            <p>Categorias: {{ importStats.categories_created }} criadas, {{ importStats.categories_updated }} atualizadas</p>
            <p class="mt-1">Produtos: {{ importStats.products_created }} criados, {{ importStats.products_updated }} atualizados</p>
            <p v-if="importStats.option_groups_synced || importStats.option_items_synced" class="mt-1">
              Complementos: {{ importStats.option_groups_synced }} grupos, {{ importStats.option_items_synced }} opções
            </p>
            <p v-if="importStats.products_skipped" class="mt-1 text-amber-700">
              {{ importStats.products_skipped }} produto(s) ignorado(s) — limite do plano ou dados inválidos
            </p>
            <p v-if="importStats.product_images_imported || importStats.option_images_imported" class="mt-1">
              Fotos: {{ importStats.product_images_imported || 0 }} produto(s), {{ importStats.option_images_imported || 0 }} complemento(s)
            </p>
          </div>
        </section>
      </template>
    </div>
</template>
