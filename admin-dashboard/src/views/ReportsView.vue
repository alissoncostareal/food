<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FeatureAccessLoading from '@/components/auth/FeatureAccessLoading.vue'
import { useFeatureAccess } from '@/composables/useFeatureAccess'
import {
  ArrowUpRight,
  BarChart3,
  Calendar,
  CalendarRange,
  CheckCircle,
  CreditCard,
  Download,
  ExternalLink,
  FileSpreadsheet,
  Loader2,
  Lock,
  PackageCheck,
  Receipt,
  TrendingUp,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const exporting = ref(false)
const reportMonth = ref(new Date().toISOString().slice(0, 7))
const toast = ref({ show: false, message: '', type: 'success' })
const loadingSales = ref(false)
const salesData = ref(null)
const salesBeginDate = ref('')
const salesEndDate = ref('')
const ifoodConnected = ref(false)
const checkingIfood = ref(false)

const { isLoading, isLocked, isUnlocked } = useFeatureAccess('advanced_reports')
const { isUnlocked: hasIfoodIntegration } = useFeatureAccess('ifood_integration')

const monthLabel = computed(() => {
  const [year, month] = reportMonth.value.split('-')
  const date = new Date(Number(year), Number(month) - 1, 1)

  return date.toLocaleDateString('pt-BR', {
    month: 'long',
    year: 'numeric'
  })
})

const reportItems = [
  {
    title: 'Resumo financeiro',
    description: 'Faturamento, descontos, entrega, ticket médio e pedidos válidos.',
    icon: Receipt
  },
  {
    title: 'Formas de pagamento',
    description: 'Totais separados por Pix, dinheiro, crédito, débito e não informado.',
    icon: CreditCard
  },
  {
    title: 'Produtos vendidos',
    description: 'Ranking com quantidade e total vendido por produto.',
    icon: PackageCheck
  },
  {
    title: 'Pedidos detalhados',
    description: 'Cliente, telefone, cupom, itens, observações e valores do pedido.',
    icon: BarChart3
  }
]

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }

  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(Number(value || 0))
}

const initSalesDates = () => {
  const end = new Date()
  const start = new Date()
  start.setDate(end.getDate() - 6)

  salesEndDate.value = end.toISOString().slice(0, 10)
  salesBeginDate.value = start.toISOString().slice(0, 10)
}

const loadIfoodStatus = async () => {
  if (!hasIfoodIntegration.value) {
    ifoodConnected.value = false
    return
  }

  try {
    checkingIfood.value = true
    const { data } = await api.get('/merchant/integrations/ifood/connection')
    ifoodConnected.value = data.store?.status === 'connected'
  } catch {
    ifoodConnected.value = false
  } finally {
    checkingIfood.value = false
  }
}

const fetchIfoodSales = async () => {
  try {
    loadingSales.value = true
    salesData.value = null

    const { data } = await api.get('/merchant/integrations/ifood/sales', {
      params: {
        begin_sales_date: salesBeginDate.value,
        end_sales_date: salesEndDate.value
      }
    })

    salesData.value = data.sales
    showNotify(data.message || 'Vendas iFood carregadas.')
  } catch (error) {
    const message =
      error.response?.data?.details ||
      error.response?.data?.message ||
      'Erro ao consultar vendas do iFood.'

    showNotify(message, 'error')
  } finally {
    loadingSales.value = false
  }
}

watch(isUnlocked, (unlocked) => {
  if (unlocked) {
    loadIfoodStatus()
  }
}, { immediate: true })

onMounted(initSalesDates)

const blobToText = (blob) => {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()

    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsText(blob)
  })
}

const getFilename = (disposition) => {
  const utf8Match = disposition?.match(/filename\*=UTF-8''([^;]+)/i)
  const regularMatch = disposition?.match(/filename="?([^"]+)"?/i)

  if (utf8Match?.[1]) {
    return decodeURIComponent(utf8Match[1])
  }

  return regularMatch?.[1] || `relatorio-vendas-${reportMonth.value}.xls`
}

