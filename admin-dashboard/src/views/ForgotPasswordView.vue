<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { getApiErrorMessage } from '@/utils/apiError'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import {
  ArrowLeft,
  ArrowRight,
  CheckCircle,
  Loader2,
  Mail,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(false)
const sent = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  email: ''
})

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const handleSubmit = async () => {
  loading.value = true

  try {
    const { data } = await api.post('/forgot-password', {
      email: form.value.email
    })

    sent.value = true
    showNotify(data.message || 'E-mail enviado com sucesso.')
  } catch (error) {
    showNotify(
      getApiErrorMessage(error, 'Não foi possível enviar o e-mail agora.'),
      'error'
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthLayout
    eyebrow="Recuperação de acesso"
    title="Esqueceu a senha?"
    subtitle="Informe o e-mail da sua conta no painel. Enviaremos um link para redefinir a senha."
    hero-title="Volte ao painel com segurança."
    hero-highlight="segurança"
    hero-description="Donos de loja e funcionários podem recuperar o acesso pelo e-mail cadastrado."
    :features="['Link seguro', 'Expira em 60 min', 'Acesso individual']"
  >
    <div v-if="sent" class="rounded-2xl border border-emerald-100 bg-emerald-50 p-6 text-center">
      <CheckCircle class="mx-auto text-emerald-600" size="32" />
      <p class="mt-4 text-sm font-black text-emerald-900">
        Verifique sua caixa de entrada
      </p>
      <p class="mt-2 text-sm font-semibold leading-relaxed text-emerald-700">
        Se <strong>{{ form.email }}</strong> estiver cadastrado no painel, você receberá um link para criar uma nova senha.
      </p>
      <button
        type="button"
        class="mt-6 inline-flex items-center gap-2 rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-black text-white transition hover:bg-emerald-700"
        @click="router.push('/login')"
      >
        <ArrowLeft size="16" />
        Voltar ao login
      </button>
    </div>

    <form v-else class="space-y-5" @submit.prevent="handleSubmit">
      <div class="space-y-2">
        <label for="forgot-email" class="text-xs font-black uppercase tracking-widest text-slate-400">
          E-mail da conta
        </label>
        <div class="relative">
          <Mail class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="forgot-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
        </div>
      </div>

      <button
        :disabled="loading"
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-black text-white shadow-lg shadow-red-100 transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-70"
      >
        <Loader2 v-if="loading" class="h-5 w-5 animate-spin" />
        <span v-else>Enviar link de recuperação</span>
        <ArrowRight v-if="!loading" class="h-5 w-5" />
      </button>

      <button
        type="button"
        class="flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 py-3.5 text-sm font-black text-slate-600 transition hover:bg-slate-50"
        @click="router.push('/login')"
      >
        <ArrowLeft size="16" />
        Voltar ao login
      </button>
    </form>
  </AuthLayout>

  <div
    v-if="toast.show"
    class="fixed bottom-5 right-5 z-50 flex max-w-sm items-center gap-3 rounded-2xl border border-slate-800 bg-slate-950 px-5 py-4 text-white shadow-2xl animate-in"
  >
    <CheckCircle v-if="toast.type === 'success'" class="h-5 w-5 shrink-0 text-emerald-400" />
    <XCircle v-else class="h-5 w-5 shrink-0 text-red-400" />
    <span class="text-sm font-bold">{{ toast.message }}</span>
  </div>
</template>

<style scoped>
.animate-in {
  animation: slideIn 0.28s ease-out;
}

@keyframes slideIn {
  from {
    transform: translateY(16px);
    opacity: 0;
  }

  to {
    transform: translateY(0);
    opacity: 1;
  }
}
</style>
