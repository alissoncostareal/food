<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api, { whatsappRequest } from '@/services/api'
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
const syncing = ref(false)
const disconnecting = ref(false)
const testing = ref(false)
const savingNumber = ref(false)
const metaConnecting = ref(false)
const metaSignupSession = ref(null)
const testPhone = ref('')
const chipNumber = ref('')
const connection = ref(null)

const status = computed(() => connection.value?.status || 'pending')
const metaReady = computed(() => Boolean(connection.value?.meta?.embedded_signup_ready))
const otpTemplateName = computed(() => connection.value?.meta?.otp_template_name || '')

const statusLabels = {
  pending: 'Aguardando conexão',
  connecting: 'Conectando...',
  connected: 'Conectado',
  error: 'Erro na conexão'
}

const statusClass = computed(() => {
  if (status.value === 'connected') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (status.value === 'error') return 'border-red-100 bg-red-50 text-red-700'

  return 'border-slate-200 bg-slate-50 text-slate-500'
})

const statusLabel = computed(() => statusLabels[status.value] || 'Aguardando')

const metaSetupHint = computed(() => {
  const missing = connection.value?.meta?.missing || []

  return missing.length > 0
    ? missing.join(', ')
    : 'META_WHATSAPP_ENABLED, META_WHATSAPP_APP_ID, META_WHATSAPP_APP_SECRET, META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID, META_WHATSAPP_OTP_TEMPLATE_NAME'
})

const applyConnection = (data) => {
  const payload = data?.whatsapp ?? data
  if (!payload) return

  connection.value = payload

  if (!testPhone.value && connection.value?.whatsapp_number) {
    testPhone.value = connection.value.whatsapp_number
  }

  if (!chipNumber.value && connection.value?.whatsapp_number) {
    chipNumber.value = connection.value.whatsapp_number_display || connection.value.whatsapp_number
  }
}

const fetchConnection = async () => {
  try {
    loading.value = true
    const { data } = await api.get('/super-admin/whatsapp/connection')
    applyConnection(data)
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao carregar WhatsApp da plataforma.', 'error')
  } finally {
    loading.value = false
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

  if (!otpTemplateName.value) {
    emit('notify', 'Defina META_WHATSAPP_OTP_TEMPLATE_NAME no backend (template de autenticação aprovado).', 'error')
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

const refreshConnection = async () => {
  try {
    syncing.value = true
    const { data } = await whatsappRequest({ method: 'post', url: '/super-admin/whatsapp/sync' })
    applyConnection(data)
    emit('notify', data.message || 'Status atualizado.')
  } catch (error) {
    emit('notify', integrationErrorNotifyMessage(error, 'Erro ao atualizar status.'), 'error')
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
    emit('notify', data.message || 'Número salvo.')
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao salvar número.', 'error')
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
    const { data } = await whatsappRequest({
      method: 'post',
      url: '/super-admin/whatsapp/test-message',
      data: {
        phone: testPhone.value.trim()
      }
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
          <h2 class="mt-1 text-2xl font-black text-slate-950">WhatsApp oficial (Meta)</h2>
          <p class="mt-1 max-w-2xl text-sm font-semibold text-slate-500">
            Envio de códigos OTP para login dos clientes via API oficial da Meta. Cada loja continua com WhatsApp próprio em Integrações.
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
      v-else-if="!loading && !metaReady"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p class="font-semibold">WhatsApp Meta não configurado no backend.</p>
      <p class="mt-2 font-mono text-xs leading-relaxed">{{ metaSetupHint }}</p>
      <p v-if="otpTemplateName" class="mt-2 text-xs">
        Template OTP: <code class="rounded bg-white px-1">{{ otpTemplateName }}</code>
      </p>
    </section>

    <section
      v-else-if="!loading && metaReady && !otpTemplateName"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p class="font-semibold">Template de autenticação OTP pendente.</p>
      <p class="mt-2 text-xs leading-relaxed">
        Crie e aprove um template de categoria <strong>Autenticação</strong> na Meta e defina
        <code class="rounded bg-white px-1">META_WHATSAPP_OTP_TEMPLATE_NAME</code> no backend.
      </p>
    </section>

    <div v-if="loading" class="flex justify-center py-16">
      <Loader2 class="animate-spin text-slate-400" size="32" />
    </div>

    <section v-else class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
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
          <button
            v-if="status !== 'connected'"
            type="button"
            class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black text-white hover:bg-emerald-700 disabled:opacity-50"
            :disabled="metaConnecting || !metaReady || !otpTemplateName"
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

          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            :disabled="syncing"
            @click="refreshConnection"
          >
            <RefreshCw :size="14" :class="{ 'animate-spin': syncing }" />
            Atualizar
          </button>
        </div>
      </div>

      <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div>
          <dl class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Phone Number ID</dt>
              <dd class="mt-1 text-sm font-black text-slate-900">{{ connection?.phone_number_id || '—' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Template OTP</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">{{ otpTemplateName || 'Não configurado' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Uso</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">{{ connection?.purpose_label || 'OTP de login' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3 sm:col-span-2">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Número conectado</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">
                {{ connection?.whatsapp_number_display || connection?.display_phone || connection?.whatsapp_number || 'Não detectado' }}
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
                  Salvar número
                </button>
              </div>
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

          <p v-if="status !== 'connected'" class="mt-4 text-sm font-semibold text-slate-600">
            Clique em <strong class="text-slate-900">Conectar com Meta</strong> e conclua a verificação por SMS no número da plataforma.
          </p>

          <div
            v-if="status === 'connected'"
            class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4"
          >
            <p class="text-sm font-black text-slate-900">Enviar mensagem de teste</p>
            <p class="mt-1 text-xs font-semibold text-slate-500">
              Envie para <strong class="text-slate-700">outro WhatsApp</strong> (não para o número conectado).
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

        <aside class="space-y-4">
          <div
            v-if="status === 'connected'"
            class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-900"
          >
            Conta conectada. Os clientes receberão códigos OTP por este número ao fazer login.
          </div>

          <div
            v-else
            class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm font-semibold text-slate-500"
          >
            Conecte o número da plataforma pela API oficial da Meta (verificação por SMS).
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-4 text-xs font-semibold text-slate-500 leading-relaxed">
            <p class="font-black text-slate-700">Login OTP</p>
            <p class="mt-2">
              Os códigos de verificação são enviados pelo template de autenticação aprovado na Meta.
            </p>
          </div>
        </aside>
      </div>
    </section>
  </section>
</template>
