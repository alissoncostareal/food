<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { Check, Zap, Store, Crown, Loader2, ArrowRight } from 'lucide-vue-next'

const router = useRouter()
const loading = ref(null) // Armazena qual plano está sendo assinado

const plans = [
  {
    id: 'starter',
    name: 'Starter',
    price: '0',
    description: 'Perfeito para quem está começando agora.',
    icon: Store,
    color: 'text-slate-600',
    features: ['Até 20 produtos', 'Gestão de pedidos básica', 'Suporte via e-mail', 'Relatórios semanais'],
    buttonText: 'Começar Grátis',
    highlight: false
  },
  {
    id: 'pro',
    name: 'Pro Performance',
    price: '89',
    description: 'O plano ideal para lojas em crescimento.',
    icon: Zap,
    color: 'text-indigo-600',
    features: ['Produtos ilimitados', 'Analytics em tempo real', 'Suporte via WhatsApp', 'Taxas reduzidas', 'Dicas de performance AI'],
    buttonText: 'Assinar Agora',
    highlight: true
  },
  {
    id: 'enterprise',
    name: 'Enterprise',
    price: '199',
    description: 'Poder total para grandes operações.',
    icon: Crown,
    color: 'text-emerald-600',
    features: ['Tudo do Pro', 'Gerente de conta dedicado', 'API de integração', 'Customização de layout', 'Prioridade no marketplace'],
    buttonText: 'Falar com Consultor',
    highlight: false
  }
]

const handleSubscribe = async (planId) => {
  loading.value = planId
  try {
    // Rota que você deve ter no Laravel para salvar a assinatura
    await api.post('/store/subscribe', { plan_id: planId })
    
    // Se deu certo, o middleware 403 não vai mais barrar ele
    router.push('/dashboard')
  } catch (error) {
    console.error("Erro ao assinar plano:", error)
    alert("Erro ao processar assinatura. Tente novamente.")
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
          Sua conta foi criada com sucesso! Agora, selecione um plano para ativar seu painel e começar a vender.
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center">
        <div 
          v-for="plan in plans" 
          :key="plan.id"
          :class="[
            plan.highlight ? 'border-indigo-500 ring-4 ring-indigo-500/10 scale-105 z-10' : 'border-slate-200',
            'bg-white rounded-3xl border p-8 shadow-xl transition-all hover:shadow-2xl flex flex-col h-full'
          ]"
        >
          <div class="flex items-center gap-4 mb-6">
            <div :class="plan.highlight ? 'bg-indigo-100' : 'bg-slate-100'" class="p-3 rounded-2xl">
              <component :is="plan.icon" :class="plan.color" size="28" />
            </div>
            <div>
              <h3 class="text-xl font-bold text-slate-900">{{ plan.name }}</h3>
              <span v-if="plan.highlight" class="text-[10px] bg-indigo-600 text-white px-2 py-0.5 rounded-full font-bold uppercase">Mais Popular</span>
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
              <div class="mt-1 bg-emerald-100 rounded-full p-0.5">
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
            <span v-else>{{ plan.buttonText }}</span>
            <ArrowRight v-if="!loading" size="18" />
          </button>
        </div>
      </div>

      <p class="text-center text-slate-400 mt-12 text-sm">
        Dúvidas sobre os planos? <a href="#" class="text-indigo-600 font-bold hover:underline">Fale com nosso suporte.</a>
      </p>
    </div>
  </div>
</template>

<style scoped>
/* Adicione aqui se precisar de algum ajuste específico, 
   mas o Tailwind já cuida de 99% do visual */
</style>