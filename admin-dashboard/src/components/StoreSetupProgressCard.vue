<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CheckCircle2,
  Circle,
  ChevronDown,
  ChevronUp,
  Lock,
  Sparkles,
  ArrowRight
} from 'lucide-vue-next'

const props = defineProps({
  progress: { type: Object, default: null },
  loading: { type: Boolean, default: false }
})

const emit = defineEmits(['go-section'])

const STORAGE_KEY = 'partiumenu:store-setup-expanded'

const readExpandedPreference = () => {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === '0') return false
    if (stored === '1') return true
  } catch {
    // ignore
  }

  return true
}

const router = useRouter()
const expanded = ref(readExpandedPreference())

const toggleExpanded = () => {
  expanded.value = !expanded.value

  try {
    localStorage.setItem(STORAGE_KEY, expanded.value ? '1' : '0')
  } catch {
    // ignore
  }
}

const core = computed(() => props.progress?.core || null)
const planFeatures = computed(() => props.progress?.plan_features || null)
const upsell = computed(() => props.progress?.upsell || null)

const showPlanFeatures = computed(() => (planFeatures.value?.total || 0) > 0)

const pendingCoreItems = computed(() =>
  (core.value?.items || []).filter(item => !item.done)
)

const planBadgeClass = (plan) => {
  if (plan === 'premium') {
    return 'bg-red-500/10 text-red-600 ring-red-100'
  }

  return 'bg-amber-500/10 text-amber-700 ring-amber-100'
}

const SETUP_TARGETS = {
  name_slug: { section: 'identidade', anchor: 'setup-name' },
  description: { section: 'identidade', anchor: 'setup-description' },
  address: { section: 'operacao', anchor: 'setup-address' },
  logo: { section: 'visual', anchor: 'setup-logo' },
  banner: { section: 'visual', anchor: 'setup-banner' },
  products: { route: '/products' },
  hours: { section: 'operacao', anchor: 'setup-hours' },
  payments: { section: 'operacao', anchor: 'setup-payments' },
  open: { anchor: 'setup-store-status' },
  online_pix: { route: '/payments' },
  delivery_areas: { route: '/delivery-areas' },
  whatsapp: { route: '/integrations/whatsapp' },
  ifood: { route: '/integrations/ifood' },
  team: { route: '/team' },
  branches: { section: 'filiais', anchor: 'setup-branches' }
}

const resolveItemTarget = (item) => {
  const fallback = SETUP_TARGETS[item.key] || {}

  return {
    route: item.route || fallback.route || null,
    section: item.section || fallback.section || null,
    anchor: item.anchor || fallback.anchor || null
  }
}

const isItemNavigable = (item) => {
  const target = resolveItemTarget(item)
  return Boolean(target.route || target.section || target.anchor)
}

const handleItemClick = (item) => {
  const target = resolveItemTarget(item)

  if (target.route) {
    router.push(target.route)
    return
  }

  if (target.section || target.anchor) {
    emit('go-section', { section: target.section, anchor: target.anchor })
  }
}

const handleUpsellClick = (item) => {
  if (item.done) return

  if (item.route) {
    router.push(item.route)
    return
  }

  router.push('/billing')
}

const goToBilling = () => {
  router.push('/billing')
}
</script>

