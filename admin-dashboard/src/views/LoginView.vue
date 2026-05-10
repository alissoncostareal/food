<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api' 
import { Mail, Lock, ArrowRight, Loader2, CheckCircle, XCircle } from 'lucide-vue-next'

const router = useRouter()
const loading = ref(false)
const errors = ref(null)

const toast = ref({ show: false, message: '', type: 'success' })

const form = ref({
  email: '',
  password: '',
  remember: false
})

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const handleLogin = async () => {
  loading.value = true
  errors.value = null
  
  try {
    const response = await api.post('/login', form.value)
    
    showNotify('Login realizado com sucesso! Carregando painel...')
    
    // Armazena o token e informações básicas
    localStorage.setItem('auth_token', response.data.token)
    localStorage.setItem('user_name', response.data.user.name)
    
    // Redireciona após o feedback visual
    setTimeout(() => router.push('/dashboard'), 1200)
  } catch (err) {
    if (err.response?.status === 401 || err.response?.status === 422) {
      showNotify('E-mail ou senha incorretos.', 'error')
      errors.value = err.response.data.errors
    } else {
      showNotify('Erro ao conectar com o servidor.', 'error')
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 bg-white font-sans text-slate-900">
    
    <div class="hidden lg:flex flex-col justify-center p-12 bg-slate-950 relative overflow-hidden">
      <div class="absolute top-0 -left-20 w-96 h-96 bg-indigo-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20"></div>
      <div class="absolute bottom-0 -right-20 w-96 h-96 bg-emerald-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20"></div>
      
      <div class="relative z-10">
        <h2 class="text-indigo-400 font-bold tracking-widest text-sm mb-4 uppercase">Bem-vindo de volta</h2>
        <h1 class="text-6xl font-extrabold text-white leading-tight mb-6">
          Assuma o <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-emerald-400">controle</span> da sua operação.
        </h1>
        <p class="text-slate-400 text-xl max-w-md leading-relaxed">
          Acesse seu painel administrativo para gerenciar pedidos, produtos e clientes em tempo real.
        </p>
      </div>
    </div>

    <div class="flex flex-col justify-center px-8 sm:px-16 lg:px-24 py-12">
      <div class="max-w-md w-full mx-auto">
        <div class="mb-10">
          <h2 class="text-3xl font-bold text-slate-950 mb-2">Entrar no Painel</h2>
          <p class="text-slate-500">Digite suas credenciais para acessar sua loja.</p>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-6">
          <div class="space-y-1">
            <label class="text-sm font-semibold text-slate-700">E-mail</label>
            <div class="relative">
              <Mail class="absolute left-3 top-3 text-slate-400 w-5 h-5" />
              <input v-model="form.email" type="email" placeholder="seu@email.com" required
                class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none transition-all" />
            </div>
            <p v-if="errors?.email" class="text-xs text-red-500">{{ errors.email[0] }}</p>
          </div>

          <div class="space-y-1">
            <div class="flex justify-between items-center">
              <label class="text-sm font-semibold text-slate-700">Senha</label>
              <a href="#" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">Esqueceu a senha?</a>
            </div>
            <div class="relative">
              <Lock class="absolute left-3 top-3 text-slate-400 w-5 h-5" />
              <input v-model="form.password" type="password" placeholder="••••••••" required
                class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none transition-all" />
            </div>
          </div>

          <div class="flex items-center">
            <input id="remember" v-model="form.remember" type="checkbox" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-slate-300 rounded cursor-pointer">
            <label for="remember" class="ml-2 block text-sm text-slate-600 cursor-pointer select-none">
              Lembrar deste dispositivo
            </label>
          </div>

          <button :disabled="loading" type="submit"
            class="w-full bg-slate-950 text-white font-bold py-3.5 rounded-xl hover:bg-indigo-600 transition-all flex items-center justify-center gap-2 mt-4 disabled:opacity-70">
            <Loader2 v-if="loading" class="w-5 h-5 animate-spin" />
            <span v-else>Acessar Painel</span>
            <ArrowRight v-if="!loading" class="w-5 h-5" />
          </button>
        </form>

        <div class="mt-8 text-center">
          <p class="text-slate-600 text-sm">
            Ainda não tem uma loja? 
            <router-link to="/register" class="text-indigo-600 font-bold hover:underline">Crie sua conta aqui</router-link>
          </p>
        </div>
      </div>
    </div>

    <div v-if="toast.show" 
      class="fixed bottom-5 right-5 z-50 flex items-center p-4 rounded-xl shadow-xl bg-slate-900 border border-slate-800 text-white animate-in fade-in slide-in-from-bottom-5">
       <CheckCircle v-if="toast.type === 'success'" class="text-emerald-400 w-5 h-5 mr-3" />
       <XCircle v-else class="text-red-400 w-5 h-5 mr-3" />
       <span class="text-sm font-medium">{{ toast.message }}</span>
    </div>

  </div>
</template>

<style scoped>
/* Pequena animação para o Toast */
.animate-in {
  animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
</style>