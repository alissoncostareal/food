<script setup>
import { computed } from 'vue'
import { CheckCircle2, Sparkles } from 'lucide-vue-next'

const props = defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  subtitle: { type: String, required: true },
  heroTitle: { type: String, required: true },
  heroHighlight: { type: String, required: true },
  heroDescription: { type: String, required: true },
  features: {
    type: Array,
    default: () => [
      'Pedidos em tempo real',
      'Cardápio e categorias',
      'Relatórios e planos'
    ]
  }
})

const heroParts = computed(() => {
  const full = props.heroTitle
  const highlight = props.heroHighlight

  if (!full.includes(highlight)) {
    return { before: full, highlight: '', after: '' }
  }

  const index = full.indexOf(highlight)

  return {
    before: full.slice(0, index),
    highlight,
    after: full.slice(index + highlight.length)
  }
})
</script>

<template>
  <div class="min-h-screen bg-slate-50 font-sans text-slate-900">
    <div class="grid min-h-screen lg:grid-cols-[1.05fr_1fr]">
      <aside class="relative hidden overflow-hidden bg-slate-950 lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
        <div class="pointer-events-none absolute inset-0">
          <div class="absolute -left-24 top-0 h-80 w-80 rounded-full bg-red-600/30 blur-3xl" />
          <div class="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-orange-500/20 blur-3xl" />
          <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.08),transparent_45%)]" />
        </div>

        <div class="relative z-10">
          <div class="inline-flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-600 text-sm font-black text-white shadow-lg shadow-red-900/40">
              PM
            </div>
            <div>
              <p class="text-sm font-black text-white">PartiuMenu</p>
              <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">Painel do lojista</p>
            </div>
          </div>
        </div>

        <div class="relative z-10 max-w-xl">
          <p class="text-xs font-black uppercase tracking-[0.24em] text-red-300">{{ eyebrow }}</p>
          <h1 class="mt-4 text-5xl font-black leading-[1.05] text-white xl:text-6xl">
            {{ heroParts.before }}<span class="text-transparent bg-clip-text bg-gradient-to-r from-white via-red-100 to-orange-200">{{ heroParts.highlight }}</span>{{ heroParts.after }}
          </h1>
          <p class="mt-6 text-lg font-semibold leading-relaxed text-slate-300">
            {{ heroDescription }}
          </p>

          <ul class="mt-10 space-y-4">
            <li
              v-for="feature in features"
              :key="feature"
              class="flex items-center gap-3 text-sm font-bold text-slate-200"
            >
              <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-white/10 text-red-300">
                <CheckCircle2 size="16" />
              </span>
              {{ feature }}
            </li>
          </ul>
        </div>

        <p class="relative z-10 text-xs font-bold text-slate-500">
          admin.partiumenu.com.br
        </p>
      </aside>

      <main class="flex flex-col justify-center px-5 py-8 sm:px-8 lg:px-12 xl:px-20">
        <div class="mx-auto w-full max-w-md">
          <div class="mb-8 lg:hidden">
            <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
              <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-red-600 text-sm font-black text-white">
                PM
              </div>
              <div>
                <p class="text-base font-black text-slate-900">PartiuMenu</p>
                <p class="text-xs font-bold text-slate-500">Gestão da sua loja online</p>
              </div>
            </div>
          </div>

          <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="mb-8">
              <div class="mb-3 inline-flex items-center gap-2 rounded-full bg-red-50 px-3 py-1 text-[11px] font-black uppercase tracking-widest text-red-700">
                <Sparkles size="12" />
                {{ eyebrow }}
              </div>
              <h2 class="text-3xl font-black tracking-tight text-slate-950">{{ title }}</h2>
              <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">{{ subtitle }}</p>
            </div>

            <slot />
          </div>

          <div v-if="$slots.footer" class="mt-6 text-center">
            <slot name="footer" />
          </div>
        </div>
      </main>
    </div>
  </div>
</template>
