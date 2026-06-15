<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { ChevronDown, Loader2, Plus, Save, Trash2 } from 'lucide-vue-next'

const emit = defineEmits(['notify'])

const loading = ref(true)
const saving = ref(false)
const leads = ref([])
const landingContentOpen = ref(false)

const sectionOpen = reactive({
  hero: false,
  features: false,
  plans: false,
  cta: false,
  leadForm: false,
  footer: false,
})

const form = reactive({
  published: true,
  hero: {
    eyebrow: '',
    title: '',
    highlight: '',
    subtitle: '',
    cta_primary_text: '',
    cta_primary_url: '',
    cta_secondary_text: '',
    cta_secondary_url: '',
  },
  features_section: {
    title: '',
    subtitle: '',
  },
  features: [],
  plans_section: {
    title: '',
    subtitle: '',
    show_plans: true,
  },
  cta_section: {
    title: '',
    subtitle: '',
  },
  lead_form: {
    enabled: true,
    title: '',
    subtitle: '',
    button_text: '',
    success_message: '',
  },
  footer: {
    text: '',
  },
})

const toggleSection = (key) => {
  sectionOpen[key] = !sectionOpen[key]
}

const assignForm = (content) => {
  form.published = Boolean(content.published)
  Object.assign(form.hero, content.hero || {})
  Object.assign(form.features_section, content.features_section || {})
  form.features = (content.features || []).map((item) => ({ ...item }))
  Object.assign(form.plans_section, content.plans_section || {})
  Object.assign(form.cta_section, content.cta_section || {})
  Object.assign(form.lead_form, content.lead_form || {})
  Object.assign(form.footer, content.footer || {})
}

const loadLanding = async () => {
  loading.value = true

  try {
    const [{ data: landingResponse }, { data: leadsResponse }] = await Promise.all([
      api.get('/super-admin/landing'),
      api.get('/super-admin/landing/leads'),
    ])

    assignForm(landingResponse.content || {})
    leads.value = leadsResponse.leads || []
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao carregar landing page.', 'error')
  } finally {
    loading.value = false
  }
}

const saveLanding = async () => {
  saving.value = true

  try {
    const { data } = await api.put('/super-admin/landing', {
      ...form,
      features: form.features.map((item) => ({ ...item })),
    })

    assignForm(data.content || {})
    emit('notify', data.message || 'Landing page salva.')
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao salvar landing page.', 'error')
  } finally {
    saving.value = false
  }
}

const addFeature = () => {
  if (form.features.length >= 12) return

  form.features.push({
    icon: 'sparkles',
    title: 'Novo recurso',
    description: 'Descreva o benefício para o lojista.',
  })
}

const removeFeature = (index) => {
  form.features.splice(index, 1)
}

const formatLeadDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString('pt-BR')
}

onMounted(loadLanding)
</script>

