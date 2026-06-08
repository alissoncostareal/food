<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'
import { 
  TrendingUp, ShoppingBag, UtensilsCrossed, FolderTree,
  Settings, LogOut, Store, Bell, Ticket
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()

const storeData = ref({
  name: '',
  logo_url: null,
  pending_count: 0
})

const menuItems = [
  { name: 'Dashboard', path: '/dashboard', icon: TrendingUp },
  { name: 'Pedidos', path: '/orders', icon: ShoppingBag },
  { name: 'Cardápio', path: '/products', icon: UtensilsCrossed },
  { name: 'Categorias', path: '/categories', icon: FolderTree },
  { name: 'Cupons', path: '/coupons', icon: Ticket },
  { name: 'Configurações', path: '/settings', icon: Settings },
  
]

const fetchStoreHeaderData = async () => {
  try {
    const { data } = await api.get('/merchant/stats')
    storeData.value = {
      name: data.store.name,
      logo_url: data.store.logo_url,
      pending_count: data.store.pending_count,
      is_open: data.store.is_open
    }
  } catch (error) {
    console.error("Erro ao carregar dados do header:", error)
  }
}

const handleLogout = () => {
  localStorage.removeItem('auth_token')
  router.push('/login')
}

onMounted(fetchStoreHeaderData)
</script>

<template>
  <div class="min-h-screen bg-orange-50/30 flex">
    
    <aside class="w-64 bg-slate-950 text-slate-400 flex flex-col fixed h-full shadow-2xl z-30">
      <div class="p-6 flex items-center gap-3">
        <div class="w-10 h-10 bg-red-500 rounded-xl flex items-center justify-center text-white shadow-lg shadow-red-900/20">
          <UtensilsCrossed size="22" />
        </div>
        <span class="text-white font-black text-2xl tracking-tighter">Food<span class="text-red-500">Dash</span></span>
      </div>

      <nav class="flex-1 px-4 space-y-2 mt-4">
        <router-link 
          v-for="item in menuItems" 
          :key="item.path" 
          :to="item.path"
          class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-bold hover:text-white"
          :class="route.path === item.path ? 'bg-red-500 text-white shadow-lg shadow-red-500/40' : 'hover:bg-white/5'"
        >
          <component :is="item.icon" size="20" :class="route.path === item.path ? 'text-white' : 'text-slate-500'" />
          <span>{{ item.name }}</span>
        </router-link>
      </nav>

      <div class="p-4 border-t border-white/5">
        <button @click="handleLogout" class="flex items-center gap-3 px-4 py-3 w-full rounded-xl hover:bg-red-500/10 hover:text-red-500 transition-all font-bold">
          <LogOut size="20" />
          <span>Sair</span>
        </button>
      </div>
    </aside>

    <main class="flex-1 ml-64">
      <header class="h-20 bg-white border-b border-slate-200 px-8 flex items-center justify-between sticky top-0 z-20">
        <h2 class="text-xl font-black text-slate-800 tracking-tight">{{ route.name || 'Painel' }}</h2>
        
        <div class="flex items-center gap-4">
          <button class="p-2 text-slate-400 hover:text-red-500 transition-all relative group">
            <Bell size="22" class="group-hover:scale-110 transition-transform" />
            <span v-if="storeData.pending_count > 0" 
              class="absolute top-1 right-1 w-5 h-5 bg-red-600 text-white text-[10px] font-black rounded-full border-2 border-white flex items-center justify-center animate-bounce">
              {{ storeData.pending_count }}
            </span>
          </button>

          <div class="flex items-center gap-3 pl-4 border-l border-slate-100">
             <div class="text-right hidden md:block">
               <p class="text-sm font-black text-slate-800 leading-none">{{ storeData.name }}</p>
               <p :class="[
                  'text-[10px] font-black uppercase tracking-widest mt-1', 
                  storeData.is_open ? 'text-emerald-500' : 'text-red-600'
                ]">
                {{storeData.is_open ? 'Loja Aberta' : 'Loja Fechada' }}
              </p>
             </div>
             <div class="h-11 w-11 rounded-2xl bg-slate-100 border-2 border-slate-200 overflow-hidden shadow-sm hover:border-red-500 transition-colors cursor-pointer">
                <img v-if="storeData.logo_url" :src="storeData.logo_url" class="w-full h-full object-cover" :alt="storeData.name">
                <div v-else class="w-full h-full flex items-center justify-center text-red-500 uppercase font-black text-sm">
                  {{ storeData.name.charAt(0) }}
                </div>
             </div>
          </div>
        </div>
      </header>

      <div class="p-8">
        <slot />
      </div>
    </main>
  </div>
</template>

<style scoped>
/* Adiciona uma animação suave de fade-in no conteúdo */
main {
  animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(5px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>