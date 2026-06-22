<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api from '@/services/api'
import { integrationErrorNotifyMessage } from '@/utils/integrationErrors'
import {
  Loader2,
  MessageCircle,
  RefreshCw,
  Send,
  Unplug
} from 'lucide-vue-next'

const emit = defineEmits(['notify'])

const loading = ref(true)
const provisioning = ref(false)
const syncing = ref(false)
const disconnecting = ref(false)
const testing = ref(false)
const savingNumber = ref(false)
const metaConnecting = ref(false)
const switchingProvider = ref(false)
const metaSignupSession = ref(null)
const pollTimer = ref(null)
const qrCountdownTimer = ref(null)
const syncInFlight = ref(false)
const testPhone = ref('')
const chipNumber = ref('')
const connection = ref(null)
const qrCountdown = ref(null)

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
  error: 'Erro na conexão'
}

const statusClass = computed(() => {
  if (status.value === 'connected') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (status.value === 'awaiting_qr' || status.value === 'provisioning') return 'border-amber-100 bg-amber-50 text-amber-700'
  if (status.value === 'error') return 'border-red-100 bg-red-50 text-red-700'

  return 'border-slate-200 bg-slate-50 text-slate-500'
})

const statusLabel = computed(() => statusLabels[status.value] || 'Aguardando')

const evolutionReady = computed(() => Boolean(connection.value?.evolution?.configured || connection.value?.test_mode))

const evolutionSetupHint = computed(() => {
  const missing = connection.value?.evolution?.missing || []

  if (missing.length > 0) {
    return missing.join(', ')
  }

  if (connection.value?.evolution?.webhook_url_missing) {
    return 'EVOLUTION_WEBHOOK_URL (opcional para OTP)'
  }

  return 'EVOLUTION_ENABLED, EVOLUTION_API_URL, EVOLUTION_API_KEY, EVOLUTION_INSTANCE_NAME'
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

const applyConnection = (data, { preserveQr = false } = {}) => {
  const payload = data?.whatsapp ?? data
  if (!payload) return

  const previousQr = connection.value?.qrcode

  connection.value = {
    ...payload,
    qrcode: payload.qrcode || (preserveQr && ['awaiting_qr', 'provisioning'].includes(payload.status) ? previousQr : null)
  }

  if (typeof payload.qrcode_expires_in === 'number') {
    qrCountdown.value = payload.qrcode_expires_in
  }

  if (!testPhone.value && connection.value?.whatsapp_number) {
    testPhone.value = connection.value.whatsapp_number
  }

  if (!chipNumber.value && connection.value?.whatsapp_number) {
    chipNumber.value = connection.value.whatsapp_number_display || connection.value.whatsapp_number
  }

  if (needsQr.value && connection.value?.qrcode) {
    startQrCountdown()
  } else {
    stopQrCountdown()
  }
}

const stopQrCountdown = () => {
  if (qrCountdownTimer.value) {
    clearInterval(qrCountdownTimer.value)
    qrCountdownTimer.value = null
  }
}

const startQrCountdown = () => {
  stopQrCountdown()

  if (!needsQr.value || !connection.value?.qrcode) {
    return
  }

  if (qrCountdown.value === null) {
    qrCountdown.value = connection.value?.qrcode_expires_in ?? 45
  }

  qrCountdownTimer.value = setInterval(async () => {
    if (qrCountdown.value === null) return

    qrCountdown.value -= 1

    if (qrCountdown.value <= 0) {
      qrCountdown.value = null
      await refreshQr(true)
    }
  }, 1000)
}

const copyPairingCode = async () => {
  const code = connection.value?.qrcode?.pairing_code
  if (!code) return

  try {
    await navigator.clipboard.writeText(code)
    emit('notify', 'Código copiado.')
  } catch {
    emit('notify', 'Não foi possível copiar o código.', 'error')
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

  if (!isEvolutionProvider.value || status.value === 'connected') {
    return
  }

  pollTimer.value = setInterval(() => {
    syncConnection(true)
  }, 15000)
}

const fetchConnection = async () => {
  try {
    loading.value = true
    const { data } = await api.get('/super-admin/whatsapp/connection')
    applyConnection(data)
    await maybeAutoStart()
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao carregar WhatsApp da plataforma.', 'error')
  } finally {
    loading.value = false
    startPolling()
  }
}

const maybeAutoStart = async () => {
  if (!isEvolutionProvider.value || connection.value?.instance_name_missing || !evolutionReady.value) {
    return
  }

  if (['pending', 'error'].includes(status.value)) {
    await provision(true)
    return
  }

  if (status.value === 'awaiting_qr' && !qrImageSrc.value) {
    await refreshQr(true)
  }
}

const switchProvider = async (nextProvider) => {
  if (provider.value === nextProvider || switchingProvider.value) {
    return
  }

  try {
    switchingProvider.value = true
    stopPolling()
    const { data } = await api.put('/super-admin/whatsapp/provider', { provider: nextProvider })
    applyConnection(data?.whatsapp || data)
    emit('notify', data.message || 'Modo de conexão atualizado.')

    if (nextProvider === 'evolution') {
      await maybeAutoStart()
      startPolling()
    }
  } catch (error) {
    emit('notify', integrationErrorNotifyMessage(error, 'Erro ao trocar modo.'), 'error')
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

  if (document.getElementById('facebook-jssdk')) {
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
  if (!metaReady.value) {
    emit('notify', `Configure no backend: ${metaSetupHint.value}`, 'error')
    return
  }

  try {
    metaConnecting.value = true
    const { data } = await api.get('/super-admin/whatsapp/meta/config')
    const signup = data?.meta?.embedded_signup

    if (!signup?.app_id || !signup?.config_id) {
      emit('notify', 'Cadastro incorporado da Meta indisponível.', 'error')
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
            emit('notify', 'Conexão com a Meta cancelada ou incompleta.', 'error')
            return
          }

          if (!session.waba_id || !session.phone_number_id) {
            emit('notify', 'Meta não retornou os dados do número. Tente novamente.', 'error')
            return
          }

          const complete = await api.post('/super-admin/whatsapp/meta/complete-signup', {
            code,
            waba_id: session.waba_id,
            phone_number_id: session.phone_number_id,
          })

          applyConnection(complete.data)
          emit('notify', complete.data?.message || 'WhatsApp oficial conectado.')
        } catch (error) {
          emit('notify', integrationErrorNotifyMessage(error, 'Erro ao conectar WhatsApp oficial.'), 'error')
        } finally {
          detachMetaSignupListener()
          metaConnecting.value = false
        }
      })()
    }, {
      config_id: signup.config_id,
      response_type: 'code',
      override_default_response_type: true,
      extras: { setup: {} },
    })
  } catch (error) {
    detachMetaSignupListener()
    metaConnecting.value = false
    emit('notify', integrationErrorNotifyMessage(error, 'Erro ao iniciar conexão Meta.'), 'error')
  }
}

