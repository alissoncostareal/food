<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api, { whatsappRequest } from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import FeatureAccessLoading from '@/components/auth/FeatureAccessLoading.vue'
import { fetchCurrentUser, useFeatureAccess } from '@/composables/useFeatureAccess'
import { useOnStoreSwitch } from '@/composables/useOnStoreSwitch'
import { integrationErrorNotifyMessage } from '@/utils/integrationErrors'
import {
  ArrowUpRight,
  BellRing,
  Bot,
  CheckCircle,
  ChevronRight,
  Loader2,
  Lock,
  MessageCircle,
  Plug,
  RefreshCw,
  RotateCcw,
  Save,
  Send,
  Sparkles,
  Pencil,
  Unplug
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const provisioning = ref(false)
const syncing = ref(false)
const disconnecting = ref(false)
const testing = ref(false)
const metaConnecting = ref(false)
const switchingProvider = ref(false)
const metaSignupSession = ref(null)
const accessDenied = ref(false)
const canConfigure = ref(false)
const pollTimer = ref(null)
const syncInFlight = ref(false)
const testPhone = ref('')
const activeSection = ref('connection')

const connection = ref(null)
const messageLabels = ref({})
const messageDefaults = ref({})
const messageDrafts = ref({})
const messageOverrides = ref({})
const messagePlaceholders = ref([])
const messagesLoading = ref(false)
const savingMessages = ref(false)
const botSettings = ref(null)
const botLoading = ref(false)
const savingBot = ref(false)
const botEditing = ref(false)
const botMessagesSnapshot = ref(null)
const botFormSnapshot = ref(null)
const botMessageLabels = ref({})
const botMessageDefaults = ref({})
const botMessageDrafts = ref({})
const botMessagePlaceholders = ref([])
const botForm = ref({
  whatsapp_bot_enabled: true,
  whatsapp_ai_enabled: false,
  whatsapp_bot_welcome: '',
  whatsapp_ai_faq: ''
})
const toast = ref({ show: false, message: '', type: 'success' })

const { isLoading: featureLoading, isLocked, isUnlocked, refresh: refreshFeatureAccess } = useFeatureAccess('whatsapp_auto')

const isBlocked = computed(() => isLocked.value || accessDenied.value)

const status = computed(() => connection.value?.status || 'pending')

const provider = computed(() => connection.value?.provider || 'evolution')
const isEvolutionProvider = computed(() => provider.value === 'evolution')
const isMetaProvider = computed(() => provider.value === 'meta')
const metaReady = computed(() => Boolean(connection.value?.meta?.embedded_signup_ready))

const statusLabels = {
  pending: 'Aguardando ativação',
  provisioning: 'Provisionando...',
  awaiting_qr: 'Escaneie o QR Code',
  connecting: 'Conectando...',
  connected: 'Conectado',
  error: 'Erro na conexão',
  disabled: 'Desativado'
}

const statusClass = computed(() => {
  if (status.value === 'connected') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (status.value === 'awaiting_qr' || status.value === 'provisioning') return 'border-amber-100 bg-amber-50 text-amber-700'
  if (status.value === 'error') return 'border-red-100 bg-red-50 text-red-700'
  if (status.value === 'disabled') return 'border-slate-200 bg-slate-100 text-slate-500'

  return 'border-slate-200 bg-slate-50 text-slate-500'
})

const statusLabel = computed(() => {
  if (loading.value) return 'Carregando'
  return statusLabels[status.value] || 'Aguardando'
})

const evolutionReady = computed(() => Boolean(connection.value?.evolution?.configured || connection.value?.test_mode))

const evolutionMissing = computed(() => connection.value?.evolution?.missing || [])

const evolutionSetupHint = computed(() => {
  if (evolutionMissing.value.length > 0) {
    return evolutionMissing.value.join(', ')
  }

  if (connection.value?.evolution?.webhook_url_missing) {
    return 'EVOLUTION_WEBHOOK_URL (recomendado para receber mensagens)'
  }

  return 'EVOLUTION_ENABLED, EVOLUTION_API_URL, EVOLUTION_API_KEY'
})

const metaSetupHint = computed(() => {
  const missing = connection.value?.meta?.missing || []

  return missing.length > 0
    ? missing.join(', ')
    : 'META_WHATSAPP_ENABLED, META_WHATSAPP_APP_ID, META_WHATSAPP_APP_SECRET, META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'
})

const needsQr = computed(() => isEvolutionProvider.value && ['awaiting_qr', 'provisioning'].includes(status.value))

const qrImageSrc = computed(() => {
  const base64 = connection.value?.qrcode?.base64

  if (!base64) return null

  if (base64.startsWith('data:image')) {
    return base64
  }

  return `data:image/png;base64,${base64}`
})

const messageKeys = computed(() => Object.keys(messageLabels.value))

const isMessageCustomized = (key) => {
  const draft = (messageDrafts.value[key] || '').trim()
  const fallback = (messageDefaults.value[key] || '').trim()

  return draft !== fallback
}

const customizedMessagesCount = computed(() =>
  messageKeys.value.filter((key) => isMessageCustomized(key)).length
)

const botMessageKeys = computed(() => Object.keys(botMessageLabels.value))

const isBotMessageCustomized = (key) => {
  const draft = (botMessageDrafts.value[key] || '').trim()
  const fallback = (botMessageDefaults.value[key] || '').trim()

  return draft !== fallback
}

const customizedBotMessagesCount = computed(() =>
  botMessageKeys.value.filter((key) => isBotMessageCustomized(key)).length
)

const botOptionPreview = (key) => {
  const draft = (botMessageDrafts.value[key] || botMessageDefaults.value[key] || '').trim()

  if (!draft) {
    return '—'
  }

  return draft.replace(/^\d+\s*-\s*/, '')
}

const botMessageRows = (key) => (key.startsWith('option_') ? 2 : 5)

const hasBotFeature = computed(() => Boolean(connection.value?.features?.bot))
const hasAiFeature = computed(() => Boolean(connection.value?.features?.ai))
const aiConfigured = computed(() => Boolean(botSettings.value?.ai_configured))
const aiProviderLabel = computed(() => botSettings.value?.ai_provider_label || 'Google Gemini')
const aiFaqMinChars = computed(() => Number(botSettings.value?.ai_faq_min_chars || 20))
const aiFaqFilled = computed(() => {
  const fromForm = botForm.value.whatsapp_ai_faq.trim().length >= aiFaqMinChars.value
  if (botForm.value.whatsapp_ai_faq.trim()) {
    return fromForm
  }

  return Boolean(botSettings.value?.ai_faq_filled)
})
const aiCanEnable = computed(() => hasAiFeature.value && aiFaqFilled.value && aiConfigured.value)

const botStatusLabel = computed(() => {
  if (!hasBotFeature.value) return 'Plano Pro+'
  if (!botForm.value.whatsapp_bot_enabled) return 'Desativado'
  if (botForm.value.whatsapp_ai_enabled && hasAiFeature.value) {
    if (!aiFaqFilled.value) return 'Bot ativo · IA aguardando FAQ'
    if (!aiConfigured.value) return 'Bot ativo · IA aguardando chave'
    return 'Bot + IA ativos'
  }
  return 'Bot ativo'
})

const sections = computed(() => [
  {
    id: 'connection',
    label: 'Conexão',
    title: 'Conectar número',
    description: isMetaProvider.value
      ? 'API oficial da Meta com verificação por SMS.'
      : 'Instância exclusiva na Evolution API com QR Code.',
    icon: Plug,
    accent: status.value === 'connected'
      ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
      : 'bg-slate-100 text-slate-600 ring-slate-200',
    status: statusLabel.value,
    statusTone: status.value === 'connected' ? 'ok' : status.value === 'error' ? 'error' : 'pending'
  },
  {
    id: 'notifications',
    label: 'Notificações',
    title: 'Status de pedido',
    description: 'Mensagens automáticas quando o pedido muda de status.',
    icon: BellRing,
    accent: 'bg-slate-100 text-slate-600 ring-slate-200',
    status: customizedMessagesCount.value
      ? `${customizedMessagesCount.value} personalizada(s)`
      : 'Padrão do sistema',
    statusTone: 'neutral'
  },
  {
    id: 'bot',
    label: 'Bot de atendimento',
    title: 'Atendimento automático',
    description: 'Menu 1–4 e IA Premium para dúvidas em texto livre.',
    icon: Bot,
    accent: 'bg-slate-100 text-slate-600 ring-slate-200',
    status: customizedBotMessagesCount.value
      ? `${customizedBotMessagesCount.value} texto(s) personalizado(s)`
      : botStatusLabel.value,
    statusTone: botForm.value.whatsapp_bot_enabled ? 'ok' : 'neutral',
    locked: !hasBotFeature.value
  }
])

const activeSectionMeta = computed(() =>
  sections.value.find((section) => section.id === activeSection.value) || sections.value[0]
)

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

const applyConnection = (data) => {
  if (!data) return

  const payload = data.whatsapp || data
  const previousQr = connection.value?.qrcode

  connection.value = {
    ...payload,
    qrcode: data.qrcode || payload.qrcode || (['awaiting_qr', 'provisioning'].includes(payload.status) ? previousQr : null)
  }
}

const stopPolling = () => {
  if (pollTimer.value) {
    clearInterval(pollTimer.value)
    pollTimer.value = null
  }
}

const startPolling = () => {
  stopPolling()

  if (!needsQr.value) {
    return
  }

  pollTimer.value = setInterval(async () => {
    await syncConnection(true)
  }, 15000)
}

const fetchConnection = async () => {
  if (isBlocked.value) {
    loading.value = false
    return
  }

  try {
    loading.value = true
    const { data } = await api.get('/merchant/integrations/whatsapp/connection')
    applyConnection(data)

    if (!testPhone.value && connection.value?.whatsapp_number) {
      testPhone.value = connection.value.whatsapp_number
    }
  } catch (error) {
    if (error.response?.status === 403 && error.response?.data?.upgrade_required) {
      accessDenied.value = true
      return
    }

    console.error('Erro ao carregar WhatsApp:', error)
    showNotify('Erro ao carregar integração WhatsApp.', 'error')
  } finally {
    loading.value = false
    startPolling()

    if (isEvolutionProvider.value && connection.value?.status !== 'connected') {
      void syncConnection(true)
    }
  }
}

const maybeAutoStart = async () => {
  if (!canConfigure.value || !isEvolutionProvider.value || !evolutionReady.value) {
    return
  }

  if (['pending', 'disabled', 'error'].includes(status.value)) {
    await provision(true)
    return
  }

  if (status.value === 'awaiting_qr' && !qrImageSrc.value) {
    await refreshQr(true)
  }
}

const switchProvider = async (nextProvider) => {
  if (!canConfigure.value || provider.value === nextProvider || switchingProvider.value) {
    return
  }

  try {
    switchingProvider.value = true
    stopPolling()
    const { data } = await api.put('/merchant/integrations/whatsapp/provider', { provider: nextProvider })
    applyConnection(data)
    showNotify(data.message || 'Modo de conexão atualizado.')

    if (nextProvider === 'evolution') {
      await maybeAutoStart()
      startPolling()
    }
  } catch (error) {
    showNotify(integrationErrorNotifyMessage(error, 'Erro ao trocar modo de conexão.'), 'error')
  } finally {
    switchingProvider.value = false
  }
}

const loadFacebookSdk = (appId) => new Promise((resolve, reject) => {
  if (window.FB) {
    window.FB.init({ appId, cookie: true, xfbml: true, version: 'v21.0' })
    resolve(window.FB)
    return
  }

  window.fbAsyncInit = function () {
    window.FB.init({ appId, cookie: true, xfbml: true, version: 'v21.0' })
    resolve(window.FB)
  }

  const existing = document.getElementById('facebook-jssdk')
  if (existing) {
    existing.addEventListener('load', () => resolve(window.FB), { once: true })
    existing.addEventListener('error', () => reject(new Error('Falha ao carregar SDK da Meta')), { once: true })
    return
  }

  const script = document.createElement('script')
  script.id = 'facebook-jssdk'
  script.src = 'https://connect.facebook.net/pt_BR/sdk.js'
  script.async = true
  script.defer = true
  script.onerror = () => reject(new Error('Falha ao carregar SDK da Meta'))
  document.body.appendChild(script)
})

const attachMetaSignupListener = () => {
  if (metaSignupSession.value?.listener) {
    return
  }

  const listener = (event) => {
    if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') {
      return
    }

    let payload = event.data
    if (typeof payload === 'string') {
      try {
        payload = JSON.parse(payload)
      } catch {
        return
      }
    }

    if (payload?.type !== 'WA_EMBEDDED_SIGNUP') {
      return
    }

    metaSignupSession.value = {
      ...(metaSignupSession.value || {}),
      listener,
      waba_id: payload?.data?.waba_id || payload?.data?.whatsapp_business_account_id,
      phone_number_id: payload?.data?.phone_number_id,
    }
  }

  window.addEventListener('message', listener)
  metaSignupSession.value = { listener }
}

