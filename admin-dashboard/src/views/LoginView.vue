<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'
import { clearCachedUser } from '@/composables/useFeatureAccess'
import { syncUserSession } from '@/utils/authSession'
import { getApiErrorMessage } from '@/utils/apiError'
import { isPlatformAdmin } from '@/utils/platformAdmin'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import {
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
const route = useRoute()
const loading = ref(false)
const errors = ref(null)
const showPassword = ref(false)

const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  email: '',
  password: '',
  remember: false
})

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const isSuperAdminLogin = computed(() => route.query.notice === 'super_admin')

const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1'

const loginWithGoogle = () => {
  window.location.href = `${apiBaseUrl}/auth/google/redirect`
}

onMounted(() => {
  if (route.query.notice === 'super_admin') {
    showNotify('Entre com a conta de super admin da plataforma (não use o e-mail da loja).', 'error')
  }

  if (route.query.notice === 'google_error') {
    showNotify('Não foi possível entrar com o Google. Tente novamente ou use e-mail e senha.', 'error')
  }
})

const handleLogin = async () => {
  loading.value = true
  errors.value = null

  try {
    const response = await api.post('/login', form.value)

    showNotify('Login realizado com sucesso!')

    clearCachedUser()
    localStorage.setItem('auth_token', response.data.access_token)
    syncUserSession(response.data.user)
    window.PartiuMenuEcho?.initialize?.({ force: true })

    const user = response.data.user
    const redirectPath = typeof route.query.redirect === 'string' ? route.query.redirect : null
    let targetRoute = redirectPath || '/dashboard'

    if (isPlatformAdmin(user)) {
      targetRoute = redirectPath?.startsWith('/super-admin') ? redirectPath : '/super-admin/overview'
    } else if (user.needs_onboarding) {
      targetRoute = '/onboarding/loja'
    } else if (redirectPath?.startsWith('/super-admin')) {
      targetRoute = '/dashboard'
    }

    setTimeout(() => router.push(targetRoute), 700)
  } catch (err) {
    if (err.response?.status === 401 || err.response?.status === 422) {
      showNotify('E-mail ou senha incorretos.', 'error')
      errors.value = err.response.data.errors
    } else if (err.response?.status === 429) {
      showNotify(
        err.userMessage || getApiErrorMessage(err, 'Muitas tentativas. Aguarde um momento e tente novamente.'),
        'error'
      )
    } else {
      showNotify(
        getApiErrorMessage(err, 'Erro ao conectar com o servidor.'),
        'error'
      )
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthLayout
    :eyebrow="isSuperAdminLogin ? 'Super Admin' : 'Acesso seguro'"
    :title="isSuperAdminLogin ? 'Entrar como super admin' : 'Entrar no painel'"
    :subtitle="isSuperAdminLogin ? 'Use a conta de administrador da plataforma (não o e-mail da loja).' : 'Use o e-mail cadastrado na sua loja para continuar.'"
    :hero-title="isSuperAdminLogin ? 'Gerencie toda a plataforma.' : 'Seu delivery no controle.'"
    :hero-highlight="isSuperAdminLogin ? 'plataforma' : 'controle'"
    :hero-description="isSuperAdminLogin ? 'Lojas, planos, cortesias e configurações globais do PartiuMenu.' : 'Acompanhe pedidos ao vivo, avise clientes pelo WhatsApp e gerencie cardápio e entregas sem complicação.'"
    :features="isSuperAdminLogin ? ['Todas as lojas', 'Planos e billing', 'Cortesias'] : ['Pedidos ao vivo com alerta', 'WhatsApp e status automático', 'Painel fácil de usar']"
  >
    <form class="space-y-5" @submit.prevent="handleLogin">
      <div class="space-y-2">
        <label for="login-email" class="text-xs font-black uppercase tracking-widest text-slate-400">
          E-mail
        </label>
        <div class="relative">
          <Mail class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="login-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
        </div>
        <p v-if="errors?.email" class="text-xs font-bold text-red-500">{{ errors.email[0] }}</p>
      </div>

      <div class="space-y-2">
        <label for="login-password" class="text-xs font-black uppercase tracking-widest text-slate-400">
          Senha
        </label>
        <div class="relative">
          <Lock class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="login-password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            required
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-12 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
          <button
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
            :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" size="18" />
            <Eye v-else size="18" />
          </button>
        </div>
      </div>

      <div class="flex items-center justify-between gap-3">
        <label class="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-600">
          <input
            v-model="form.remember"
            type="checkbox"
            class="h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-500"
          />
          Lembrar deste dispositivo
        </label>

        <router-link
          to="/forgot-password"
          class="text-sm font-black text-red-600 transition hover:text-red-700"
        >
          Esqueci minha senha
        </router-link>
      </div>

      <button
        :disabled="loading"
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-black text-white shadow-lg shadow-red-100 transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-70 active:scale-[0.99]"
      >
        <Loader2 v-if="loading" class="h-5 w-5 animate-spin" />
        <span v-else>Acessar painel</span>
        <ArrowRight v-if="!loading" class="h-5 w-5" />
      </button>

      <div class="relative py-2">
        <div class="absolute inset-0 flex items-center">
          <div class="w-full border-t border-slate-200" />
        </div>
        <div class="relative flex justify-center text-xs font-black uppercase tracking-widest">
          <span class="bg-white px-3 text-slate-400">ou</span>
        </div>
      </div>

      <button
        type="button"
        :disabled="loading"
        class="flex w-full items-center justify-center gap-3 rounded-2xl border border-slate-200 bg-white py-4 text-sm font-black text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 active:scale-[0.99]"
        @click="loginWithGoogle"
      >
        <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
          <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
          <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
          <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
        </svg>
        Entrar com Google
      </button>
    </form>

    <template #footer>
      <p class="text-sm font-semibold text-slate-500">
        Ainda não tem uma loja?
        <router-link to="/register" class="font-black text-red-600 hover:text-red-700">
          Criar conta grátis
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