const disconnectMeta = async () => {
  try {
    disconnecting.value = true
    const { data } = await api.post('/super-admin/whatsapp/meta/disconnect')
    applyConnection(data)
    emit('notify', data.message || 'WhatsApp oficial desconectado.')
  } catch (error) {
    emit('notify', integrationErrorNotifyMessage(error, 'Erro ao desconectar.'), 'error')
  } finally {
    disconnecting.value = false
  }
}

const provision = async (silent = false) => {
  if (connection.value?.instance_name_missing) {
    emit('notify', 'Defina EVOLUTION_INSTANCE_NAME no backend (ex.: partiumenu-otp).', 'error')
    return
  }

  if (!evolutionReady.value) {
    emit('notify', `Evolution não configurado: ${evolutionSetupHint.value}`, 'error')
    return
  }

  try {
    provisioning.value = true
    const { data } = await api.post('/super-admin/whatsapp/provision')
    applyConnection(data)

    if (!silent) {
      emit('notify', data.message || 'Instância criada. Escaneie o QR Code.')
    }

    startPolling()
  } catch (error) {
    if (!silent) {
      emit('notify', integrationErrorNotifyMessage(error, 'Erro ao ativar WhatsApp da plataforma.'), 'error')
    }
  } finally {
    provisioning.value = false
  }
}