const detachMetaSignupListener = () => {
  const listener = metaSignupSession.value?.listener
  if (listener) {
    window.removeEventListener('message', listener)
  }
  metaSignupSession.value = null
}

const connectMeta = async () => {
  if (!canConfigure.value || !metaReady.value) {
    showNotify(`Configure no backend: ${metaSetupHint.value}`, 'error')
    return
  }

  try {
    metaConnecting.value = true
    const { data } = await api.get('/merchant/integrations/whatsapp/meta/config')
    const signup = data?.meta?.embedded_signup

    if (!signup?.app_id || !signup?.config_id) {
      showNotify('Cadastro incorporado da Meta indisponível.', 'error')
      return
    }

    attachMetaSignupListener()
    const FB = await loadFacebookSdk(signup.app_id)

    FB.login((response) => {
      void (async () => {
        try {
          const session = metaSignupSession.value || {}
          const code = response?.authResponse?.code

          if (!code) {
            showNotify('Conexão com a Meta cancelada ou incompleta.', 'error')
            return
          }

          if (!session.waba_id || !session.phone_number_id) {
            showNotify('Meta não retornou os dados do número. Tente novamente.', 'error')
            return
          }

          const complete = await api.post('/merchant/integrations/whatsapp/meta/complete-signup', {
            code,
            waba_id: session.waba_id,
            phone_number_id: session.phone_number_id,
          })

          applyConnection(complete.data)
          showNotify(complete.data?.message || 'WhatsApp oficial conectado.')
        } catch (error) {
          showNotify(integrationErrorNotifyMessage(error, 'Erro ao conectar WhatsApp oficial.'), 'error')
        } finally {
          detachMetaSignupListener()
          metaConnecting.value = false
        }
      })()
    }, {
      config_id: signup.config_id,
      response_type: 'code',
      override_default_response_type: true,
      extras: {
        setup: {},
      },
    })
  } catch (error) {
    detachMetaSignupListener()
    metaConnecting.value = false
    showNotify(integrationErrorNotifyMessage(error, 'Erro ao iniciar conexão Meta.'), 'error')
  }
}

