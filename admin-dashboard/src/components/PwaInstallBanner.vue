<script setup>
import { computed } from 'vue'
import { Download, X, MonitorSmartphone } from 'lucide-vue-next'
import { usePwaInstall } from '@/composables/usePwaInstall'

const { canInstall, isInstalled, isIosSafari, install, dismiss } = usePwaInstall()

const showBanner = computed(() => !isInstalled.value && (canInstall.value || isIosSafari.value))

const handleInstall = async () => {
  const ok = await install()
  if (!ok && !canInstall.value) {
    dismiss()
  }
}
</script>

<template>
  <div
    v-if="showBanner"
    class="mx-4 mb-3 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-900 px-4 py-3 text-white shadow-lg sm:mx-6 sm:flex-row sm:items-center sm:justify-between"
  >
    <div class="flex items-start gap-3">
      <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10">
        <MonitorSmartphone v-if="isIosSafari" size="20" />
        <Download v-else size="20" />
      </div>

      <div>
        <p class="text-sm font-black tracking-tight">
          Instalar PartiuMenu no computador
        </p>
        <p class="mt-0.5 text-xs font-medium text-slate-300">
          <template v-if="isIosSafari">
            No Safari: Compartilhar → Adicionar à Tela de Início.
          </template>
          <template v-else>
            Abra como app, com ícone na barra de tarefas e acesso rápido aos pedidos.
          </template>
        </p>
      </div>
    </div>

    <div class="flex items-center gap-2 sm:shrink-0">
      <button
        v-if="canInstall"
        type="button"
        class="rounded-xl bg-red-600 px-4 py-2 text-xs font-black uppercase tracking-wider transition hover:bg-red-500"
        @click="handleInstall"
      >
        Instalar
      </button>

      <button
        type="button"
        class="rounded-xl p-2 text-slate-400 transition hover:bg-white/10 hover:text-white"
        aria-label="Fechar aviso de instalação"
        @click="dismiss"
      >
        <X size="16" />
      </button>
    </div>
  </div>
</template>