const syncConnection = async (silent = false) => {
  if (syncInFlight.value) {
    return
  }

  try {
    syncInFlight.value = true
    syncing.value = true
    const { data } = await api.post('/super-admin/whatsapp/sync')
    applyConnection(data, { preserveQr: true })

    if (connection.value?.status === 'connected') {
      stopPolling()
      stopQrCountdown()
      if (!silent) {
        emit('notify', data.message || 'WhatsApp conectado!')
      }
      return
    }

    if (!silent && !data.transient) {
      emit('notify', data.message || 'Status atualizado.')
    }

    startPolling()
  } catch (error) {
    if (!silent && !error.response?.data?.transient) {
      emit('notify', integrationErrorNotifyMessage(error, 'Erro ao sincronizar conexão.'), 'error')
    }
  } finally {
    syncing.value = false
    syncInFlight.value = false
  }
}

const disconnectForNewNumber = async () => {
  const confirmed = window.confirm(
    'Desconectar o chip atual e gerar um novo QR Code?\n\nO nome da instância permanece o mesmo — você só precisa escanear com o novo número.'
  )

  if (!confirmed) return

  try {
    disconnecting.value = true
    const { data } = await api.post('/super-admin/whatsapp/disconnect')
    applyConnection(data)
    emit('notify', data.message || 'Escaneie o QR Code com o novo chip.')
    startPolling()
  } catch (error) {
    emit('notify', integrationErrorNotifyMessage(error, 'Erro ao desconectar WhatsApp.'), 'error')
  } finally {
    disconnecting.value = false
  }
}

const refreshQr = async (silent = false) => {
  try {
    syncing.value = true
    const { data } = await api.get('/super-admin/whatsapp/qrcode')
    applyConnection(data)
    qrCountdown.value = connection.value?.qrcode_expires_in ?? 45

    if (!silent) {
      emit('notify', data.message || 'QR Code atualizado.')
    }

    startPolling()
  } catch (error) {
    if (!silent) {
      emit('notify', error.response?.data?.message || 'Erro ao atualizar QR Code.', 'error')
    }
  } finally {
    syncing.value = false
  }
}

const saveChipNumber = async () => {
  if (!chipNumber.value.trim()) {
    emit('notify', 'Informe o número do chip com DDD.', 'error')
    return
  }

  try {
    savingNumber.value = true
    const { data } = await api.put('/super-admin/whatsapp/number', {
      phone: chipNumber.value.trim()
    })
    applyConnection(data)
    emit('notify', data.message || 'Número do chip salvo.')
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao salvar número do chip.', 'error')
  } finally {
    savingNumber.value = false
  }
}

const sendTestMessage = async () => {
  if (!testPhone.value.trim()) {
    emit('notify', 'Informe um número com DDD para o teste.', 'error')
    return
  }

  try {
    testing.value = true
    const { data } = await api.post('/super-admin/whatsapp/test-message', {
      phone: testPhone.value.trim()
    })
    applyConnection(data)
    emit('notify', data.message || 'Mensagem de teste enviada.')
  } catch (error) {
    if (error.response?.data?.whatsapp) {
      applyConnection(error.response.data)
    }

    emit('notify', error.response?.data?.message || integrationErrorNotifyMessage(error, 'Erro ao enviar teste.'), 'error')
  } finally {
    testing.value = false
  }
}

onMounted(fetchConnection)

