<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { clearCachedUser } from '@/composables/useFeatureAccess'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import {
  Store,
  Link2,
  ArrowRight,
  Loader2,
  CheckCircle,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  name: '',
  slug: ''
})

const menuAppBaseUrl = (import.meta.env.VITE_MENU_APP_URL || 'https://app.partiumenu.com.br').replace(/\/+$/, '')

const previewSlug = computed(() => {
  const raw = form.value.slug.trim() || form.value.name.trim()
  return raw
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
})

const publicMenuUrl = computed(() => `${menuAppBaseUrl}/${previewSlug.value || 'sua-loja'}`)

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => { toast.value.show = false }, 4000)
}

const handleCreate = async () => {
  if (!form.value.name.trim()) {
    showNotify('Informe o nome da loja matriz.', 'error')
    return
  }

  loading.value = true

  try {
    await api.post('/merchant/store/create', {
      name: form.value.name,
      slug: form.value.slug || undefined
    })

    clearCachedUser()
    showNotify('Loja matriz criada!')

    setTimeout(() => router.push('/loja'), 700)
  } catch (err) {
    const message = err.response?.data?.message || 'Erro ao criar loja.'
    showNotify(message, 'error')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthLayout
    eyebrow="Último passo"
    title="Criar loja matriz"
    subtitle="Defina o nome e o link do cardápio. Você configura endereço e aparência depois."
    hero-title="Quase lá."
    hero-highlight="Quase lá"
    hero-description="Sua conta está pronta. Agora crie a loja matriz para começar a receber pedidos."
    :features="['Trial de 7 dias', 'Link próprio do cardápio', 'Configure tudo no painel']"
  >
    <form class="space-y-4" @submit.prevent="handleCreate">
      <div class="space-y-2">
        <label for="store-name" class="text-xs font-black uppercase tracking-widest text-slate-400">
          Nome da loja matriz
        </label>
        <div class="relative">
          <Store class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="store-name"
            v-model="form.name"
            type="text"
            required
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100"
          />
        </div>
      </div>

      <div class="space-y-2">
        <label for="store-slug" class="text-xs font-black uppercase tracking-widest text-slate-400">
          Link do cardápio (opcional)
        </label>
        <div class="relative">
          <Link2 class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            id="store-slug"
            v-model="form.slug"
            type="text"
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-4 focus:ring-red-100 lowercase"
          />
        </div>
        <p class="text-xs font-bold text-slate-500 truncate">{{ publicMenuUrl }}</p>
      </div>

      <button
        :disabled="loading"
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-black text-white shadow-lg shadow-red-100 transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-70 active:scale-[0.99]"
      >
        <Loader2 v-if="loading" class="h-5 w-5 animate-spin" />
        <span v-else>Criar loja e entrar no painel</span>
        <ArrowRight v-if="!loading" class="h-5 w-5" />
      </button>
    </form>
  </AuthLayout>

  <div
    v-if="toast.show"
    class="fixed bottom-5 right-5 z-50 flex max-w-sm items-center gap-3 rounded-2xl border border-slate-800 bg-slate-950 px-5 py-4 text-white shadow-2xl"
  >
    <CheckCircle v-if="toast.type === 'success'" class="h-5 w-5 shrink-0 text-emerald-400" />
    <XCircle v-else class="h-5 w-5 shrink-0 text-red-400" />
    <span class="text-sm font-bold">{{ toast.message }}</span>
  </div>
</template>
