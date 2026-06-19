<script setup>
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Construction } from 'lucide-vue-next'
import { moduleLabels } from '@/constants/moduleMaintenance'
import { refreshModuleMaintenance, useModuleMaintenance } from '@/composables/useModuleMaintenance'

const route = useRoute()
const router = useRouter()
const { isInMaintenance, messageFor } = useModuleMaintenance()

const moduleKey = computed(() => String(route.query.module || ''))
const moduleLabel = computed(() => moduleLabels[moduleKey.value] || 'Módulo')
const maintenanceMessage = computed(() => messageFor(moduleKey.value))

onMounted(async () => {
  await refreshModuleMaintenance({ force: true })

  if (moduleKey.value && !isInMaintenance(moduleKey.value)) {
    router.replace('/dashboard')
  }
})
</script>

<template>
  <div class="min-h-[70vh] flex items-center justify-center p-6">
    <div class="max-w-lg w-full rounded-3xl border border-amber-200 bg-white p-8 text-center shadow-xl shadow-amber-100/60">
      <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-600">
        <Construction :size="30" />
      </div>

      <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-600">Em manutenção</p>
      <h1 class="mt-2 text-2xl font-black text-slate-900">{{ moduleLabel }}</h1>
      <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-500">
        {{ maintenanceMessage }}
      </p>

      <button
        type="button"
        class="mt-8 inline-flex h-12 items-center justify-center rounded-xl bg-slate-900 px-6 text-sm font-black text-white hover:bg-slate-800"
        @click="router.push('/dashboard')"
      >
        Voltar ao painel
      </button>
    </div>
  </div>
</template>