<template>
  <div v-if="loading" class="pm-card p-5 animate-pulse">
    <div class="h-4 w-40 rounded-full bg-gray-100" />
    <div class="mt-4 h-2 w-full rounded-full bg-gray-100" />
    <div class="mt-3 h-3 w-56 rounded-full bg-gray-100" />
  </div>

  <div
    v-else-if="progress"
    class="pm-card overflow-hidden"
  >
    <button
      type="button"
      class="w-full px-5 py-4 flex items-start justify-between gap-4 text-left hover:bg-gray-50/80 transition-colors"
      :aria-expanded="expanded"
      @click="toggleExpanded"
    >
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 flex-wrap">
          <Sparkles size="16" class="text-red-500 shrink-0" />
          <p class="text-sm font-black text-gray-900">
            {{ core?.complete ? 'Loja configurada' : 'Complete sua loja' }}
          </p>
          <span
            v-if="core?.complete"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-100"
          >
            <CheckCircle2 size="11" />
            Pronta
          </span>
        </div>

        <p class="mt-1 text-xs font-bold text-gray-400">
          <template v-if="core?.complete && upsell">
            Tudo certo na loja. Veja o que desbloqueia no {{ upsell.target_label }}.
          </template>
          <template v-else-if="pendingCoreItems.length">
            Falta: {{ pendingCoreItems.slice(0, 3).map(i => i.label.toLowerCase()).join(' · ') }}
          </template>
          <template v-else>
            {{ core?.completed }}/{{ core?.total }} itens da loja
          </template>
        </p>
      </div>

      <component
        :is="expanded ? ChevronUp : ChevronDown"
        size="18"
        class="text-gray-400 shrink-0 mt-0.5"
      />
    </button>

    <div v-show="expanded" class="px-5 pb-5 space-y-5 border-t border-gray-100 pt-4">
      <div>
        <div class="flex items-center justify-between gap-3 mb-2">
          <p class="text-[11px] font-black uppercase tracking-widest text-gray-400">
            {{ core?.label }}
          </p>
          <span class="text-xs font-black text-gray-700">
            {{ core?.completed }}/{{ core?.total }}
          </span>
        </div>

        <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
          <div
            class="h-full rounded-full transition-all duration-500"
            :class="core?.complete ? 'bg-emerald-500' : 'bg-red-500'"
            :style="{ width: `${core?.percent || 0}%` }"
          />
        </div>

        <ul class="mt-3 space-y-1.5">
          <li
            v-for="item in core?.items || []"
            :key="item.key"
          >
            <button
              type="button"
              class="w-full flex items-center gap-2.5 rounded-xl px-2 py-2 text-left transition-colors"
              :class="[
                item.done ? 'opacity-80' : 'hover:bg-red-50',
                isItemNavigable(item) ? 'cursor-pointer' : 'cursor-default'
              ]"
              @click="handleItemClick(item)"
            >
              <CheckCircle2
                v-if="item.done"
                size="16"
                class="text-emerald-500 shrink-0"
              />
              <Circle
                v-else
                size="16"
                class="text-red-400 shrink-0"
              />
              <span
                class="text-xs font-bold flex-1"
                :class="item.done ? 'text-gray-600' : 'text-gray-800'"
              >
                {{ item.label }}
              </span>
              <ArrowRight
                v-if="isItemNavigable(item)"
                size="14"
                class="text-gray-300 shrink-0"
              />
            </button>
          </li>
        </ul>
      </div>

      <div v-if="showPlanFeatures">
        <div class="flex items-center justify-between gap-3 mb-2">
          <p class="text-[11px] font-black uppercase tracking-widest text-gray-400">
            {{ planFeatures?.label }}
          </p>
          <span class="text-xs font-black text-gray-700">
            {{ planFeatures?.completed }}/{{ planFeatures?.total }}
          </span>
        </div>

        <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
          <div
            class="h-full rounded-full bg-red-500 transition-all duration-500"
            :style="{ width: `${planFeatures?.percent || 0}%` }"
          />
        </div>

        <ul class="mt-3 space-y-1.5">
          <li
            v-for="item in planFeatures?.items || []"
            :key="item.key"
          >
            <button
              type="button"
              class="w-full flex items-center gap-2.5 rounded-xl px-2 py-2 text-left transition-colors"
              :class="[
                item.done ? 'opacity-80' : 'hover:bg-red-50',
                isItemNavigable(item) ? 'cursor-pointer' : 'cursor-default'
              ]"
              @click="handleItemClick(item)"
            >
              <CheckCircle2
                v-if="item.done"
                size="16"
                class="text-emerald-500 shrink-0"
              />
              <Circle
                v-else
                size="16"
                class="text-red-400 shrink-0"
              />
              <span
                class="text-xs font-bold flex-1"
                :class="item.done ? 'text-gray-600' : 'text-gray-800'"
              >
                {{ item.label }}
              </span>
              <ArrowRight
                v-if="isItemNavigable(item)"
                size="14"
                class="text-gray-300 shrink-0"
              />
            </button>
          </li>
        </ul>
      </div>

      <div
        v-if="upsell"
        class="rounded-2xl border border-dashed border-gray-200 bg-gray-50/80 p-4"
      >
        <div class="flex items-center justify-between gap-3">
          <p class="text-xs font-black text-gray-800">
            No {{ upsell.target_label }}
          </p>
          <span
            class="rounded-md px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider ring-1"
            :class="planBadgeClass(upsell.target_plan)"
          >
            {{ upsell.target_label }}
          </span>
        </div>

        <p class="mt-1 text-[11px] font-bold text-gray-500">
          {{ upsell.completed }}/{{ upsell.total }} configurados no plano superior
        </p>

        <ul class="mt-3 space-y-1.5">
          <li
            v-for="item in upsell.items"
            :key="item.key"
          >
            <button
              type="button"
              class="w-full flex items-center gap-2.5 rounded-xl px-2 py-2 text-left transition-colors hover:bg-white"
              @click="handleUpsellClick(item)"
            >
              <CheckCircle2
                v-if="item.done"
                size="16"
                class="text-emerald-500 shrink-0"
              />
              <Lock
                v-else
                size="15"
                class="text-gray-400 shrink-0"
              />
              <span
                class="text-xs font-bold flex-1"
                :class="item.done ? 'text-gray-500 line-through' : 'text-gray-700'"
              >
                {{ item.label }}
              </span>
              <span
                v-if="!item.done"
                class="rounded-md px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider ring-1"
                :class="planBadgeClass(item.required_plan)"
              >
                {{ item.required_plan === 'premium' ? 'Premium' : 'Pro' }}
              </span>
            </button>
          </li>
        </ul>

        <button
          type="button"
          class="mt-4 inline-flex items-center gap-2 text-xs font-black text-red-600 hover:text-red-700 transition-colors"
          @click="goToBilling"
        >
          Ver planos
          <ArrowRight size="14" />
        </button>
      </div>
    </div>
  </div>
</template>
