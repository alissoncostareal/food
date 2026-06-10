<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import api from '@/services/api'
import {
  BarChart3,
  Calendar,
  CreditCard,
  Download,
  FileSpreadsheet,
  Loader2,
  PackageCheck,
  Receipt,
  ShieldCheck,
  XCircle,
  CheckCircle,
  Lock,
  ArrowUpRight
} from 'lucide-vue-next'

const router = useRouter()
const exporting = ref(false)
const reportMonth = ref(new Date().toISOString().slice(0, 7))
const toast = ref({ show: false, message: '', type: 'success' })
const featureLocked = ref(false)

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

const fetchFeatureAccess = async () => {
  try {
    const { data } = await api.get('/me')
    featureLocked.value = !Boolean(data?.store?.plan?.features?.advanced_reports)
  } catch (error) {
    featureLocked.value = true
  }
}

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

  if (featureLocked.value) {
    showNotify('Relatórios avançados estão disponíveis no Premium.', 'error')
    router.push('/plans')
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
      router.push('/plans')
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

onMounted(fetchFeatureAccess)
</script>

<template>
  <DashboardLayout>
    <div v-if="toast.show" class="fixed top-5 right-5 z-[100] animate-in slide-in-from-right">
      <div
        :class="[
          'px-6 py-3 rounded-2xl shadow-lg font-black text-white flex items-center gap-3',
          toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500'
        ]"
      >
        <CheckCircle v-if="toast.type === 'success'" />
        <XCircle v-else />
        {{ toast.message }}
      </div>
    </div>

    <div class="space-y-8 pb-10 animate-in fade-in slide-in-from-bottom-2 duration-500">
      <section class="bg-white rounded-3xl border border-slate-200 shadow-sm p-8">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
          <div class="flex items-start gap-4">
            <div class="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center">
              <FileSpreadsheet size="26" />
            </div>

            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.18em] text-red-500">
                Premium
              </p>
              <h1 class="mt-1 text-2xl font-black text-slate-900 tracking-tight">
                Relatórios de vendas
              </h1>
              <p class="mt-2 max-w-2xl text-sm font-bold leading-relaxed text-slate-500">
                Exporte uma planilha mensal pronta para conferência de caixa, fechamento financeiro e envio ao contador.
              </p>
            </div>
          </div>

          <div class="flex items-center gap-2 rounded-2xl bg-emerald-50 border border-emerald-100 px-4 py-3 text-emerald-700">
            <ShieldCheck size="18" />
            <span class="text-xs font-black uppercase tracking-widest">Módulo Premium</span>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 xl:grid-cols-3 gap-8">
        <div
          v-if="featureLocked"
          class="xl:col-span-2 bg-slate-950 rounded-3xl border border-slate-800 shadow-xl p-8 text-white relative overflow-hidden"
        >
          <div class="relative z-10 max-w-2xl">
            <div class="w-12 h-12 rounded-2xl bg-red-500 flex items-center justify-center mb-5 shadow-lg shadow-red-950/30">
              <Lock size="22" />
            </div>

            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-300">
              Relatórios Premium
            </p>

            <h2 class="mt-2 text-3xl font-black leading-tight">
              Veja o que sua loja poderia exportar para fechar o mês com menos trabalho
            </h2>

            <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-300">
              No Premium, você baixa planilhas mensais com faturamento, pagamentos, descontos, produtos vendidos e pedidos detalhados.
            </p>

            <button
              type="button"
              @click="router.push('/plans')"
              class="mt-7 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-700 active:scale-95"
            >
              Ativar Premium
              <ArrowUpRight size="18" />
            </button>
          </div>

          <FileSpreadsheet class="absolute -right-10 -bottom-10 text-white/5" size="190" />
        </div>

        <div v-else class="xl:col-span-2 bg-white rounded-3xl border border-slate-200 shadow-sm p-8">
          <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-6">
            <div>
              <h2 class="text-lg font-black text-slate-900">Exportação mensal</h2>
              <p class="mt-1 text-sm font-bold text-slate-500">
                Selecione o mês e baixe a planilha em formato Excel.
              </p>
            </div>

            <Calendar class="text-red-500" size="24" />
          </div>

          <div class="mt-7 grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-end">
            <label class="block">
              <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Mês de referência
              </span>
              <input
                v-model="reportMonth"
                type="month"
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm font-black text-slate-800 outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100"
              />
            </label>

            <button
              type="button"
              @click="downloadSalesReport"
              :disabled="exporting"
              class="rounded-2xl bg-slate-900 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-600 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60 flex items-center justify-center gap-2"
            >
              <Loader2 v-if="exporting" size="16" class="animate-spin" />
              <Download v-else size="16" />
              {{ exporting ? 'Gerando...' : 'Baixar planilha' }}
            </button>
          </div>

          <div class="mt-6 rounded-2xl border border-slate-100 bg-slate-50 px-5 py-4">
            <p class="text-xs font-black uppercase tracking-widest text-slate-400">
              Arquivo gerado
            </p>
            <p class="mt-1 text-sm font-bold text-slate-700">
              Relatório de {{ monthLabel }} com resumo financeiro, formas de pagamento, produtos vendidos e pedidos detalhados.
            </p>
          </div>
        </div>

        <aside class="bg-slate-950 rounded-3xl border border-slate-800 shadow-lg p-8 text-white">
          <p class="text-[10px] font-black uppercase tracking-[0.18em] text-red-300">
            Para contabilidade
          </p>
          <h3 class="mt-2 text-xl font-black leading-tight">
            Menos trabalho manual no fechamento do mês
          </h3>
          <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-300">
            A planilha reúne os dados que normalmente o dono precisa conferir antes de calcular faturamento, taxas e repassar informações ao contador.
          </p>
        </aside>
      </section>

      <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
        <div
          v-for="item in reportItems"
          :key="item.title"
          class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6"
        >
          <div class="w-10 h-10 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center">
            <component :is="item.icon" size="20" />
          </div>

          <h3 class="mt-4 text-sm font-black text-slate-900">
            {{ item.title }}
          </h3>
          <p class="mt-2 text-xs font-bold leading-relaxed text-slate-500">
            {{ item.description }}
          </p>
        </div>
      </section>
    </div>
  </DashboardLayout>
</template>