const disconnectMeta = async () => {
  if (!canConfigure.value) {
    return
  }

  try {
    disconnecting.value = true
    const { data } = await api.post('/merchant/integrations/whatsapp/meta/disconnect')
    applyConnection(data)
    showNotify(data.message || 'WhatsApp oficial desconectado.')
  } catch (error) {
    showNotify(integrationErrorNotifyMessage(error, 'Erro ao desconectar.'), 'error')
  } finally {
    disconnecting.value = false
  }
}

const provision = async (silent = false) => {
  if (!canConfigure.value) {
    showNotify('Apenas o dono da loja pode configurar o WhatsApp.', 'error')
    return
  }

  if (!evolutionReady.value) {
    const missing = connection.value?.evolution?.missing?.join(', ') || 'EVOLUTION_*'
    showNotify(`Servidor Evolution não configurado. Defina no Render (backend): ${missing}`, 'error')
    return
  }

  try {
    provisioning.value = true
    const { data } = await whatsappRequest({ method: 'post', url: '/merchant/integrations/whatsapp/provision' })
    applyConnection(data)

    if (!silent) {
      showNotify(data.message || 'Instância criada. Escaneie o QR Code.')
    }

    startPolling()
  } catch (error) {
    if (!silent) {
      showNotify(integrationErrorNotifyMessage(error, 'Erro ao ativar WhatsApp.'), 'error')
    }
  } finally {
    provisioning.value = false
  }
}

const syncConnection = async (silent = false) => {
  if (!canConfigure.value || syncInFlight.value) {
    return
  }

  try {
    syncInFlight.value = true
    syncing.value = true
    const { data } = await whatsappRequest({ method: 'post', url: '/merchant/integrations/whatsapp/sync' })
    applyConnection({ whatsapp: data.whatsapp || data })

    if (connection.value?.status === 'connected') {
      stopPolling()
      if (!silent) {
        showNotify(data.message || 'WhatsApp conectado!')
      }
      return
    }

    if (!silent && !data.transient) {
      showNotify(data.message || 'Status atualizado.')
    }

    startPolling()
  } catch (error) {
    if (!silent && !error.response?.data?.transient) {
      showNotify(integrationErrorNotifyMessage(error, 'Erro ao sincronizar conexão.'), 'error')
    }
  } finally {
    syncing.value = false
    syncInFlight.value = false
  }
}

const disconnectForNewNumber = async () => {
  if (!canConfigure.value) {
    showNotify('Apenas o dono da loja pode trocar o WhatsApp.', 'error')
    return
  }

  const confirmed = window.confirm(
    'Desconectar o WhatsApp atual e gerar um novo QR Code?\n\nVocê precisará escanear com o novo número. O número em Minha loja será atualizado após conectar.'
  )

  if (!confirmed) return

  try {
    disconnecting.value = true
    const { data } = await whatsappRequest({ method: 'post', url: '/merchant/integrations/whatsapp/disconnect' })
    applyConnection(data)
    showNotify(data.message || 'Escaneie o QR Code com o novo número.')
    startPolling()
  } catch (error) {
    showNotify(integrationErrorNotifyMessage(error, 'Erro ao desconectar WhatsApp.'), 'error')
  } finally {
    disconnecting.value = false
  }
}

const refreshQr = async (silent = false) => {
  if (!canConfigure.value) {
    showNotify('Apenas o dono da loja pode gerar o QR Code.', 'error')
    return
  }

  try {
    syncing.value = true
    const { data } = await whatsappRequest({ method: 'get', url: '/merchant/integrations/whatsapp/qrcode' })
    applyConnection(data)

    if (!silent) {
      showNotify(data.message || 'QR Code atualizado.')
    }

    startPolling()
  } catch (error) {
    if (!silent) {
      showNotify(error.response?.data?.message || 'Erro ao atualizar QR Code.', 'error')
    }
  } finally {
    syncing.value = false
  }
}

const sendTestMessage = async () => {
  if (!testPhone.value.trim()) {
    showNotify('Informe um número com DDD para o teste.', 'error')
    return
  }

  try {
    testing.value = true
    const { data } = await whatsappRequest({
      method: 'post',
      url: '/merchant/integrations/whatsapp/test-message',
      data: {
        phone: testPhone.value.trim()
      }
    })
    showNotify(data.message || 'Mensagem de teste enviada.')
  } catch (error) {
    showNotify(integrationErrorNotifyMessage(error, 'Erro ao enviar teste.'), 'error')
  } finally {
    testing.value = false
  }
}

