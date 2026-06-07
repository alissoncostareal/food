<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { Check, Zap, Store, Crown, Loader2, ArrowRight, CheckCircle, XCircle } from 'lucide-vue-next'

const router = useRouter()
const loading = ref(null)

// Lógica do Toast (Igual ao Register)
const toast = ref({ show: false, message: '', type: 'success' })

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const plans = [
  {
    id: '1',
    name: 'Starter',
    price: '0',
    description: 'Perfeito para quem está começando agora.',
    icon: Store,
    color: 'text-slate-600',
    features: ['Até 20 produtos', 'Pedidos via WhatsApp', 'Suporte via e-mail', 'Relatórios semanais'],
    buttonText: 'Começar Grátis',
    highlight: false
  },
  {
    id: '2',
    name: 'Pro Performance',
    price: '89',
    description: 'O plano ideal para lojas em crescimento.',
    icon: Zap,
    color: 'text-indigo-600',
    features: ['Produtos ilimitados', 'Robô de Atendimento', 'Gestão de Entregadores', 'Cupons de Desconto', 'Pagamento Online'],
    buttonText: 'Assinar Agora',
    highlight: true
  },
  {
    id: '3',
    name: 'Enterprise / Salão',
    price: '199',
    description: 'Poder total para grandes operações.',
    icon: Crown,
    color: 'text-emerald-600',
    features: ['Tudo do Pro', 'Gestão de Mesas (QR Code)', 'Tela para Cozinha (KDS)', 'Multi-usuários', 'Relatórios de Lucro'],
    buttonText: 'Falar com Consultor',
    highlight: false
  }
]

const handleSubscribe = async (planId) => {
  loading.value = planId
  try {
    await api.post('/merchant/subscribe', { plan_id: planId })
    
    showNotify('Plano ativado com sucesso! Carregando painel...')
    
    // Pequeno delay para o usuário ler a confirmação
    setTimeout(() => {
        router.push('/dashboard') 
    }, 1500)
  } catch (error) {
    console.error("Erro ao assinar plano:", error)
    const msg = error.response?.data?.message || 'Erro ao processar assinatura. Tente novamente.'
    showNotify(msg, 'error')
  } finally {
    loading.value = null
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 py-12 px-4 sm:px-6 lg:px-8 font-sans">
    <div class="max-w-7xl mx-auto">
      
      <div class="text-center mb-16">
        <h2 class="text-indigo-600 font-bold tracking-tight uppercase text-sm mb-3">Planos e Preços</h2>
        <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 mb-4">
          Escolha o plano ideal para sua loja
        </h1>
        <p class="text-slate-500 text-lg max-w-2xl mx-auto">
          Sua conta foi criada! Agora, selecione um plano inspirado no modelo <span class="font-bold text-slate-700 underline decoration-indigo-300">Cardápio Web</span> para ativar seu painel.
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center">
        <div 
          v-for="plan in plans" 
          :key="plan.id"
          :class="[
            plan.highlight ? 'border-indigo-500 ring-4 ring-indigo-500/10 scale-105 z-10' : 'border-slate-200',
            'bg-white rounded-3xl border p-8 shadow-xl transition-all hover:shadow-2xl flex flex-col h-full relative overflow-hidden'
          ]"
        >
          <div v-if="plan.highlight" class="absolute top-0 right-0">
             <div class="bg-indigo-600 text-white text-[10px] font-bold px-4 py-1 uppercase tracking-wider rotate-45 translate-x-4 translate-y-2">Popular</div>
          </div>

          <div class="flex items-center gap-4 mb-6">
            <div :class="plan.highlight ? 'bg-indigo-100' : 'bg-slate-100'" class="p-3 rounded-2xl">
              <component :is="plan.icon" :class="plan.color" size="28" />
            </div>
            <div>
              <h3 class="text-xl font-bold text-slate-900">{{ plan.name }}</h3>
            </div>
          </div>

          <div class="mb-6">
            <div class="flex items-baseline">
              <span class="text-slate-500 text-lg">R$</span>
              <span class="text-5xl font-black text-slate-900 mx-1">{{ plan.price }}</span>
              <span class="text-slate-500">/mês</span>
            </div>
            <p class="text-slate-400 text-sm mt-2 leading-tight">{{ plan.description }}</p>
          </div>

          <ul class="space-y-4 mb-8 flex-grow">
            <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-3">
              <div class="mt-1 bg-emerald-100 rounded-full p-0.5 flex-shrink-0">
                <Check class="text-emerald-600" size="14" />
              </div>
              <span class="text-slate-600 text-sm font-medium">{{ feature }}</span>
            </li>
          </ul>

          <button 
            @click="handleSubscribe(plan.id)"
            :disabled="loading"
            :class="[
              plan.highlight ? 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-200' : 'bg-slate-900 hover:bg-slate-800 text-white',
              'w-full py-4 rounded-2xl font-bold flex items-center justify-center gap-2 transition-all active:scale-95 disabled:opacity-50'
            ]"
          >
            <Loader2 v-if="loading === plan.id" class="animate-spin" size="20" />
            <template v-else>
              <span>{{ plan.buttonText }}</span>
              <ArrowRight size="18" />
            </template>
          </button>
        </div>
      </div>

      <p class="text-center text-slate-400 mt-12 text-sm">
        Dúvidas sobre os planos? <a href="#" class="text-indigo-600 font-bold hover:underline">Fale com nosso suporte.</a>
      </p>
    </div>

    <transition name="toast">
      <div v-if="toast.show" class="fixed bottom-10 right-1/2 translate-x-1/2 md:translate-x-0 md:right-10 z-50 flex items-center p-4 rounded-2xl shadow-2xl bg-slate-900 border border-slate-800 text-white min-w-[300px]">
         <CheckCircle v-if="toast.type === 'success'" class="text-emerald-400 w-6 h-6 mr-3 flex-shrink-0" />
         <XCircle v-else class="text-red-400 w-6 h-6 mr-3 flex-shrink-0" />
         <span class="text-sm font-bold tracking-tight">{{ toast.message }}</span>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.toast-enter-active, .toast-leave-active {
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.toast-enter-from {
  opacity: 0;
  transform: translateY(100px) scale(0.8);
}
.toast-leave-to {
  opacity: 0;
  transform: scale(0.9);
}
</style>