const downloadSalesReport = async () => {
  if (exporting.value) return

  if (isLocked.value) {
    showNotify('Relatórios avançados estão disponíveis no Premium.', 'error')
    router.push('/billing')
    return
  }

  try {
    exporting.value = true

    const response = await api.get('/merchant/reports/sales/monthly', {
      params: {
        month: reportMonth.value,
        format: 'xls'
      },
      responseType: 'blob'
    })

    const contentType = response.headers['content-type'] || ''

    if (contentType.includes('application/json')) {
      const errorText = await blobToText(response.data)
      const error = JSON.parse(errorText)
      throw new Error(error.message || 'Não foi possível gerar a planilha.')
    }

    const filename = getFilename(response.headers['content-disposition'])
    const blobUrl = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')

    link.href = blobUrl
    link.setAttribute('download', filename)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(blobUrl)

    showNotify('Planilha de vendas baixada com sucesso!')
  } catch (error) {
    if (error.response?.status === 403) {
      showNotify('Relatórios avançados estão disponíveis no Premium.', 'error')
      router.push('/billing')
      return
    }

    if (error.response?.data instanceof Blob) {
      try {
        const errorText = await blobToText(error.response.data)
        const parsedError = JSON.parse(errorText)
        showNotify(parsedError.message || 'Não foi possível gerar a planilha.', 'error')
        return
      } catch {
        showNotify('Não foi possível gerar a planilha.', 'error')
        return
      }
    }

    showNotify(error.message || 'Não foi possível baixar a planilha.', 'error')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <PageHeader
        title="Relatórios de vendas"
        subtitle="Exporte planilhas mensais com faturamento, pagamentos e produtos vendidos."
      >
        <template #icon>
          <FileSpreadsheet size="26" />
        </template>
      </PageHeader>

      <div
        v-if="isLocked"
        class="inline-flex rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs font-black uppercase tracking-wider text-amber-700"
      >
        Premium
      </div>

      <FeatureAccessLoading v-if="isLoading" />

      <section v-else-if="isLocked" class="grid grid-cols-1 gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <div class="relative overflow-hidden rounded-3xl border border-slate-800 bg-slate-950 p-8 text-white shadow-xl">
          <div class="relative z-10 max-w-2xl">
            <div class="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-red-500 shadow-lg shadow-red-950/30">
              <Lock size="22" />
            </div>

            <h2 class="text-3xl font-black leading-tight">
              Relatórios avançados disponíveis no Premium
            </h2>

            <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-300">
              Baixe planilhas mensais com faturamento, pagamentos, produtos vendidos e pedidos detalhados.
            </p>

            <button
              type="button"
              class="mt-7 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-700 active:scale-95"
              @click="router.push('/plans')"
            >
              Ativar Premium
              <ArrowUpRight size="18" />
            </button>
          </div>

          <FileSpreadsheet class="absolute -right-10 -bottom-10 text-white/5" size="190" />
        </div>

        <div class="grid gap-4">
          <article
            v-for="item in reportItems"
            :key="item.title"
            class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
          >
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <component :is="item.icon" size="18" />
            </div>
            <h3 class="mt-3 text-sm font-black text-slate-900">{{ item.title }}</h3>
            <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">{{ item.description }}</p>
          </article>
        </div>
      </section>

      <template v-else>
      <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
        <div class="flex flex-col gap-4 border-b border-slate-100 pb-6 md:flex-row md:items-center md:justify-between">
          <div>
            <h2 class="text-lg font-black text-slate-900">Exportação mensal</h2>
            <p class="mt-1 text-sm font-bold text-slate-500">
              Relatório de {{ monthLabel }} · resumo financeiro, pagamentos, produtos e pedidos.
            </p>
          </div>
          <Calendar class="text-red-500" size="24" />
        </div>

        <div class="mt-6 grid grid-cols-1 items-end gap-4 md:grid-cols-[1fr_auto]">
          <label class="block">
            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
              Mês de referência
            </span>
            <input
              v-model="reportMonth"
              type="month"
              class="pm-input-sm mt-2"
            />
          </label>

          <button
            type="button"
            :disabled="exporting"
            class="flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white shadow-lg shadow-red-100 transition-all hover:bg-red-700 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
            @click="downloadSalesReport"
          >
            <Loader2 v-if="exporting" size="16" class="animate-spin" />
            <Download v-else size="16" />
            {{ exporting ? 'Gerando...' : 'Baixar planilha' }}
          </button>
        </div>

        <ul class="mt-8 grid gap-3 sm:grid-cols-2">
          <li
            v-for="item in reportItems"
            :key="item.title"
            class="flex items-start gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3"
          >
            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
              <component :is="item.icon" size="16" />
            </div>
            <div>
              <p class="text-sm font-black text-slate-900">{{ item.title }}</p>
              <p class="mt-0.5 text-xs font-bold text-slate-500">{{ item.description }}</p>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="hasIfoodIntegration" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
        <div class="flex flex-col gap-4 border-b border-slate-100 pb-6 md:flex-row md:items-center md:justify-between">
          <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <TrendingUp size="23" />
            </div>
            <div>
              <h2 class="text-lg font-black text-slate-900">Vendas iFood</h2>
              <p class="text-xs font-bold text-slate-400">Resumo financeiro via API Sales (módulo Financial)</p>
            </div>
          </div>
        </div>

        <div v-if="checkingIfood" class="mt-6 flex justify-center py-8">
          <Loader2 class="h-8 w-8 animate-spin text-red-600" />
        </div>

        <div
          v-else-if="!ifoodConnected"
          class="mt-6 rounded-2xl border border-amber-100 bg-amber-50 p-5"
        >
          <p class="text-sm font-bold text-amber-800">
            Conecte sua loja iFood para consultar vendas do período.
          </p>
          <button
            type="button"
            class="mt-4 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition hover:bg-red-700"
            @click="router.push('/integrations/ifood')"
          >
            Ir para integração iFood
            <ExternalLink size="16" />
          </button>
        </div>

        <template v-else>
          <p class="mt-6 text-sm font-bold leading-relaxed text-slate-500">
            Consulta pedidos e valores do período selecionado na plataforma iFood.
          </p>

          <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div>
              <label for="ifood-sales-begin" class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Data inicial
              </label>
              <input
                id="ifood-sales-begin"
                v-model="salesBeginDate"
                type="date"
                class="pm-input-sm mt-2"
              />
            </div>
            <div>
              <label for="ifood-sales-end" class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Data final
              </label>
              <input
                id="ifood-sales-end"
                v-model="salesEndDate"
                type="date"
                class="pm-input-sm mt-2"
              />
            </div>
          </div>

          <button
            type="button"
            :disabled="loadingSales || !salesBeginDate || !salesEndDate"
            class="mt-5 inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition-all hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            @click="fetchIfoodSales"
          >
            <Loader2 v-if="loadingSales" class="animate-spin" size="16" />
            <CalendarRange v-else size="16" />
            {{ loadingSales ? 'Carregando...' : 'Consultar vendas iFood' }}
          </button>

          <div v-if="salesData?.summary" class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Pedidos</p>
              <p class="mt-1 text-2xl font-black text-slate-900">{{ salesData.summary.total_orders }}</p>
            </div>
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-emerald-600">Concluídos</p>
              <p class="mt-1 text-2xl font-black text-emerald-900">{{ salesData.summary.concluded_orders }}</p>
            </div>
            <div class="rounded-2xl border border-blue-100 bg-blue-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-blue-600">Valor bruto</p>
              <p class="mt-1 text-xl font-black text-blue-900">{{ formatCurrency(salesData.summary.gross_total) }}</p>
            </div>
            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
              <p class="text-[10px] font-black uppercase tracking-widest text-indigo-600">Saldo líquido</p>
              <p class="mt-1 text-xl font-black text-indigo-900">{{ formatCurrency(salesData.summary.net_total) }}</p>
            </div>
          </div>

          <div v-if="salesData?.sales?.length" class="mt-5 overflow-x-auto rounded-2xl border border-slate-100">
            <table class="min-w-full text-left text-sm">
              <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                <tr>
                  <th class="px-4 py-3">Pedido</th>
                  <th class="px-4 py-3">Data</th>
                  <th class="px-4 py-3">Status</th>
                  <th class="px-4 py-3">Bruto</th>
                  <th class="px-4 py-3">Líquido</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="sale in salesData.sales.slice(0, 20)"
                  :key="sale.id"
                  class="border-t border-slate-100"
                >
                  <td class="px-4 py-3 font-black text-slate-900">#{{ sale.short_id || '—' }}</td>
                  <td class="px-4 py-3 font-bold text-slate-600">
                    {{ sale.created_at ? new Date(sale.created_at).toLocaleString('pt-BR') : '—' }}
                  </td>
                  <td class="px-4 py-3 font-bold text-slate-600">{{ sale.status || '—' }}</td>
                  <td class="px-4 py-3 font-bold text-slate-700">
                    {{ formatCurrency((sale.gross_value?.bag || 0) + (sale.gross_value?.delivery_fee || 0)) }}
                  </td>
                  <td class="px-4 py-3 font-black text-emerald-700">{{ formatCurrency(sale.net_balance) }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="salesData.sales.length > 20" class="border-t border-slate-100 px-4 py-3 text-xs font-bold text-slate-500">
              Mostrando 20 de {{ salesData.sales.length }} vendas do período.
            </p>
          </div>

          <div
            v-else-if="salesData && !salesData.sales?.length"
            class="mt-5 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm font-bold text-amber-800"
          >
            Nenhuma venda iFood encontrada no período.
          </div>
        </template>
      </section>
      </template>
    </div>
</template>
