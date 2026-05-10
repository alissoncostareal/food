<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { 
  LayoutDashboard, ShoppingBag, UtensilsCrossed, 
  Settings, LogOut, Store, Bell 
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()

const menuItems = [
  { name: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
  { name: 'Pedidos', path: '/orders', icon: ShoppingBag },
  { name: 'Cardápio', path: '/products', icon: UtensilsCrossed },
  { name: 'Configurações', path: '/settings', icon: Settings },
]

const handleLogout = () => {
  localStorage.removeItem('auth_token')
  router.push('/login')
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex">
    <aside class="w-64 bg-slate-950 text-slate-400 flex flex-col fixed h-full">
      <div class="p-6 flex items-center gap-3">
        <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center text-white">
          <Store size="20" />
        </div>
        <span class="text-white font-bold text-xl tracking-tight">FoodDash</span>
      </div>

      <nav class="flex-1 px-4 space-y-2 mt-4">
        <router-link 
          v-for="item in menuItems" 
          :key="item.path" 
          :to="item.path"
          class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all hover:text-white"
          :class="route.path === item.path ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' : 'hover:bg-slate-900'"
        >
          <component :is="item.icon" size="20" />
          <span class="font-medium">{{ item.name }}</span>
        </router-link>
      </nav>

      <div class="p-4 border-t border-slate-900">
        <button @click="handleLogout" class="flex items-center gap-3 px-4 py-3 w-full rounded-xl hover:bg-red-500/10 hover:text-red-500 transition-all">
          <LogOut size="20" />
          <span class="font-medium">Sair</span>
        </button>
      </div>
    </aside>

    <main class="flex-1 ml-64">
      <header class="h-20 bg-white border-b border-slate-200 px-8 flex items-center justify-between sticky top-0 z-20">
        <h2 class="text-xl font-bold text-slate-800 capitalize">{{ route.name || 'Painel' }}</h2>
        
        <div class="flex items-center gap-4">
          <button class="p-2 text-slate-400 hover:text-indigo-600 transition-colors relative">
            <Bell size="22" />
            <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></span>
          </button>
          <div class="h-10 w-10 rounded-full bg-slate-200 border border-slate-300"></div>
        </div>
      </header>

      <div class="p-8">
        <slot />
      </div>
    </main>
  </div>
</template>