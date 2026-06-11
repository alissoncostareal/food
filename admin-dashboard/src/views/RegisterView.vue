<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { clearCachedUser } from '@/composables/useFeatureAccess'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import {
  User,
  Mail,
  Lock,
  ArrowRight,
  Loader2,
  CheckCircle,
  XCircle,
  Eye,
  EyeOff
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(false)
const errors = ref(null)
const showPassword = ref(false)
const showPasswordConfirm = ref(false)

const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
})

const passwordStrength = computed(() => {
  const value = form.value.password

  if (!value) return { label: '', className: 'bg-slate-200', width: '0%' }

  let score = 0

  if (value.length >= 8) score++
  if (/[A-Z]/.test(value)) score++
  if (/[0-9]/.test(value)) score++
  if (/[^A-Za-z0-9]/.test(value)) score++

  if (score <= 1) {
    return { label: 'Fraca', className: 'bg-red-500', width: '33%' }
  }

  if (score <= 2) {
    return { label: 'Média', className: 'bg-amber-500', width: '66%' }
  }

  return { label: 'Forte', className: 'bg-emerald-500', width: '100%' }
})

const passwordsMatch = computed(() => {
  if (!form.value.password_confirmation) return true

  return form.value.password === form.value.password_confirmation
})

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const handleRegister = async () => {
  if (!passwordsMatch.value) {
    showNotify('As senhas não coincidem.', 'error')
    return
  }

  loading.value = true
  errors.value = null

  try {
    const response = await api.post('/register/merchant', form.value)

    showNotify('Conta criada com sucesso!')

    clearCachedUser()
    localStorage.setItem('auth_token', response.data.data.token)
    localStorage.setItem('user_name', form.value.name)
    localStorage.setItem('user_role', 'store_owner')

    window.PartiuMenuEcho?.initialize?.({ force: true })

    setTimeout(() => router.push('/onboarding/loja'), 800)
  } catch (err) {
    if (err.response?.status === 422) {
      errors.value = err.response.data.errors
      showNotify('Verifique os dados informados.', 'error')
    } else {
      const message = err.response?.data?.message || 'Erro ao conectar com o servidor.'
      showNotify(message, 'error')
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthLayout
    eyebrow="Comece agora"
    title="Criar minha conta"
    subtitle="Cadastro rápido. Depois você cria a loja matriz e configura tudo no painel."
    hero-title="Sua loja em outro nível."
    hero-highlight="outro nível"
    hero-description="Publique cardápio, receba pedidos e escale com planos feitos para delivery."
    :features="['Setup em minutos', 'Trial para testar', 'Integração iFood no Premium']"
  >
    <form class="space-y-4" @submit.prevent="handleRegister">
      <div class="space-y-2">
        <label for="owner-name" class="text-xs font-black uppercase tracking-widest text-slate-400">
          Seu nome
        </label>
        <div class="relative">
          <User class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="owner-name"
            v-model="form.name"
            type="text"
            autocomplete="name"
            required
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
        </div>
        <p v-if="errors?.name" class="text-xs font-bold text-red-500">{{ errors.name[0] }}</p>
      </div>

      <div class="space-y-2">
        <label for="register-email" class="text-xs font-black uppercase tracking-widest text-slate-400">
          E-mail
        </label>
        <div class="relative">
          <Mail class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="register-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
        </div>
        <p v-if="errors?.email" class="text-xs font-bold text-red-500">{{ errors.email[0] }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-2">
          <label for="register-password" class="text-xs font-black uppercase tracking-widest text-slate-400">
            Senha
          </label>
          <div class="relative">
            <Lock class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
            <input
              id="register-password"
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
          <label for="register-password-confirm" class="text-xs font-black uppercase tracking-widest text-slate-400">
            Confirmar senha
          </label>
          <div class="relative">
            <Lock class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
            <input
              id="register-password-confirm"
              v-model="form.password_confirmation"
              :type="showPasswordConfirm ? 'text' : 'password'"
              autocomplete="new-password"
              required
              minlength="8"
              class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-12 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
            />
            <button
              type="button"
              class="absolute right-3 top-1/2 -translate-y-1/2 rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
              @click="showPasswordConfirm = !showPasswordConfirm"
            >
              <EyeOff v-if="showPasswordConfirm" size="18" />
              <Eye v-else size="18" />
            </button>
          </div>
        </div>
      </div>

      <div v-if="form.password" class="space-y-2">
        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
          <div
            class="h-full rounded-full transition-all duration-300"
            :class="passwordStrength.className"
            :style="{ width: passwordStrength.width }"
          />
        </div>
        <p class="text-xs font-bold text-slate-500">
          Força da senha: <span class="text-slate-700">{{ passwordStrength.label }}</span>
        </p>
      </div>

      <p v-if="errors?.password" class="text-xs font-bold text-red-500">{{ errors.password[0] }}</p>
      <p v-if="!passwordsMatch" class="text-xs font-bold text-red-500">As senhas não coincidem.</p>

      <button
        :disabled="loading || !passwordsMatch"
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-black text-white shadow-lg shadow-red-100 transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-70 active:scale-[0.99]"
      >
        <Loader2 v-if="loading" class="h-5 w-5 animate-spin" />
        <span v-else>Criar minha conta</span>
        <ArrowRight v-if="!loading" class="h-5 w-5" />
      </button>

      <p class="text-center text-xs font-semibold leading-relaxed text-slate-400">
        No próximo passo você cria a loja matriz e define o link do cardápio.
      </p>
    </form>

    <template #footer>
      <p class="text-sm font-semibold text-slate-500">
        Já possui uma conta?
        <router-link to="/login" class="font-black text-red-600 hover:text-red-700">
          Fazer login
        </router-link>
      </p>
    </template>
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
