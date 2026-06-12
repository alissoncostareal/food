<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { clearCachedUser } from '@/composables/useFeatureAccess'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import {
  ArrowRight,
  CheckCircle,
  Loader2,
  Lock,
  Store,
  XCircle
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const submitting = ref(false)
const invitation = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  name: '',
  password: '',
  password_confirmation: ''
})

const token = computed(() => route.params.token)
const requiresRegistration = computed(() => Boolean(invitation.value?.requires_registration))

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const loadInvitation = async () => {
  loading.value = true

  try {
    const { data } = await api.get(`/team/invitations/${token.value}`)
    invitation.value = data
  } catch (error) {
    invitation.value = null
    showNotify(error.response?.data?.message || 'Convite inválido ou expirado.', 'error')
  } finally {
    loading.value = false
  }
}

const acceptInvite = async () => {
  if (submitting.value) return

  if (requiresRegistration.value && form.value.password !== form.value.password_confirmation) {
    showNotify('As senhas não coincidem.', 'error')
    return
  }

  submitting.value = true

  try {
    const payload = requiresRegistration.value
      ? {
          name: form.value.name,
          password: form.value.password
        }
      : {}

    const { data } = await api.post(`/team/invitations/${token.value}/accept`, payload)

    clearCachedUser()
    localStorage.setItem('auth_token', data.data.token)
    localStorage.setItem('user_name', data.data.user.name)
    localStorage.setItem('user_role', data.data.user.role)

    showNotify('Acesso liberado! Redirecionando...')
    setTimeout(() => router.push('/dashboard'), 800)
  } catch (error) {
    showNotify(error.response?.data?.message || 'Não foi possível aceitar o convite.', 'error')
  } finally {
    submitting.value = false
  }
}

onMounted(loadInvitation)
</script>

<template>
  <AuthLayout
    eyebrow="Convite da equipe"
    title="Aceitar acesso"
    subtitle="Entre na operação da loja com seu próprio login."
    hero-title="Sua equipe no painel."
    hero-highlight="equipe"
    hero-description="Gerencie pedidos e operação com acesso separado do dono da loja."
    :features="['Acesso individual', 'Operação no restaurante', 'Login seguro']"
  >
    <div v-if="loading" class="flex justify-center py-16 text-red-500">
      <Loader2 class="animate-spin" size="40" />
    </div>

    <div v-else-if="!invitation" class="rounded-2xl border border-red-100 bg-red-50 p-6 text-center">
      <p class="font-black text-red-700">Convite indisponível</p>
      <p class="mt-2 text-sm font-semibold text-red-600">Peça um novo link ao dono da loja.</p>
    </div>

    <div v-else class="space-y-5">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex items-start gap-3">
          <div class="rounded-xl bg-red-100 p-2 text-red-600">
            <Store size="18" />
          </div>
          <div>
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Loja</p>
            <p class="font-black text-slate-900">{{ invitation.store.name }}</p>
            <p class="mt-1 text-xs font-bold text-slate-500">{{ invitation.email }}</p>
          </div>
        </div>
      </div>

      <form class="space-y-4" @submit.prevent="acceptInvite">
        <template v-if="requiresRegistration">
          <label class="block space-y-1">
            <span class="text-xs font-black uppercase tracking-widest text-slate-400">Seu nome</span>
            <input v-model="form.name" required type="text" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 px-4 text-sm font-bold" />
          </label>

          <label class="block space-y-1">
            <span class="text-xs font-black uppercase tracking-widest text-slate-400">Crie sua senha</span>
            <input v-model="form.password" required minlength="8" type="password" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 px-4 text-sm font-bold" />
          </label>

          <label class="block space-y-1">
            <span class="text-xs font-black uppercase tracking-widest text-slate-400">Confirmar senha</span>
            <input v-model="form.password_confirmation" required minlength="8" type="password" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 px-4 text-sm font-bold" />
          </label>
        </template>

        <template v-else>
          <div class="rounded-2xl border border-dashed border-slate-200 p-4 text-sm font-semibold text-slate-500">
            Este e-mail já possui conta. Ao continuar, o acesso à loja será vinculado ao seu login atual.
          </div>
        </template>

        <button
          type="submit"
          :disabled="submitting"
          class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-black text-white hover:bg-red-700 disabled:opacity-60"
        >
          <Loader2 v-if="submitting" class="animate-spin" size="18" />
          <span v-else>Aceitar convite</span>
          <ArrowRight v-if="!submitting" size="18" />
        </button>
      </form>
    </div>
  </AuthLayout>

  <div v-if="toast.show" class="fixed bottom-5 right-5 z-50 flex items-center gap-3 rounded-2xl bg-slate-950 px-5 py-4 text-white shadow-2xl">
    <CheckCircle v-if="toast.type === 'success'" class="text-emerald-400" size="18" />
    <XCircle v-else class="text-red-400" size="18" />
    <span class="text-sm font-bold">{{ toast.message }}</span>
  </div>
</template>