const applyMessages = (data) => {
  messageLabels.value = data?.labels || {}
  messageDefaults.value = data?.defaults || {}
  messageOverrides.value = data?.messages || {}
  messagePlaceholders.value = data?.placeholders || ['{nome}', '{pedido}', '{loja}']

  const drafts = {}

  for (const key of Object.keys(messageLabels.value)) {
    drafts[key] = messageOverrides.value[key] || messageDefaults.value[key] || ''
  }

  messageDrafts.value = drafts
}

const fetchMessages = async () => {
  if (isBlocked.value) {
    return
  }

  try {
    messagesLoading.value = true
    const { data } = await api.get('/merchant/integrations/whatsapp/messages')
    applyMessages(data)
  } catch (error) {
    console.error('Erro ao carregar mensagens WhatsApp:', error)
    showNotify('Erro ao carregar mensagens automáticas.', 'error')
  } finally {
    messagesLoading.value = false
  }
}

const saveMessages = async () => {
  if (!canConfigure.value) {
    showNotify('Apenas o dono da loja pode editar as mensagens.', 'error')
    return
  }

  try {
    savingMessages.value = true

    const { data } = await api.put('/merchant/integrations/whatsapp/messages', {
      messages: Object.fromEntries(
        messageKeys.value.map((key) => [key, messageDrafts.value[key] || ''])
      )
    })

    applyMessages({
      labels: messageLabels.value,
      defaults: messageDefaults.value,
      placeholders: messagePlaceholders.value,
      messages: data.messages || {}
    })

    showNotify(data.message || 'Mensagens salvas.')
  } catch (error) {
    showNotify(
      error.response?.data?.message || 'Erro ao salvar mensagens.',
      'error'
    )
  } finally {
    savingMessages.value = false
  }
}

const resetMessage = (key) => {
  messageDrafts.value[key] = messageDefaults.value[key] || ''
}

const resetAllMessages = () => {
  for (const key of messageKeys.value) {
    resetMessage(key)
  }
}

const applyBotSettings = (data) => {
  const settings = data?.settings || data || {}
  botSettings.value = settings
  botForm.value = {
    whatsapp_bot_enabled: settings.whatsapp_bot_enabled !== false,
    whatsapp_ai_enabled: settings.whatsapp_ai_enabled === true,
    whatsapp_bot_welcome: settings.whatsapp_bot_welcome || '',
    whatsapp_ai_faq: settings.whatsapp_ai_faq || ''
  }

  if (data?.bot_messages) {
    applyBotMessages(data.bot_messages)
  }
}

const applyBotMessages = (payload) => {
  botMessageLabels.value = payload?.labels || {}
  botMessageDefaults.value = payload?.defaults || {}
  botMessagePlaceholders.value = payload?.placeholders || []
  const stored = payload?.messages || {}
  const keys = Object.keys(botMessageLabels.value)

  botMessageDrafts.value = Object.fromEntries(
    keys.map((key) => [key, stored[key] || botMessageDefaults.value[key] || ''])
  )
}

const resetBotMessage = (key) => {
  botMessageDrafts.value[key] = botMessageDefaults.value[key] || ''
}

const resetAllBotMessages = () => {
  for (const key of botMessageKeys.value) {
    resetBotMessage(key)
  }
}

const startBotEditing = () => {
  botMessagesSnapshot.value = JSON.parse(JSON.stringify(botMessageDrafts.value))
  botFormSnapshot.value = JSON.parse(JSON.stringify(botForm.value))
  botEditing.value = true
}

const cancelBotEditing = () => {
  if (botMessagesSnapshot.value) {
    botMessageDrafts.value = { ...botMessagesSnapshot.value }
  }

  if (botFormSnapshot.value) {
    botForm.value = { ...botFormSnapshot.value }
  }

  botEditing.value = false
}

watch(
  () => botForm.value.whatsapp_ai_faq,
  (faq) => {
    if (faq.trim().length < aiFaqMinChars.value) {
      botForm.value.whatsapp_ai_enabled = false
    }
  }
)

const fetchBotSettings = async () => {
  if (isBlocked.value) {
    return
  }

  try {
    botLoading.value = true
    const { data } = await api.get('/merchant/integrations/whatsapp/bot')
    applyBotSettings(data)
  } catch (error) {
    console.error('Erro ao carregar bot WhatsApp:', error)
  } finally {
    botLoading.value = false
  }
}

const saveBotSettings = async () => {
  if (!canConfigure.value) {
    showNotify('Apenas o dono da loja pode editar o bot.', 'error')
    return
  }

  try {
    savingBot.value = true
    const { data } = await api.put('/merchant/integrations/whatsapp/bot', {
      ...botForm.value,
      messages: Object.fromEntries(
        botMessageKeys.value.map((key) => [key, botMessageDrafts.value[key] || ''])
      )
    })
    applyBotSettings(data)
    botEditing.value = false
    showNotify(data.message || 'Configurações do bot salvas.')
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao salvar bot.', 'error')
  } finally {
    savingBot.value = false
  }
}

const openSection = (sectionId) => {
  activeSection.value = sectionId
}

const sectionStatusClass = (tone) => {
  if (tone === 'ok') return 'text-emerald-600'
  if (tone === 'error') return 'text-red-600'
  return 'text-slate-500'
}

const loadPage = async () => {
  accessDenied.value = false
  stopPolling()

  await refreshFeatureAccess()

  if (!isUnlocked.value) {
    loading.value = false
    return
  }

  try {
    const user = await fetchCurrentUser()
    canConfigure.value = Boolean(user?.permissions?.can_manage_billing || user?.role === 'store_owner')
  } catch {
    canConfigure.value = false
  }

  await fetchConnection()

  void Promise.all([fetchMessages(), fetchBotSettings()])
  void maybeAutoStart()
}

onMounted(loadPage)

useOnStoreSwitch(loadPage)

