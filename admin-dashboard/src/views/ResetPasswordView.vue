<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import {
  ArrowRight,
  CheckCircle,
  Eye,
  EyeOff,
  Loader2,
  Lock,
  XCircle
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const done = ref(false)
const showPassword = ref(false)
const showConfirmation = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  token: typeof route.query.token === 'string' ? route.query.token : '',
  password: '',
  password_confirmation: ''
})

const hasRequiredParams = computed(() => Boolean(form.value.email && form.value.token))

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const handleSubmit = async () => {
  if (form.value.password !== form.value.password_confirmation) {
    showNotify('As senhas não coincidem.', 'error')
    return
  }

  loading.value = true

  try {
    const { data } = await api.post('/reset-password', form.value)
    done.value = true
    showNotify(data.message || 'Senha redefinida com sucesso.')
    setTimeout(() => router.push('/login'), 1200)
  } catch (error) {
    showNotify(
      error.response?.data?.message || error.response?.data?.details || 'Não foi possível redefinir a senha.',
      'error'
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthLayout
    eyebrow="Nova senha"
    title="Redefinir senha"
    subtitle="Escolha uma nova senha para acessar o painel."
    hero-title="Acesso restaurado em poucos passos."
    hero-highlight="passos"
    hero-description="Use uma senha forte com pelo menos 8 caracteres."
    :features="['Mínimo 8 caracteres', 'Confirmação obrigatória', 'Sessões antigas encerradas']"
  >
    <div v-if="!hasRequiredParams" class="rounded-2xl border border-red-100 bg-red-50 p-6 text-center">
      <p class="font-black text-red-700">Link inválido</p>
      <p class="mt-2 text-sm font-semibold text-red-600">
        Solicite um novo e-mail em
        <router-link to="/forgot-password" class="font-black underline">Esqueci minha senha</router-link>.
      </p>
    </div>

    <div v-else-if="done" class="rounded-2xl border border-emerald-100 bg-emerald-50 p-6 text-center">
      <CheckCircle class="mx-auto text-emerald-600" size="32" />
      <p class="mt-4 text-sm font-black text-emerald-900">Senha atualizada</p>
      <p class="mt-2 text-sm font-semibold text-emerald-700">Redirecionando para o login...</p>
    </div>

    <form v-else class="space-y-5" @submit.prevent="handleSubmit">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-600">
        {{ form.email }}
      </div>

      <div class="space-y-2">
        <label for="reset-password" class="text-xs font-black uppercase tracking-widest text-slate-400">
          Nova senha
        </label>
        <div class="relative">
          <Lock class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="reset-password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            required
            minlength="8"
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-12 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
          <button
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" size="18" />
            <Eye v-else size="18" />
          </button>
        </div>
      </div>

      <div class="space-y-2">
        <label for="reset-password-confirmation" class="text-xs font-black uppercase tracking-widest text-slate-400">
          Confirmar nova senha
        </label>
        <div class="relative">
          <Lock class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="reset-password-confirmation"
            v-model="form.password_confirmation"
            :type="showConfirmation ? 'text' : 'password'"
            autocomplete="new-password"
            required
            minlength="8"
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-12 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
          <button
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
            @click="showConfirmation = !showConfirmation"
          >
            <EyeOff v-if="showConfirmation" size="18" />
            <Eye v-else size="18" />
          </button>
        </div>
      </div>

      <button
        :disabled="loading"
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-black text-white shadow-lg shadow-red-100 transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-70"
      >
        <Loader2 v-if="loading" class="h-5 w-5 animate-spin" />
        <span v-else>Salvar nova senha</span>
        <ArrowRight v-if="!loading" class="h-5 w-5" />
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
