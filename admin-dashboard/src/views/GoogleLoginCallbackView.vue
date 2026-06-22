<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { clearCachedUser } from '@/composables/useFeatureAccess'
import { syncUserSession } from '@/utils/authSession'
import { getApiErrorMessage } from '@/utils/apiError'
import { isPlatformAdmin } from '@/utils/platformAdmin'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import { Loader2 } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const message = ref('Conectando com o Google...')

const errorMessages = {
  google_failed: 'Não foi possível validar sua conta Google. Tente novamente.',
  google_not_configured: 'Login com Google não está configurado no servidor.',
  google_profile_incomplete: 'O Google não retornou e-mail válido para login.',
  google_account_not_found: 'Nenhuma conta do painel encontrada com este e-mail. Crie sua loja primeiro.',
  google_not_allowed: 'Esta conta não tem acesso ao painel.',
  google_email_mismatch: 'O e-mail do Google não corresponde à conta cadastrada.',
}

onMounted(async () => {
  const error = typeof route.query.error === 'string' ? route.query.error : null

  if (error) {
    message.value = errorMessages[error] || 'Não foi possível entrar com o Google.'
    setTimeout(() => router.replace({ path: '/login', query: { notice: 'google_error' } }), 2200)
    return
  }

  const code = typeof route.query.code === 'string' ? route.query.code : ''

  if (!code) {
    message.value = 'Código de login ausente. Tente novamente.'
    setTimeout(() => router.replace('/login'), 2200)
    return
  }

  try {
    const { data } = await api.post('/auth/google/exchange', { code })

    clearCachedUser()
    localStorage.setItem('auth_token', data.access_token)
    syncUserSession(data.user)
    window.PartiuMenuEcho?.initialize?.({ force: true })

    const user = data.user
    let targetRoute = '/dashboard'

    if (isPlatformAdmin(user)) {
      targetRoute = '/super-admin/overview'
    } else if (user.needs_onboarding) {
      targetRoute = '/onboarding/loja'
    }

    message.value = 'Login realizado com sucesso! Redirecionando...'
    setTimeout(() => router.replace(targetRoute), 500)
  } catch (err) {
    message.value = getApiErrorMessage(err, 'Não foi possível concluir o login com Google.')
    setTimeout(() => router.replace('/login'), 2400)
  }
})
</script>

<template>
  <AuthLayout
    eyebrow="Google"
    title="Entrando no painel"
    subtitle="Aguarde enquanto validamos sua conta Google."
    hero-title="Quase lá."
    hero-highlight="lá"
    hero-description="Estamos finalizando seu acesso seguro ao PartiuMenu."
    :features="['Conta verificada', 'Acesso ao painel', 'Sem nova senha']"
  >
    <div class="flex flex-col items-center justify-center gap-4 py-16 text-center">
      <Loader2 class="h-10 w-10 animate-spin text-red-600" />
      <p class="max-w-sm text-sm font-semibold leading-relaxed text-slate-600">{{ message }}</p>
    </div>
  </AuthLayout>
</template>