<template>
  <section v-if="loading" class="flex justify-center py-20 text-red-600">
    <Loader2 class="animate-spin" size="32" />
  </section>

  <section v-else class="space-y-5">
    <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
      <h3 class="text-lg font-black text-slate-900">Leads recebidos</h3>
      <p class="mt-1 text-sm font-semibold text-slate-500">Últimos interessados enviados pelo formulário.</p>

      <div v-if="leads.length === 0" class="mt-6 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm font-bold text-slate-400">
        Nenhum lead ainda.
      </div>

      <div v-else class="mt-6 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="text-[10px] font-black uppercase tracking-wider text-slate-400">
            <tr>
              <th class="px-3 py-2">Data</th>
              <th class="px-3 py-2">Nome</th>
              <th class="px-3 py-2">E-mail</th>
              <th class="px-3 py-2">WhatsApp</th>
              <th class="px-3 py-2">Loja</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="lead in leads" :key="lead.id" class="border-t border-slate-100">
              <td class="px-3 py-3 font-bold text-slate-500">{{ formatLeadDate(lead.created_at) }}</td>
              <td class="px-3 py-3 font-black text-slate-900">{{ lead.name }}</td>
              <td class="px-3 py-3 font-bold text-slate-600">{{ lead.email }}</td>
              <td class="px-3 py-3 font-bold text-slate-600">{{ lead.phone || '—' }}</td>
              <td class="px-3 py-3 font-bold text-slate-600">{{ lead.store_name || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <button
        type="button"
        class="flex w-full items-center gap-3 px-6 py-5 text-left transition hover:bg-slate-50"
        @click="landingContentOpen = !landingContentOpen"
      >
        <ChevronDown
          size="20"
          class="shrink-0 text-slate-400 transition-transform duration-200"
          :class="landingContentOpen ? 'rotate-180' : ''"
        />
        <div class="flex-1 min-w-0">
          <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Site institucional</p>
          <h2 class="mt-1 text-xl font-black text-slate-950">Conteúdo da landing page</h2>
          <p class="mt-1 text-sm font-semibold text-slate-500">
            Edite textos, recursos e formulário exibidos em partiumenu.com.br.
          </p>
        </div>
        <span
          class="shrink-0 rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider"
          :class="form.published ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
        >
          {{ form.published ? 'Publicada' : 'Rascunho' }}
        </span>
      </button>

      <form v-show="landingContentOpen" class="space-y-4 border-t border-slate-100 px-6 pb-6 pt-5" @submit.prevent="saveLanding">
        <label class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-black text-slate-700">
          <input v-model="form.published" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
          Landing page publicada
        </label>

        <div class="space-y-3">
          <div class="overflow-hidden rounded-2xl border border-slate-200">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-3 text-left"
              @click="toggleSection('hero')"
            >
              <span class="text-sm font-black text-slate-900">Hero</span>
              <ChevronDown size="18" class="text-slate-400 transition-transform" :class="sectionOpen.hero ? 'rotate-180' : ''" />
            </button>
            <div v-show="sectionOpen.hero" class="space-y-4 border-t border-slate-100 p-4">
              <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Eyebrow</span>
                  <input v-model="form.hero.eyebrow" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Destaque colorido</span>
                  <input v-model="form.hero.highlight" type="text" class="pm-input-sm" />
                </label>
              </div>
              <label class="block space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Título principal</span>
                <input v-model="form.hero.title" type="text" class="pm-input-sm" />
              </label>
              <label class="block space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Subtítulo</span>
                <textarea v-model="form.hero.subtitle" rows="3" class="pm-textarea" />
              </label>
              <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Botão primário</span>
                  <input v-model="form.hero.cta_primary_text" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Link primário</span>
                  <input v-model="form.hero.cta_primary_url" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Botão secundário</span>
                  <input v-model="form.hero.cta_secondary_text" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Link secundário</span>
                  <input v-model="form.hero.cta_secondary_url" type="text" class="pm-input-sm" />
                </label>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-slate-200">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-3 text-left"
              @click="toggleSection('features')"
            >
              <span class="text-sm font-black text-slate-900">Recursos</span>
              <ChevronDown size="18" class="text-slate-400 transition-transform" :class="sectionOpen.features ? 'rotate-180' : ''" />
            </button>
            <div v-show="sectionOpen.features" class="space-y-4 border-t border-slate-100 p-4">
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-bold text-slate-500">{{ form.features.length }} recurso(s)</p>
                <button
                  type="button"
                  class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-50"
                  @click="addFeature"
                >
                  <Plus size="14" />
                  Adicionar
                </button>
              </div>
              <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Título da seção</span>
                  <input v-model="form.features_section.title" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Subtítulo da seção</span>
                  <input v-model="form.features_section.subtitle" type="text" class="pm-input-sm" />
                </label>
              </div>
              <div
                v-for="(feature, index) in form.features"
                :key="`${feature.title}-${index}`"
                class="rounded-2xl border border-slate-100 bg-slate-50 p-4 space-y-3"
              >
                <div class="flex items-center justify-between gap-3">
                  <p class="text-sm font-black text-slate-800">Recurso {{ index + 1 }}</p>
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-black text-red-600 hover:bg-red-50"
                    @click="removeFeature(index)"
                  >
                    <Trash2 size="14" />
                    Remover
                  </button>
                </div>
                <div class="grid gap-3 md:grid-cols-[160px_1fr]">
                  <label class="block space-y-1">
                    <span class="text-[10px] font-black uppercase text-slate-400">Ícone</span>
                    <input v-model="feature.icon" type="text" class="pm-input-sm" placeholder="sparkles" />
                  </label>
                  <label class="block space-y-1">
                    <span class="text-[10px] font-black uppercase text-slate-400">Título</span>
                    <input v-model="feature.title" type="text" class="pm-input-sm" />
                  </label>
                </div>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Descrição</span>
                  <textarea v-model="feature.description" rows="2" class="pm-textarea" />
                </label>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-slate-200">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-3 text-left"
              @click="toggleSection('plans')"
            >
              <span class="text-sm font-black text-slate-900">Planos</span>
              <ChevronDown size="18" class="text-slate-400 transition-transform" :class="sectionOpen.plans ? 'rotate-180' : ''" />
            </button>
            <div v-show="sectionOpen.plans" class="space-y-4 border-t border-slate-100 p-4">
              <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Título planos</span>
                  <input v-model="form.plans_section.title" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Subtítulo planos</span>
                  <input v-model="form.plans_section.subtitle" type="text" class="pm-input-sm" />
                </label>
              </div>
              <label class="inline-flex items-center gap-2 text-sm font-black text-slate-700">
                <input v-model="form.plans_section.show_plans" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                Exibir planos da API
              </label>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-slate-200">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-3 text-left"
              @click="toggleSection('cta')"
            >
              <span class="text-sm font-black text-slate-900">CTA</span>
              <ChevronDown size="18" class="text-slate-400 transition-transform" :class="sectionOpen.cta ? 'rotate-180' : ''" />
            </button>
            <div v-show="sectionOpen.cta" class="space-y-4 border-t border-slate-100 p-4">
              <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Título CTA</span>
                  <input v-model="form.cta_section.title" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Subtítulo CTA</span>
                  <input v-model="form.cta_section.subtitle" type="text" class="pm-input-sm" />
                </label>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-slate-200">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-3 text-left"
              @click="toggleSection('leadForm')"
            >
              <span class="text-sm font-black text-slate-900">Formulário de interesse</span>
              <ChevronDown size="18" class="text-slate-400 transition-transform" :class="sectionOpen.leadForm ? 'rotate-180' : ''" />
            </button>
            <div v-show="sectionOpen.leadForm" class="space-y-4 border-t border-slate-100 p-4">
              <label class="inline-flex items-center gap-2 text-sm font-black text-slate-700">
                <input v-model="form.lead_form.enabled" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                Formulário de interesse ativo
              </label>
              <div class="grid gap-4 md:grid-cols-2">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Título formulário</span>
                  <input v-model="form.lead_form.title" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Subtítulo formulário</span>
                  <input v-model="form.lead_form.subtitle" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Texto do botão</span>
                  <input v-model="form.lead_form.button_text" type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Mensagem de sucesso</span>
                  <input v-model="form.lead_form.success_message" type="text" class="pm-input-sm" />
                </label>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-slate-200">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-3 text-left"
              @click="toggleSection('footer')"
            >
              <span class="text-sm font-black text-slate-900">Rodapé</span>
              <ChevronDown size="18" class="text-slate-400 transition-transform" :class="sectionOpen.footer ? 'rotate-180' : ''" />
            </button>
            <div v-show="sectionOpen.footer" class="space-y-4 border-t border-slate-100 p-4">
              <label class="block space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Texto do rodapé</span>
                <input v-model="form.footer.text" type="text" class="pm-input-sm" />
              </label>
            </div>
          </div>
        </div>

        <button
          type="submit"
          :disabled="saving"
          class="inline-flex items-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-60"
        >
          <Loader2 v-if="saving" class="animate-spin" size="16" />
          <Save v-else size="16" />
          Salvar landing page
        </button>
      </form>
    </article>
  </section>
</template>
