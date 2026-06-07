<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api' 
import { User, Mail, Lock, Store, ArrowRight, Loader2, CheckCircle, XCircle, X } from 'lucide-vue-next'

const router = useRouter()
const loading = ref(false)
const errors = ref(null)

const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '', 
  store_name: '',
  address: 'Endereço a completar',
})

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const handleRegister = async () => {
  loading.value = true
  errors.value = null
  
  try {
    const response = await api.post('/register/merchant', form.value)
    
    showNotify('Loja criada com sucesso! Entrando no painel...')
    
    localStorage.setItem('auth_token', response.data.data.token)
    localStorage.setItem('user_role', 'store_owner')
    
    setTimeout(() => router.push('/dashboard'), 1500)
  } catch (err) {
    if (err.response?.status === 422) {
      errors.value = err.response.data.errors
      showNotify('Verifique os dados da loja.', 'error')
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
  <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 bg-white font-sans text-slate-900">
    
    <div class="hidden lg:flex flex-col justify-center p-12 bg-red-700 relative overflow-hidden">
      <div class="absolute top-0 -left-20 w-96 h-96 bg-red-500 rounded-full mix-blend-multiply filter blur-3xl opacity-30"></div>
      <div class="absolute bottom-0 -right-20 w-96 h-96 bg-orange-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20"></div>
      
      <div class="relative z-10">
        <h2 class="text-red-200 font-bold tracking-widest text-sm mb-4 uppercase">Merchant Portal</h2>
        <h1 class="text-6xl font-extrabold text-white leading-tight mb-6">
          Sua loja <span class="text-transparent bg-clip-text bg-gradient-to-r from-white to-red-200">em outro nível.</span>
        </h1>
        <p class="text-red-100 text-xl max-w-md leading-relaxed">
          O marketplace completo para gerenciar suas vendas e entregas com performance de elite.
        </p>
      </div>
    </div>

    <div class="flex flex-col justify-center px-8 sm:px-16 lg:px-24 py-12">
      <div class="max-w-md w-full mx-auto">
        <div class="mb-10">
          <h2 class="text-3xl font-bold text-slate-950 mb-2">Começar como Lojista</h2>
          <p class="text-slate-500">Crie sua conta e configure sua loja agora.</p>
        </div>

        <form @submit.prevent="handleRegister" class="space-y-4">
          <div class="space-y-1">
            <label class="text-sm font-semibold text-slate-700">Nome da sua Loja</label>
            <div class="relative">
              <Store class="absolute left-3 top-3 text-slate-400 w-5 h-5" />
              <input v-model="form.store_name" type="text" placeholder="Ex: Burger King" 
                class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-red-600 outline-none transition-all" />
            </div>
            <p v-if="errors?.store_name" class="text-xs text-red-500">{{ errors.store_name[0] }}</p>
          </div>

          <div class="space-y-1">
            <label class="text-sm font-semibold text-slate-700">Seu Nome</label>
            <div class="relative">
              <User class="absolute left-3 top-3 text-slate-400 w-5 h-5" />
              <input v-model="form.name" type="text" placeholder="Nome do proprietário" 
                class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-red-600 outline-none transition-all" />
            </div>
          </div>

          <div class="space-y-1">
            <label class="text-sm font-semibold text-slate-700">E-mail Profissional</label>
            <div class="relative">
              <Mail class="absolute left-3 top-3 text-slate-400 w-5 h-5" />
              <input v-model="form.email" type="email" placeholder="loja@exemplo.com" 
                class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-red-600 outline-none transition-all" />
            </div>
            <p v-if="errors?.email" class="text-xs text-red-500">{{ errors.email[0] }}</p>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div class="space-y-1">
              <label class="text-sm font-semibold text-slate-700">Senha</label>
              <input v-model="form.password" type="password" placeholder="••••••••" 
                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-red-600 outline-none transition-all" />
            </div>
            <div class="space-y-1">
              <label class="text-sm font-semibold text-slate-700">Confirmar</label>
              <input v-model="form.password_confirmation" type="password" placeholder="••••••••" 
                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-red-600 outline-none transition-all" />
            </div>
          </div>
          <p v-if="errors?.password" class="text-xs text-red-500">{{ errors.password[0] }}</p>

          <button :disabled="loading" type="submit"
            class="w-full bg-red-600 text-white font-bold py-3.5 rounded-xl hover:bg-red-700 transition-all flex items-center justify-center gap-2 mt-4 disabled:opacity-70 shadow-lg shadow-red-100 active:scale-95">
            <Loader2 v-if="loading" class="w-5 h-5 animate-spin" />
            <span v-else>Criar Minha Loja</span>
            <ArrowRight v-if="!loading" class="w-5 h-5" />
          </button>
          
          <div class="mt-6 text-center">
            <p class="text-sm text-slate-500">
              Já possui uma conta? 
              <button @click="router.push('/login')" type="button" class="text-red-600 font-bold hover:underline">
                Fazer Login
              </button>
            </p>
          </div>
        </form>
      </div>
    </div>

    <div v-if="toast.show" 
      class="fixed bottom-5 right-5 z-50 flex items-center p-4 rounded-xl shadow-xl bg-slate-900 border border-slate-800 text-white animate-in">
       <CheckCircle v-if="toast.type === 'success'" class="text-emerald-400 w-5 h-5 mr-3" />
       <XCircle v-else class="text-red-500 w-5 h-5 mr-3" />
       <span class="text-sm font-medium">{{ toast.message }}</span>
    </div>

  </div>
</template>

<style scoped>
.animate-in {
  animation: slideIn 0.3s ease-out forwards;
}

@keyframes slideIn {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
</style>