onBeforeUnmount(() => {
  stopPolling()
  stopQrCountdown()
  detachMetaSignupListener()
})
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex items-start gap-4">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
          <MessageCircle size="22" />
        </div>

        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Plataforma</p>
          <h2 class="mt-1 text-2xl font-black text-slate-950">WhatsApp PartiuMenu</h2>
          <p class="mt-1 max-w-2xl text-sm font-semibold text-slate-500">
            Chip oficial para enviar códigos OTP de login aos clientes. Cada loja continua com instância própria em Integrações.
          </p>
        </div>
      </div>
    </div>

    <section
      v-if="connection?.test_mode"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900"
    >
      Modo teste ativo — mensagens vão para o log do servidor, não para o WhatsApp real.
    </section>

    <section
      v-if="connection?.instance_name_missing && isEvolutionProvider"
      class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-950"
    >
      <p class="font-semibold">EVOLUTION_INSTANCE_NAME não configurado no backend.</p>
      <p class="mt-2 text-xs leading-relaxed">
        Defina no Render (ex.: <code class="rounded bg-white px-1 py-0.5">partiumenu-otp</code>) e faça redeploy antes de conectar o chip.
      </p>
    </section>

    <section
      v-else-if="!loading && isEvolutionProvider && !evolutionReady"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p class="font-semibold">Evolution API não configurada no backend.</p>
      <p class="mt-2 font-mono text-xs leading-relaxed">{{ evolutionSetupHint }}</p>
    </section>

    <section
      v-else-if="!loading && isMetaProvider && !metaReady"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p class="font-semibold">WhatsApp oficial (Meta) não configurado no backend.</p>
      <p class="mt-2 font-mono text-xs leading-relaxed">{{ metaSetupHint }}</p>
      <p v-if="connection?.meta?.otp_template_name" class="mt-2 text-xs">
        Template OTP: <code class="rounded bg-white px-1">{{ connection.meta.otp_template_name }}</code>
      </p>
    </section>

    <div v-if="loading" class="flex justify-center py-16">
      <Loader2 class="animate-spin text-slate-400" size="32" />
    </div>

    <section v-else class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
      <div class="mb-6 grid gap-3 sm:grid-cols-2">
        <button
          type="button"
          class="rounded-2xl border p-4 text-left transition-all"
          :class="isEvolutionProvider
            ? 'border-red-200 bg-red-50 ring-1 ring-red-100'
            : 'border-slate-200 bg-white hover:border-slate-300'"
          :disabled="switchingProvider"
          @click="switchProvider('evolution')"
        >
          <p class="text-sm font-black text-slate-900">Rápido (QR Code)</p>
          <p class="mt-1 text-xs font-semibold text-slate-500">Evolution + chip no celular</p>
        </button>

        <button
          type="button"
          class="rounded-2xl border p-4 text-left transition-all"
          :class="isMetaProvider
            ? 'border-emerald-200 bg-emerald-50 ring-1 ring-emerald-100'
            : 'border-slate-200 bg-white hover:border-slate-300'"
          :disabled="switchingProvider"
          @click="switchProvider('meta')"
        >
          <p class="text-sm font-black text-slate-900">Oficial (Meta) <span class="text-emerald-700">Recomendado</span></p>
          <p class="mt-1 text-xs font-semibold text-slate-500">API oficial + template de autenticação</p>
        </button>
      </div>

      <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-5">
        <div>
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Status</p>
          <span
            class="mt-2 inline-flex rounded-full border px-3 py-1 text-xs font-black uppercase tracking-wide"
            :class="statusClass"
          >
            {{ statusLabel }}
          </span>
        </div>

        <div class="flex flex-wrap gap-2">
          <template v-if="isEvolutionProvider">
            <button
              v-if="['pending', 'error'].includes(status)"
              type="button"
              class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-xs font-black text-white hover:bg-red-700 disabled:opacity-50"
              :disabled="provisioning || !evolutionReady || connection?.instance_name_missing"
              @click="provision(false)"
            >
              <Loader2 v-if="provisioning" size="14" class="animate-spin" />
              {{ provisioning ? 'Ativando...' : status === 'error' ? 'Tentar novamente' : 'Criar instância' }}
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
              <Loader2 v-if="disconnecting" size="14" class="animate-spin" />
              <Unplug v-else :size="14" />
              Trocar chip
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
              <Loader2 v-if="disconnecting" size="14" class="animate-spin" />
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

      <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div>
          <dl class="grid gap-4 sm:grid-cols-2">
            <div v-if="isEvolutionProvider" class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Instância</dt>
              <dd class="mt-1 text-sm font-black text-slate-900">{{ connection?.instance_name || '—' }}</dd>
            </div>

            <div v-if="isMetaProvider" class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Phone Number ID</dt>
              <dd class="mt-1 text-sm font-black text-slate-900">{{ connection?.phone_number_id || '—' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Uso</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">{{ connection?.purpose_label || 'OTP de login' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3 sm:col-span-2">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Número conectado</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">
                {{ connection?.whatsapp_number_display || connection?.whatsapp_number || 'Não detectado automaticamente' }}
              </dd>

              <div v-if="status === 'connected'" class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input
                  v-model="chipNumber"
                  type="tel"
                  inputmode="tel"
                  placeholder="Número do chip (ex: 85989102317)"
                  class="flex-1 rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm font-bold focus:border-red-500 focus:ring-red-500"
                >
                <button
                  type="button"
                  class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  :disabled="savingNumber"
                  @click="saveChipNumber"
                >
                  <Loader2 v-if="savingNumber" size="14" class="animate-spin" />
                  Salvar número do chip
                </button>
              </div>
              <p v-if="status === 'connected' && connection?.whatsapp_number_missing" class="mt-2 text-xs font-semibold text-amber-700">
                A Evolution não retornou o número. Salve manualmente o número do chip acima.
              </p>
            </div>

            <div v-if="connection?.connected_at" class="rounded-2xl bg-slate-50 px-4 py-3">
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
              Código: {{ connection.error_ref }}
            </p>
          </div>

          <p v-if="isEvolutionProvider && status === 'pending' && !needsQr" class="mt-4 text-sm font-semibold text-slate-600">
            Clique em <strong class="text-slate-900">Criar instância</strong> e escaneie o QR com o celular do chip PartiuMenu.
          </p>

          <p v-else-if="isMetaProvider && status !== 'connected'" class="mt-4 text-sm font-semibold text-slate-600">
            Clique em <strong class="text-slate-900">Conectar com Meta</strong> e conclua a verificação por SMS no chip PartiuMenu.
          </p>

          <div
            v-if="status === 'connected'"
            class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4"
          >
            <p class="text-sm font-black text-slate-900">Enviar mensagem de teste</p>
            <p class="mt-1 text-xs font-semibold text-slate-500">
              Envie para <strong class="text-slate-700">outro WhatsApp</strong> (não para o chip conectado).
            </p>

            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
              <input
                v-model="testPhone"
                type="tel"
                inputmode="tel"
                placeholder="DDD + número"
                class="flex-1 rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm font-bold focus:border-red-500 focus:ring-red-500"
              >
              <button
                type="button"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-black text-white hover:bg-slate-800 disabled:opacity-50"
                :disabled="testing"
                @click="sendTestMessage"
              >
                <Loader2 v-if="testing" size="14" class="animate-spin" />
                <Send v-else size="14" />
                Enviar teste
              </button>
            </div>
          </div>
        </div>

        <aside>
          <div
            v-if="isEvolutionProvider && needsQr"
            class="rounded-2xl border border-slate-200 bg-slate-50 p-5"
          >
            <p class="text-sm font-black text-slate-900">Conectar o chip</p>
            <p class="mt-1 text-xs font-semibold text-slate-500">
              Use o celular com o chip PartiuMenu. O QR expira em ~45s — renovamos automaticamente.
            </p>

            <div
              v-if="connection?.qrcode?.pairing_code"
              class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3"
            >
              <p class="text-xs font-black uppercase tracking-wider text-emerald-800">Recomendado para chip</p>
              <p class="mt-1 text-xs font-semibold text-emerald-900">
                WhatsApp → Aparelhos conectados → Conectar com número → digite o código:
              </p>
              <p class="mt-2 text-center text-lg font-black tracking-[0.2em] text-emerald-950">
                {{ connection.qrcode.pairing_code }}
              </p>
              <button
                type="button"
                class="mt-3 w-full rounded-xl bg-emerald-700 px-3 py-2 text-xs font-black text-white"
                @click="copyPairingCode"
              >
                Copiar código de pareamento
              </button>
            </div>

            <p class="mt-4 text-xs font-black uppercase tracking-wider text-slate-400">Ou escaneie o QR</p>

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

            <p v-if="qrCountdown !== null" class="mt-3 text-center text-xs font-bold text-amber-700">
              QR válido por {{ qrCountdown }}s — não feche esta tela enquanto escaneia
            </p>
          </div>

          <div
            v-else-if="status === 'connected'"
            class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-900"
          >
            Chip conectado. Os clientes receberão códigos OTP por este número ao fazer login.
          </div>

          <div
            v-else-if="isMetaProvider && status !== 'connected'"
            class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm font-semibold text-slate-500"
          >
            Conecte o chip PartiuMenu pela API oficial da Meta (verificação por SMS).
          </div>

          <div
            v-else-if="status !== 'connected'"
            class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm font-semibold text-slate-500"
          >
            O QR Code aparece aqui após criar a instância.
          </div>

          <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-xs font-semibold text-slate-500 leading-relaxed">
            <p class="font-black text-slate-700">Trocar chip no futuro</p>
            <p class="mt-2">
              Use <strong class="text-slate-800">Trocar chip</strong>, coloque o novo SIM no celular e escaneie o QR.
              O nome da instância não muda — não precisa alterar variáveis no servidor.
            </p>
          </div>
        </aside>
      </div>
    </section>
  </section>
</template>