onBeforeUnmount(() => {
  stopPolling()
  detachMetaSignupListener()
})
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <header class="pm-page-header">
        <div class="flex items-center gap-4">
          <div class="pm-page-icon">
            <MessageCircle size="26" />
          </div>
          <div>
            <h1 class="pm-page-title">WhatsApp da loja</h1>
            <p class="pm-page-subtitle">
              Três funções: conectar o número, avisar clientes sobre pedidos e atender automaticamente.
            </p>
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
            WhatsApp automático disponível no Pro
          </h2>

          <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-300">
            Ao assinar o Pro, criamos automaticamente uma instância exclusiva da sua loja.
            Conecte com QR Code, envie status de pedido e ative o bot de atendimento.
          </p>

          <button
            type="button"
            class="mt-7 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-700 active:scale-95"
            @click="router.push('/billing')"
          >
            Ver planos
            <ArrowUpRight size="18" />
          </button>
        </div>

        <MessageCircle class="absolute -right-10 -bottom-10 text-white/5" size="190" />
      </section>

      <template v-else>
        <section
          v-if="connection?.test_mode"
          class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900"
        >
          Modo teste ativo — mensagens vão para o log do servidor, não para o WhatsApp real.
        </section>

        <section
          v-if="!loading && isEvolutionProvider && !evolutionReady"
          class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
          <p class="font-semibold">Servidor Evolution não configurado no backend de produção.</p>
          <p class="mt-2 leading-relaxed">
            Adicione estas variáveis no serviço Laravel do Render
            (<strong>Environment</strong>) e faça redeploy:
          </p>
          <p class="mt-2 font-mono text-xs leading-relaxed">
            {{ evolutionSetupHint }}
          </p>
          <p class="mt-2 text-xs leading-relaxed text-amber-800">
            A chave
            <code class="rounded bg-white px-1 py-0.5">EVOLUTION_API_KEY</code>
            deve ser igual ao
            <code class="rounded bg-white px-1 py-0.5">AUTHENTICATION_API_KEY</code>
            do serviço Evolution. Referência: <code class="rounded bg-white px-1 py-0.5">backend/.env.production</code>.
          </p>
        </section>

        <section
          v-if="!loading && isMetaProvider && !metaReady"
          class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
          <p class="font-semibold">WhatsApp oficial (Meta) não configurado no backend.</p>
          <p class="mt-2 font-mono text-xs leading-relaxed">
            {{ metaSetupHint }}
          </p>
        </section>

        <div v-if="loading" class="flex justify-center py-16">
          <Loader2 class="animate-spin text-slate-400" size="32" />
        </div>

        <template v-else>
          <section class="grid gap-3 sm:grid-cols-3">
            <button
              v-for="section in sections"
              :key="section.id"
              type="button"
              class="group rounded-2xl border p-4 text-left transition-all"
              :class="activeSection === section.id
                ? 'border-slate-300 bg-slate-50 shadow-sm ring-1 ring-slate-200'
                : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm'"
              @click="openSection(section.id)"
            >
              <div class="flex items-start justify-between gap-3">
                <div
                  :class="[
                    'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1',
                    section.accent
                  ]"
                >
                  <component :is="section.icon" size="20" />
                </div>
                <span
                  class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wide"
                  :class="activeSection === section.id ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-500'"
                >
                  {{ section.id === 'connection' ? '1' : section.id === 'notifications' ? '2' : '3' }}
                </span>
              </div>

              <p class="mt-3 text-sm font-black text-slate-900">{{ section.label }}</p>
              <p class="mt-1 line-clamp-2 text-xs font-semibold text-slate-500">
                {{ section.description }}
              </p>

              <div class="mt-3 flex items-center justify-between gap-2">
                <span
                  class="text-[10px] font-black uppercase tracking-wide"
                  :class="sectionStatusClass(section.statusTone)"
                >
                  {{ section.status }}
                </span>
                <ChevronRight
                  size="16"
                  class="shrink-0 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500"
                />
              </div>
            </button>
          </section>

          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
            <div class="border-b border-slate-100 pb-5">
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">
                Função {{ activeSection === 'connection' ? '1' : activeSection === 'notifications' ? '2' : '3' }}
              </p>
              <h2 class="mt-1 text-lg font-black text-slate-900">{{ activeSectionMeta?.title }}</h2>
              <p class="mt-1 text-sm font-semibold text-slate-500">{{ activeSectionMeta?.description }}</p>
            </div>

            <!-- 1. Conexão -->
            <div v-show="activeSection === 'connection'" class="mt-6">
              <div class="mb-6 grid gap-3 sm:grid-cols-2">
                <button
                  type="button"
                  class="rounded-2xl border p-4 text-left transition-all"
                  :class="isEvolutionProvider
                    ? 'border-red-200 bg-red-50 ring-1 ring-red-100'
                    : 'border-slate-200 bg-white hover:border-slate-300'"
                  :disabled="switchingProvider || !canConfigure"
                  @click="switchProvider('evolution')"
                >
                  <p class="text-sm font-black text-slate-900">Rápido (QR Code)</p>
                  <p class="mt-1 text-xs font-semibold text-slate-500">
                    WhatsApp comum ou Business no celular. Ideal para começar rápido.
                  </p>
                </button>

                <button
                  type="button"
                  class="rounded-2xl border p-4 text-left transition-all"
                  :class="isMetaProvider
                    ? 'border-emerald-200 bg-emerald-50 ring-1 ring-emerald-100'
                    : 'border-slate-200 bg-white hover:border-slate-300'"
                  :disabled="switchingProvider || !canConfigure"
                  @click="switchProvider('meta')"
                >
                  <p class="text-sm font-black text-slate-900">Oficial (Meta) <span class="text-emerald-700">Recomendado</span></p>
                  <p class="mt-1 text-xs font-semibold text-slate-500">
                    API oficial com verificação por SMS. Mais estável para notificações.
                  </p>
                </button>
              </div>

              <p class="mb-4 rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-xs font-semibold leading-relaxed text-sky-900">
                O cliente recebe status automático (preparo, pronto, cancelado etc.) após pedir pelo cardápio.
                O aviso de &quot;pedido recebido&quot; no WhatsApp só sai depois que o cliente enviar o pedido pelo botão do checkout.
                Os detalhes do pedido na loja continuam vindo como mensagem do cliente.
              </p>

              <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div>
                  <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                      <h3 class="text-sm font-black text-slate-900">
                        {{ isMetaProvider ? 'WhatsApp Business (Meta)' : 'Instância da loja' }}
                      </h3>
                      <p class="mt-1 text-xs font-semibold text-slate-500">
                        {{ isMetaProvider
                          ? 'Conecte o número comercial da loja pela API oficial da Meta.'
                          : 'Cada loja tem uma instância exclusiva na Evolution API.' }}
                      </p>
                    </div>

                    <div v-if="canConfigure" class="flex flex-wrap gap-2">
                      <template v-if="isEvolutionProvider">
                        <button
                          v-if="['pending', 'error', 'disabled'].includes(status)"
                          type="button"
                          class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-xs font-black text-white hover:bg-red-700 disabled:opacity-50"
                          :disabled="provisioning || !evolutionReady"
                          @click="provision(false)"
                        >
                          <Loader2 v-if="provisioning" size="14" class="animate-spin" />
                          {{ provisioning ? 'Ativando...' : status === 'error' ? 'Tentar novamente' : 'Ativar WhatsApp' }}
                        </button>

                        <button
                          v-if="needsQr"
                          type="button"
                          class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-xs font-black text-red-800 hover:bg-red-100 disabled:opacity-50"
                          :disabled="syncing || !evolutionReady"
                          @click="refreshQr(false)"
                        >
                          <RefreshCw :size="14" :class="{ 'animate-spin': syncing }" />
                          Novo QR
                        </button>

                        <button
                          v-if="status === 'connected'"
                          type="button"
                          class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                          :disabled="disconnecting || !evolutionReady"
                          @click="disconnectForNewNumber"
                        >
                          <Loader2 v-if="disconnecting" :size="14" class="animate-spin" />
                          <Unplug v-else :size="14" />
                          Trocar número
                        </button>
                      </template>

                      <template v-else>
                        <button
                          v-if="status !== 'connected'"
                          type="button"
                          class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black text-white hover:bg-emerald-700 disabled:opacity-50"
                          :disabled="metaConnecting || !metaReady"
                          @click="connectMeta"
                        >
                          <Loader2 v-if="metaConnecting" size="14" class="animate-spin" />
                          {{ metaConnecting ? 'Conectando...' : 'Conectar com Meta' }}
                        </button>

                        <button
                          v-if="status === 'connected'"
                          type="button"
                          class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                          :disabled="disconnecting"
                          @click="disconnectMeta"
                        >
                          <Loader2 v-if="disconnecting" :size="14" class="animate-spin" />
                          <Unplug v-else :size="14" />
                          Desconectar
                        </button>
                      </template>

                      <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="syncing"
                        @click="syncConnection(false)"
                      >
                        <RefreshCw :size="14" :class="{ 'animate-spin': syncing }" />
                        Atualizar
                      </button>
                    </div>
                  </div>

                  <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div v-if="isEvolutionProvider" class="rounded-2xl bg-slate-50 px-4 py-3">
                      <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Instância</dt>
                      <dd class="mt-1 text-sm font-black text-slate-900">{{ connection?.instance_name || '—' }}</dd>
                    </div>

                    <div v-if="isMetaProvider" class="rounded-2xl bg-slate-50 px-4 py-3">
                      <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Phone Number ID</dt>
                      <dd class="mt-1 text-sm font-black text-slate-900">{{ connection?.phone_number_id || '—' }}</dd>
                    </div>

                    <div class="rounded-2xl bg-slate-50 px-4 py-3">
                      <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Número</dt>
                      <dd class="mt-1 text-sm font-bold text-slate-800">
                        {{ connection?.display_phone || connection?.whatsapp_number || '—' }}
                      </dd>
                    </div>

                    <div v-if="connection?.connected_at" class="rounded-2xl bg-slate-50 px-4 py-3 sm:col-span-2">
                      <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Conectado em</dt>
                      <dd class="mt-1 text-sm font-semibold text-slate-600">
                        {{ new Date(connection.connected_at).toLocaleString('pt-BR') }}
                      </dd>
                    </div>
                  </dl>

                  <div v-if="connection?.last_error" class="mt-4 rounded-2xl border border-red-100 bg-red-50 px-4 py-3">
                    <p class="text-xs font-black uppercase tracking-wider text-red-700">Último erro</p>
                    <p class="mt-1 text-sm font-semibold text-red-800">{{ connection.last_error }}</p>
                    <p v-if="connection?.error_ref" class="mt-2 text-xs font-bold text-red-700">
                      Código para suporte: {{ connection.error_ref }}
                    </p>
                    <p class="mt-2 text-xs font-semibold text-red-700/80">
                      Detalhes técnicos ficam no Super Admin → Logs de integração.
                    </p>
                  </div>

                  <p v-if="!canConfigure" class="mt-4 text-sm font-semibold text-slate-500">
                    Somente o dono da loja pode ativar ou reconectar o WhatsApp.
                  </p>

                  <p v-else-if="isEvolutionProvider && status === 'pending' && !needsQr" class="mt-4 text-sm font-semibold text-slate-600">
                    Clique em <strong class="text-slate-900">Ativar WhatsApp</strong> para criar a instância e gerar o QR Code.
                  </p>

                  <p v-else-if="isMetaProvider && status !== 'connected'" class="mt-4 text-sm font-semibold text-slate-600">
                    Clique em <strong class="text-slate-900">Conectar com Meta</strong> e conclua a verificação por SMS no número comercial da loja.
                  </p>
                </div>

                <aside class="space-y-4">
                  <div
                    v-if="isEvolutionProvider && needsQr"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-5"
                  >
                    <p class="text-sm font-black text-slate-900">Escaneie o QR Code</p>
                    <p class="mt-1 text-xs font-semibold text-slate-500">
                      WhatsApp → Aparelhos conectados → Conectar aparelho
                    </p>

                    <div class="mt-4 flex min-h-[220px] items-center justify-center rounded-2xl bg-white p-3">
                      <img
                        v-if="qrImageSrc"
                        :src="qrImageSrc"
                        alt="QR Code WhatsApp"
                        class="max-h-[200px] w-full object-contain"
                      >
                      <div v-else class="flex flex-col items-center gap-2 text-slate-400">
                        <Loader2 size="24" class="animate-spin" />
                        <span class="text-xs font-bold">Gerando QR...</span>
                      </div>
                    </div>

                    <p v-if="connection?.qrcode?.pairing_code" class="mt-3 text-center text-xs font-bold text-slate-600">
                      Código: {{ connection.qrcode.pairing_code }}
                    </p>
                  </div>

                  <div
                    v-else-if="status === 'connected'"
                    class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-6 text-center"
                  >
                    <CheckCircle class="mx-auto text-emerald-600" size="40" />
                    <p class="mt-3 text-sm font-black text-emerald-900">WhatsApp conectado</p>
                    <p v-if="connection?.whatsapp_number" class="mt-1 text-xs font-bold text-emerald-800">
                      {{ connection.whatsapp_number }}
                    </p>
                    <p class="mt-1 text-xs font-semibold leading-relaxed text-emerald-700">
                      Pronto para enviar notificações e responder clientes. O número também aparece em Minha loja.
                    </p>
                  </div>

                  <div
                    v-else-if="isMetaProvider && status !== 'connected'"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-center"
                  >
                    <Plug class="mx-auto text-emerald-400" size="40" />
                    <p class="mt-3 text-sm font-black text-slate-700">Conexão oficial Meta</p>
                    <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">
                      Use um número comercial. A Meta envia um código por SMS para verificar o chip.
                    </p>
                  </div>

                  <div
                    v-else-if="status !== 'connected'"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-center"
                  >
                    <Plug class="mx-auto text-slate-300" size="40" />
                    <p class="mt-3 text-sm font-black text-slate-700">Aguardando conexão</p>
                    <p class="mt-1 text-xs font-semibold text-slate-500">
                      Ative o WhatsApp para gerar o QR Code.
                    </p>
                  </div>

                  <div
                    v-if="status === 'connected' && canConfigure"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-5"
                  >
                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">Testar envio</p>
                    <input
                      v-model="testPhone"
                      type="tel"
                      placeholder="11999998888"
                      class="pm-input-sm mt-2 w-full"
                    >
                    <button
                      type="button"
                      class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-xs font-black text-white hover:bg-slate-800 disabled:opacity-50"
                      :disabled="testing"
                      @click="sendTestMessage"
                    >
                      <Loader2 v-if="testing" size="14" class="animate-spin" />
                      <Send v-else size="14" />
                      {{ testing ? 'Enviando...' : 'Enviar teste' }}
                    </button>
                  </div>
                </aside>
              </div>
            </div>

            <!-- 2. Notificações de pedido -->
            <div v-show="activeSection === 'notifications'" class="mt-6">
              <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <h3 class="text-sm font-black text-slate-900">Mensagens por status</h3>
                  <p class="mt-1 max-w-2xl text-xs font-semibold text-slate-500">
                    Personalize o texto enviado em cada mudança de status. Use
                    <code
                      v-for="token in messagePlaceholders"
                      :key="token"
                      class="mx-0.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-700"
                    >{{ token }}</code>
                    como variáveis.
                  </p>
                </div>

                <div v-if="canConfigure" class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50"
                    @click="resetAllMessages"
                  >
                    <RotateCcw size="14" />
                    Restaurar padrão
                  </button>

                  <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-xs font-black text-white hover:bg-red-700 disabled:opacity-50"
                    :disabled="savingMessages || messagesLoading"
                    @click="saveMessages"
                  >
                    <Loader2 v-if="savingMessages" size="14" class="animate-spin" />
                    <Save v-else size="14" />
                    {{ savingMessages ? 'Salvando...' : 'Salvar mensagens' }}
                  </button>
                </div>
              </div>

              <div v-if="messagesLoading" class="flex justify-center py-12">
                <Loader2 class="animate-spin text-red-600" size="28" />
              </div>

              <div v-else class="mt-6 grid gap-4 lg:grid-cols-2">
                <div
                  v-for="key in messageKeys"
                  :key="key"
                  class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
                >
                  <div class="mb-2 flex items-center justify-between gap-2">
                    <label :for="`wa-msg-${key}`" class="text-sm font-black text-slate-900">
                      {{ messageLabels[key] }}
                    </label>
                    <button
                      v-if="canConfigure && isMessageCustomized(key)"
                      type="button"
                      class="text-[10px] font-black uppercase tracking-wider text-slate-400 hover:text-slate-600"
                      @click="resetMessage(key)"
                    >
                      Padrão
                    </button>
                  </div>

                  <textarea
                    :id="`wa-msg-${key}`"
                    v-model="messageDrafts[key]"
                    rows="5"
                    class="pm-input w-full resize-y font-mono text-xs leading-relaxed"
                    :readonly="!canConfigure"
                    :placeholder="messageDefaults[key]"
                  />
                </div>
              </div>

              <p v-if="!canConfigure" class="mt-4 text-sm font-semibold text-slate-500">
                Somente o dono da loja pode editar as mensagens.
              </p>
            </div>

            <!-- 3. Bot de atendimento -->
            <div v-show="activeSection === 'bot'" class="mt-6">
              <div v-if="!hasBotFeature" class="rounded-2xl border border-slate-200 bg-slate-50 px-5 py-8 text-center">
                <Bot class="mx-auto text-slate-300" size="40" />
                <p class="mt-3 text-sm font-black text-slate-800">Bot disponível no plano Pro</p>
                <p class="mt-1 text-xs font-semibold text-slate-500">
                  Faça upgrade para ativar menu automático e, no Premium, IA para dúvidas.
                </p>
              </div>

              <template v-else>
                <div class="flex flex-wrap items-start justify-between gap-4">
                  <div>
                    <h3 class="text-sm font-black text-slate-900">Menu e IA</h3>
                    <p class="mt-1 max-w-2xl text-xs font-semibold text-slate-500">
                      Personalize o menu 1–4, as respostas automáticas e a IA (Premium).
                    </p>
                  </div>

                  <div v-if="canConfigure" class="flex flex-wrap gap-2">
                    <button
                      v-if="botEditing"
                      type="button"
                      class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50"
                      :disabled="savingBot"
                      @click="cancelBotEditing"
                    >
                      Cancelar
                    </button>

                    <button
                      v-if="!botEditing"
                      type="button"
                      class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50"
                      :disabled="botLoading"
                      @click="startBotEditing"
                    >
                      <Pencil size="14" />
                      Editar bot
                    </button>

                    <template v-else>
                      <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50"
                        @click="resetAllBotMessages"
                      >
                        <RotateCcw size="14" />
                        Restaurar padrão
                      </button>

                      <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-xs font-black text-white hover:bg-red-700 disabled:opacity-50"
                        :disabled="savingBot || botLoading"
                        @click="saveBotSettings"
                      >
                        <Loader2 v-if="savingBot" size="14" class="animate-spin" />
                        <Save v-else size="14" />
                        {{ savingBot ? 'Salvando...' : 'Salvar bot' }}
                      </button>
                    </template>
                  </div>
                </div>

                <div v-if="botLoading" class="flex justify-center py-10">
                  <Loader2 class="animate-spin text-red-600" size="28" />
                </div>

                <template v-else-if="!botEditing">
                  <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                      <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">1 · Cardápio</p>
                      <p class="mt-1 text-xs font-semibold text-slate-600">{{ botOptionPreview('option_menu') }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                      <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">2 · Horário</p>
                      <p class="mt-1 text-xs font-semibold text-slate-600">{{ botOptionPreview('option_hours') }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                      <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">3 · Pedido</p>
                      <p class="mt-1 text-xs font-semibold text-slate-600">{{ botOptionPreview('option_order') }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                      <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">4 · Atendente</p>
                      <p class="mt-1 text-xs font-semibold text-slate-600">{{ botOptionPreview('option_human') }}</p>
                    </div>
                  </div>

                  <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">Resumo</p>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                      <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Bot automático</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-700">
                          {{ botForm.whatsapp_bot_enabled ? 'Ativo' : 'Desativado' }}
                        </dd>
                      </div>
                      <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">IA Premium</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-700">
                          {{ botForm.whatsapp_ai_enabled && hasAiFeature ? 'Ativa' : 'Inativa' }}
                        </dd>
                      </div>
                      <div class="sm:col-span-2">
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Textos personalizados</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-700">
                          {{ customizedBotMessagesCount ? `${customizedBotMessagesCount} campo(s)` : 'Usando padrão do sistema' }}
                        </dd>
                      </div>
                    </dl>
                  </div>

                  <p v-if="canConfigure" class="mt-4 text-xs font-semibold text-slate-500">
                    Clique em <strong class="text-slate-700">Editar bot</strong> para alterar opções do menu, respostas e mensagem de boas-vindas.
                  </p>
                </template>

                <div v-else class="mt-5 space-y-5">
                  <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <input
                      v-model="botForm.whatsapp_bot_enabled"
                      type="checkbox"
                      class="mt-1 rounded border-slate-300 text-red-600 focus:ring-red-500"
                      :disabled="!canConfigure"
                    >
                    <span class="text-sm font-bold leading-relaxed text-slate-600">
                      Ativar bot automático (responde mensagens recebidas no WhatsApp conectado)
                    </span>
                  </label>

                  <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">Mensagem de boas-vindas completa (opcional)</p>
                    <p class="mt-1 text-xs font-semibold text-slate-500">
                      Se preencher, substitui o menu montado abaixo. Deixe vazio para usar as opções 1–4 personalizáveis.
                    </p>
                    <textarea
                      v-model="botForm.whatsapp_bot_welcome"
                      rows="4"
                      class="pm-input mt-3 w-full resize-y font-mono text-xs leading-relaxed"
                      :readonly="!canConfigure"
                      placeholder="Deixe vazio para usar o menu padrão com opções 1–4."
                    />
                  </div>

                  <div>
                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">Menu e respostas do bot</p>
                    <p class="mt-1 text-xs font-semibold text-slate-500">
                      Variáveis:
                      <code
                        v-for="token in botMessagePlaceholders"
                        :key="token"
                        class="mx-0.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-700"
                      >{{ token }}</code>
                    </p>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                      <div
                        v-for="key in botMessageKeys"
                        v-show="key !== 'option_ai_hint' || hasAiFeature"
                        :key="key"
                        class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
                      >
                        <div class="mb-2 flex items-center justify-between gap-2">
                          <label :for="`wa-bot-${key}`" class="text-sm font-black text-slate-900">
                            {{ botMessageLabels[key] }}
                          </label>
                          <button
                            v-if="canConfigure && isBotMessageCustomized(key)"
                            type="button"
                            class="text-[10px] font-black uppercase tracking-wider text-slate-400 hover:text-slate-600"
                            @click="resetBotMessage(key)"
                          >
                            Padrão
                          </button>
                        </div>

                        <textarea
                          :id="`wa-bot-${key}`"
                          v-model="botMessageDrafts[key]"
                          :rows="botMessageRows(key)"
                          class="pm-input w-full resize-y font-mono text-xs leading-relaxed"
                          :readonly="!canConfigure"
                          :placeholder="botMessageDefaults[key]"
                        />
                      </div>
                    </div>
                  </div>

                  <div v-if="hasAiFeature" class="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                    <div>
                      <label class="text-xs font-black uppercase tracking-wider text-slate-500">
                        Informações da loja (IA)
                      </label>
                      <p class="mt-1 text-xs font-semibold text-slate-500">
                        Complemente cardápio e horários com políticas da loja. Mínimo {{ aiFaqMinChars }} caracteres.
                      </p>
                      <textarea
                        v-model="botForm.whatsapp_ai_faq"
                        rows="5"
                        class="pm-input mt-2 w-full resize-y text-sm"
                        :readonly="!canConfigure"
                        placeholder="Ex: Pedido mínimo R$ 30. Aceitamos vale-refeição."
                      />
                      <p
                        class="mt-1 text-[11px] font-semibold"
                        :class="aiFaqFilled ? 'text-red-700' : 'text-slate-400'"
                      >
                        {{ botForm.whatsapp_ai_faq.trim().length }}/{{ aiFaqMinChars }} caracteres
                      </p>
                    </div>

                    <label class="flex items-start gap-3">
                      <input
                        v-model="botForm.whatsapp_ai_enabled"
                        type="checkbox"
                        class="mt-1 rounded border-slate-300 text-slate-600 focus:ring-slate-400"
                        :disabled="!canConfigure || !aiCanEnable"
                      >
                      <span class="text-sm font-bold leading-relaxed text-slate-700">
                        <Sparkles size="14" class="inline -mt-0.5 mr-1 text-slate-500" />
                        Ativar IA para responder dúvidas (Premium)
                      </span>
                    </label>

                    <p v-if="!aiFaqFilled" class="text-xs font-semibold text-amber-700">
                      Preencha as informações da loja acima para liberar a IA.
                    </p>

                    <p v-else-if="!aiConfigured" class="text-xs font-semibold text-amber-700">
                      {{ aiProviderLabel }} não configurada no servidor.
                    </p>
                  </div>

                  <div
                    v-else-if="hasBotFeature"
                    class="rounded-2xl border border-slate-200 bg-slate-50/30 p-4"
                  >
                    <p class="text-sm font-bold text-slate-700">
                      <Sparkles size="14" class="inline -mt-0.5 mr-1 text-slate-500" />
                      IA em texto livre — Premium
                    </p>
                    <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">
                      No Pro o bot usa o menu 1–4. Faça upgrade para Premium e cadastre informações da loja para a IA.
                    </p>
                  </div>

                  <p v-if="status !== 'connected'" class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-800">
                    Conecte o WhatsApp na função 1 para o bot receber e responder mensagens reais.
                  </p>
                </div>
              </template>
            </div>
          </section>
        </template>
      </template>
    </div>
</template>
