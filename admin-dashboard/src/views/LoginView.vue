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

onMounted(() => {
  if (route.query.notice === 'super_admin') {
    showNotify('Entre com a conta de super admin da plataforma (não use o e-mail da loja).', 'error')
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
    :hero-title="isSuperAdminLogin ? 'Gerencie toda a plataforma.' : 'Assuma o controle da sua operação.'"
    :hero-highlight="isSuperAdminLogin ? 'plataforma' : 'controle'"
    :hero-description="isSuperAdminLogin ? 'Lojas, planos, cortesias e configurações globais do PartiuMenu.' : 'Gerencie pedidos, cardápio, entregas e integrações em um só lugar.'"
    :features="isSuperAdminLogin ? ['Todas as lojas', 'Planos e billing', 'Cortesias'] : ['Pedidos ao vivo', 'Cardápio digital', 'Planos e billing']"
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
