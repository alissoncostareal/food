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
const pollTimer = ref(null)
const qrCountdownTimer = ref(null)
const syncInFlight = ref(false)
const testPhone = ref('')
const connection = ref(null)
const qrCountdown = ref(null)

const statusLabels = {
  pending: 'Aguardando ativação',
  provisioning: 'Provisionando...',
  awaiting_qr: 'Escaneie o QR Code',
  connected: 'Conectado',
  error: 'Erro na conexão'
}

const status = computed(() => connection.value?.status || 'pending')

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

const needsQr = computed(() => ['awaiting_qr', 'provisioning'].includes(status.value))

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

  if (status.value === 'connected') {
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
  if (connection.value?.instance_name_missing || !evolutionReady.value) {
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
    emit('notify', data.message || 'Mensagem de teste enviada.')
  } catch (error) {
    const transient = error.response?.status === 503 || error.response?.data?.transient
    emit('notify', integrationErrorNotifyMessage(
      error,
      transient
        ? 'Evolution demorou a responder. Aguarde e tente novamente.'
        : 'Erro ao enviar teste.'
    ), transient ? 'warning' : 'error')
  } finally {
    testing.value = false
  }
}

onMounted(fetchConnection)

onBeforeUnmount(() => {
  stopPolling()
  stopQrCountdown()
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
      v-if="connection?.instance_name_missing"
      class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-950"
    >
      <p class="font-semibold">EVOLUTION_INSTANCE_NAME não configurado no backend.</p>
      <p class="mt-2 text-xs leading-relaxed">
        Defina no Render (ex.: <code class="rounded bg-white px-1 py-0.5">partiumenu-otp</code>) e faça redeploy antes de conectar o chip.
      </p>
    </section>

    <section
      v-else-if="!loading && !evolutionReady"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p class="font-semibold">Evolution API não configurada no backend.</p>
      <p class="mt-2 font-mono text-xs leading-relaxed">{{ evolutionSetupHint }}</p>
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
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            :disabled="syncing"
            @click="syncConnection(false)"
          >
            <RefreshCw :size="14" :class="{ 'animate-spin': syncing }" />
            Atualizar
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
        </div>
      </div>

      <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div>
          <dl class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Instância</dt>
              <dd class="mt-1 text-sm font-black text-slate-900">{{ connection?.instance_name || '—' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Uso</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">{{ connection?.purpose_label || 'OTP de login' }}</dd>
            </div>

            <div class="rounded-2xl bg-slate-50 px-4 py-3">
              <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Número conectado</dt>
              <dd class="mt-1 text-sm font-bold text-slate-800">{{ connection?.whatsapp_number || '—' }}</dd>
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

          <p v-if="status === 'pending' && !needsQr" class="mt-4 text-sm font-semibold text-slate-600">
            Clique em <strong class="text-slate-900">Criar instância</strong> e escaneie o QR com o celular do chip PartiuMenu.
          </p>

          <div
            v-if="status === 'connected'"
            class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4"
          >
            <p class="text-sm font-black text-slate-900">Enviar mensagem de teste</p>
            <p class="mt-1 text-xs font-semibold text-slate-500">
              Confirme que o chip está enviando mensagens antes de liberar o login por OTP.
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
            v-if="needsQr"
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
            v-else
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
