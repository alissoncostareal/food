<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FeatureAccessLoading from '@/components/auth/FeatureAccessLoading.vue'
import { useFeatureAccess } from '@/composables/useFeatureAccess'
import {
  ArrowUpRight,
  CheckCircle,
  Download,
  FileSpreadsheet,
  Loader2,
  Lock,
  ShieldCheck,
  Upload,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const toast = ref({ show: false, message: '', type: 'success' })
const { isLoading, isLocked, isUnlocked } = useFeatureAccess('ifood_integration')

const selectedFile = ref(null)
const fileInput = ref(null)
const isDragging = ref(false)
const previewing = ref(false)
const importing = ref(false)
const downloadingSample = ref(false)
const updateExisting = ref(true)
const preview = ref(null)
const importStats = ref(null)

const hasFile = computed(() => Boolean(selectedFile.value))

const statLabels = {
  categories_created: 'Categorias criadas',
  categories_updated: 'Categorias atualizadas',
  products_created: 'Produtos criados',
  products_updated: 'Produtos atualizados',
  products_skipped: 'Produtos ignorados',
  option_groups_synced: 'Grupos de opções',
  option_items_synced: 'Itens de opção',
  product_images_imported: 'Fotos de produtos',
  option_images_imported: 'Fotos de opções'
}

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

const resetResults = () => {
  preview.value = null
  importStats.value = null
}

const setFile = (file) => {
  if (!file) return

  const name = file.name.toLowerCase()

  if (!name.endsWith('.xml')) {
    showNotify('Selecione um arquivo .xml válido.', 'error')
    return
  }

  selectedFile.value = file
  resetResults()
}

const onFileChange = (event) => {
  setFile(event.target.files?.[0] || null)
}

const onDrop = (event) => {
  isDragging.value = false
  setFile(event.dataTransfer.files?.[0] || null)
}

const openFilePicker = () => {
  fileInput.value?.click()
}

const clearFile = () => {
  selectedFile.value = null
  resetResults()

  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const buildFormData = () => {
  const formData = new FormData()
  formData.append('file', selectedFile.value)
  formData.append('update_existing', updateExisting.value ? '1' : '0')

  return formData
}

const downloadSample = async () => {
  try {
    downloadingSample.value = true
    const { data } = await api.get('/merchant/import/catalog/sample', { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([data], { type: 'application/xml' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'partiumenu-catalog-exemplo.xml'
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Erro ao baixar exemplo XML:', error)
    showNotify('Não foi possível baixar o arquivo de exemplo.', 'error')
  } finally {
    downloadingSample.value = false
  }
}

const runPreview = async () => {
  if (!selectedFile.value) {
    showNotify('Selecione um arquivo XML primeiro.', 'error')
    return
  }

  try {
    previewing.value = true
    importStats.value = null
    const { data } = await api.post('/merchant/import/catalog/preview', buildFormData())
    preview.value = data.preview
  } catch (error) {
    console.error('Erro ao pré-visualizar XML:', error)
    showNotify(error.response?.data?.details || error.response?.data?.message || 'Erro ao ler o XML.', 'error')
  } finally {
    previewing.value = false
  }
}

const runImport = async () => {
  if (!selectedFile.value) {
    showNotify('Selecione um arquivo XML primeiro.', 'error')
    return
  }

  if (!window.confirm('Importar o catálogo deste XML? Produtos existentes podem ser atualizados.')) {
    return
  }

  try {
    importing.value = true
    const { data } = await api.post('/merchant/import/catalog/xml', buildFormData())
    importStats.value = data.stats
    preview.value = null
    showNotify(data.message || 'Catálogo importado com sucesso.')
  } catch (error) {
    console.error('Erro ao importar XML:', error)
    showNotify(error.response?.data?.details || error.response?.data?.message || 'Erro ao importar catálogo.', 'error')
  } finally {
    importing.value = false
  }
}
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <PageHeader
        title="Importação de produtos"
        subtitle="Traga seu catálogo de outro sistema para o PartiuMenu via XML."
      >
        <template #icon>
          <Upload size="26" />
        </template>
      </PageHeader>

      <FeatureAccessLoading v-if="isLoading" />

      <section v-else-if="isLocked" class="relative overflow-hidden rounded-3xl border border-slate-800 bg-slate-950 p-8 text-white shadow-xl">
        <div class="relative z-10 max-w-2xl">
          <div class="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-red-500 shadow-lg shadow-red-950/30">
            <Lock size="22" />
          </div>

          <h2 class="text-3xl font-black leading-tight">
            Importação disponível no Premium
          </h2>

          <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-300">
            Importe produtos por XML e conecte canais externos sem recadastrar tudo manualmente.
          </p>

          <button
            type="button"
            class="mt-7 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-700 active:scale-95"
            @click="router.push('/billing')"
          >
            Ver plano Premium
            <ArrowUpRight size="18" />
          </button>
        </div>

        <FileSpreadsheet class="absolute -right-10 -bottom-10 text-white/5" size="190" />
      </section>

      <template v-else-if="isUnlocked">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
          <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-black text-slate-900">Importar por XML</h2>
                <p class="mt-1 text-sm font-bold text-slate-500">
                  Categorias, produtos, preços, grupos de opções e fotos.
                </p>
              </div>

              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs font-black uppercase tracking-wide text-slate-700 transition hover:bg-slate-100"
                :disabled="downloadingSample"
                @click="downloadSample"
              >
                <Loader2 v-if="downloadingSample" size="14" class="animate-spin" />
                <Download v-else size="14" />
                Baixar exemplo
              </button>
            </div>

            <div
              class="mt-5 rounded-3xl border-2 border-dashed p-8 text-center transition-colors"
              :class="isDragging ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50'"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onDrop"
            >
              <input
                ref="fileInput"
                type="file"
                accept=".xml,text/xml,application/xml"
                class="hidden"
                @change="onFileChange"
              >

              <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-slate-400 shadow-sm">
                <FileSpreadsheet size="28" />
              </div>

              <p class="mt-4 text-sm font-black text-slate-900">
                Arraste o XML aqui ou selecione do computador
              </p>
              <p class="mt-1 text-xs font-bold text-slate-500">
                Máximo 10 MB · formato PartiuMenu v1
              </p>

              <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                <button
                  type="button"
                  class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white transition hover:bg-slate-800"
                  @click="openFilePicker"
                >
                  Escolher arquivo
                </button>

                <button
                  v-if="hasFile"
                  type="button"
                  class="rounded-2xl border border-slate-200 px-5 py-3 text-sm font-black text-slate-600 transition hover:bg-white"
                  @click="clearFile"
                >
                  Remover
                </button>
              </div>

              <p v-if="selectedFile" class="mt-4 text-xs font-bold text-emerald-700">
                {{ selectedFile.name }} · {{ (selectedFile.size / 1024).toFixed(1) }} KB
              </p>
            </div>

            <label class="mt-5 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <input v-model="updateExisting" type="checkbox" class="mt-1 rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-sm font-bold leading-relaxed text-slate-600">
                Atualizar produtos e categorias existentes com o mesmo ID ou slug.
                Grupos de opções ausentes no XML serão removidos do produto.
              </span>
            </label>

            <div class="mt-5 flex flex-wrap gap-3">
              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                :disabled="!hasFile || previewing || importing"
                @click="runPreview"
              >
                <Loader2 v-if="previewing" size="16" class="animate-spin" />
                Pré-visualizar
              </button>

              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-50"
                :disabled="!hasFile || previewing || importing"
                @click="runImport"
              >
                <Loader2 v-if="importing" size="16" class="animate-spin" />
                <Upload v-else size="16" />
                Importar catálogo
              </button>
            </div>
          </section>

          <aside class="space-y-4">
            <section v-if="preview" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <h3 class="text-sm font-black uppercase tracking-wide text-slate-500">Pré-visualização</h3>

              <dl class="mt-4 space-y-3">
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Versão XML</dt>
                  <dd class="font-black text-slate-900">{{ preview.version }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Categorias</dt>
                  <dd class="font-black text-slate-900">{{ preview.categories }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Produtos</dt>
                  <dd class="font-black text-slate-900">{{ preview.products }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Grupos de opções</dt>
                  <dd class="font-black text-slate-900">{{ preview.option_groups }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Itens de opção</dt>
                  <dd class="font-black text-slate-900">{{ preview.options }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Com imagens</dt>
                  <dd class="font-black text-slate-900">{{ preview.with_images }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Novos produtos</dt>
                  <dd class="font-black text-emerald-700">{{ preview.new_products }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                  <dt class="font-bold text-slate-500">Já existentes</dt>
                  <dd class="font-black text-slate-900">{{ preview.existing_products }}</dd>
                </div>
              </dl>

              <p
                v-if="preview.would_skip_products > 0"
                class="mt-4 rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs font-bold leading-relaxed text-amber-800"
              >
                {{ preview.would_skip_products }} produto(s) novo(s) não serão importados por limite do plano.
              </p>
            </section>

            <section v-if="importStats" class="rounded-3xl border border-emerald-100 bg-emerald-50 p-6 shadow-sm">
              <div class="flex items-center gap-2 text-emerald-800">
                <CheckCircle size="18" />
                <h3 class="text-sm font-black uppercase tracking-wide">Importação concluída</h3>
              </div>

              <dl class="mt-4 space-y-2">
                <div
                  v-for="(label, key) in statLabels"
                  :key="key"
                  v-show="importStats[key] > 0"
                  class="flex items-center justify-between gap-3 text-sm"
                >
                  <dt class="font-bold text-emerald-700">{{ label }}</dt>
                  <dd class="font-black text-emerald-900">{{ importStats[key] }}</dd>
                </div>
              </dl>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                <ShieldCheck size="20" />
              </div>

              <h2 class="mt-4 text-lg font-black text-slate-900">Formato suportado</h2>
              <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">
                Use o XML de exemplo como base. Cada item pode ter atributo <code class="rounded bg-slate-100 px-1">id</code>
                para reimportação sem duplicar. Fotos de produtos e complementos via URL ou base64.
              </p>

              <div class="mt-4 flex flex-wrap gap-2">
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">Categorias</span>
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">Produtos</span>
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">Preços</span>
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">Grupos</span>
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">Itens dos grupos</span>
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">Fotos</span>
              </div>
            </section>

            <section class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-6">
              <h2 class="text-sm font-black uppercase tracking-wide text-slate-500">Em breve</h2>
              <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">
                API personalizada para sincronizar estoque, pedidos e CRM local.
              </p>
            </section>
          </aside>
        </div>
      </template>
    </div>
</template>
