<script setup>
import { computed, reactive, ref, watch } from 'vue'
import api from '@/services/api'
import { createCardToken } from '@/services/pagarme'
import { getApiErrorMessage } from '@/utils/apiError'
import {
  CalendarSync,
  CheckCircle2,
  CreditCard,
  Loader2,
  Lock,
  Mail,
  MapPin,
  Phone,
  ShieldCheck,
  User,
  X
} from 'lucide-vue-next'

const props = defineProps({
  open: { type: Boolean, default: false },
  plan: { type: Object, default: null },
  pagarme: { type: Object, default: null },
  defaultEmail: { type: String, default: '' },
  defaultPhone: { type: String, default: '' },
})

const emit = defineEmits(['update:open', 'success', 'error'])

const submitting = ref(false)
const formError = ref('')

const BRAZILIAN_STATES = [
  'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
]

const form = reactive({
  billing_email: '',
  holder_name: '',
  holder_document: '',
  holder_phone: '',
  billing_zip_code: '',
  billing_street: '',
  billing_number: '',
  billing_district: '',
  billing_city: '',
  billing_state: 'CE',
  billing_complement: '',
  number: '',
  exp_month: '',
  exp_year: '',
  cvv: '',
})

const planPrice = computed(() =>
  Number(props.plan?.display_price ?? props.plan?.price ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
)

const regularPlanPrice = computed(() => {
  const regular = Number(props.plan?.regular_price ?? 0)
  const current = Number(props.plan?.display_price ?? props.plan?.price ?? 0)

  return regular > current
    ? regular.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
    : null
})

const environmentLabel = computed(() =>
  props.pagarme?.environment === 'sandbox' ? 'Modo de testes (sandbox)' : 'Ambiente seguro de produção'
)

const isSandbox = computed(() => props.pagarme?.environment === 'sandbox')

const fieldClass = 'w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#00a868] focus:ring-2 focus:ring-[#00a868]/20'
const labelClass = 'mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-slate-600'

const resetForm = () => {
  form.billing_email = props.defaultEmail || ''
  form.holder_name = ''
  form.holder_document = ''
  form.holder_phone = props.defaultPhone || ''
  form.billing_zip_code = ''
  form.billing_street = ''
  form.billing_number = ''
  form.billing_district = ''
  form.billing_city = ''
  form.billing_state = 'CE'
  form.billing_complement = ''
  form.number = ''
  form.exp_month = ''
  form.exp_year = ''
  form.cvv = ''
  formError.value = ''
}

const close = () => {
  if (submitting.value) return
  emit('update:open', false)
}

watch(() => props.open, (isOpen) => { if (isOpen) resetForm() })
watch(() => props.defaultEmail, (email) => {
  if (!form.billing_email) form.billing_email = email || ''
}, { immediate: true })

watch(() => props.defaultPhone, (phone) => {
  if (!form.holder_phone) form.holder_phone = phone || ''
}, { immediate: true })

const onlyDigits = (value, maxLength = null) => {
  const digits = String(value || '').replace(/\D/g, '')
  return maxLength ? digits.slice(0, maxLength) : digits
}

const formatCardNumber = (value) =>
  onlyDigits(value, 16).replace(/(\d{4})(?=\d)/g, '$1 ').trim()

const formatDocument = (value) => {
  const digits = onlyDigits(value, 11)
  if (digits.length <= 3) return digits
  if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`
  if (digits.length <= 9) return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6)}`
  return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`
}

const formatPhone = (value) => {
  const digits = onlyDigits(value, 13)

  if (digits.length <= 2) return digits
  if (digits.length <= 7) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`
  if (digits.length <= 11) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2, digits.length > 10 ? 7 : 6)}-${digits.slice(digits.length > 10 ? 7 : 6)}`
  }

  return `+${digits.slice(0, 2)} (${digits.slice(2, 4)}) ${digits.slice(4, 9)}-${digits.slice(9)}`
}

const formatCep = (value) => {
  const digits = onlyDigits(value, 8)
  if (digits.length <= 5) return digits
  return `${digits.slice(0, 5)}-${digits.slice(5)}`
}

const billingPayload = () => ({
  billing_zip_code: onlyDigits(form.billing_zip_code, 8),
  billing_street: form.billing_street.trim(),
  billing_number: form.billing_number.trim(),
  billing_city: form.billing_city.trim(),
  billing_state: form.billing_state.trim().toUpperCase().slice(0, 2),
  billing_district: form.billing_district.trim(),
  billing_complement: form.billing_complement.trim(),
})

const validateForm = () => {
  if (!props.plan?.id) return 'Plano inválido.'
  if (!props.pagarme?.configured) return 'Pagar.me ainda não está configurado.'
  if (!form.billing_email.trim()) return 'Informe o e-mail de cobrança.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.billing_email.trim())) return 'E-mail inválido.'
  if (!form.holder_name.trim()) return 'Informe o nome no cartão.'
  if (onlyDigits(form.holder_document).length !== 11) return 'Informe um CPF válido.'
  if (onlyDigits(form.holder_phone).length < 10) return 'Informe o WhatsApp do titular com DDD.'
  if (onlyDigits(form.billing_zip_code).length !== 8) return 'Informe o CEP de cobrança.'
  if (!form.billing_street.trim()) return 'Informe a rua de cobrança.'
  if (!form.billing_number.trim()) return 'Informe o número de cobrança.'
  if (!form.billing_city.trim()) return 'Informe a cidade de cobrança.'
  if (!form.billing_state.trim()) return 'Informe o UF de cobrança.'
  if (onlyDigits(form.number).length < 13) return 'Informe o número do cartão.'
  if (!form.exp_month || Number(form.exp_month) < 1 || Number(form.exp_month) > 12) return 'Mês inválido.'
  if (!form.exp_year || String(form.exp_year).length < 2) return 'Ano inválido.'
  if (onlyDigits(form.cvv).length < 3) return 'Informe o CVV.'
  return ''
}

const handleSubmit = async () => {
  formError.value = validateForm()
  if (formError.value) return

  submitting.value = true

  try {
    const cardToken = await createCardToken({
      number: form.number,
      holder_name: form.holder_name,
      holder_document: form.holder_document,
      exp_month: form.exp_month,
      exp_year: form.exp_year,
      cvv: form.cvv,
      ...billingPayload(),
    })

    const { data } = await api.post('/merchant/billing/pagarme/subscription', {
      plan_id: props.plan.id,
      billing_email: form.billing_email.trim(),
      card_token: cardToken,
      holder_document: onlyDigits(form.holder_document),
      holder_name: form.holder_name.trim(),
      holder_phone: onlyDigits(form.holder_phone),
      ...billingPayload(),
    })

    emit('success', data)
    emit('update:open', false)
  } catch (error) {
    formError.value = getApiErrorMessage(
      error,
      'Não foi possível concluir a assinatura.'
    )

    emit('error', formError.value)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[120] flex items-end justify-center bg-slate-950/70 p-0 backdrop-blur-sm sm:items-center sm:p-4"
      @click.self="close"
    >
      <div class="flex max-h-[96vh] w-full max-w-4xl flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl lg:flex-row">
        <!-- Painel esquerdo — resumo + confiança -->
        <aside class="relative flex-shrink-0 bg-gradient-to-br from-[#0f172a] via-[#1e293b] to-[#0f172a] p-6 text-white sm:p-8 lg:w-[340px]">
          <button
            type="button"
            class="absolute right-4 top-4 rounded-lg p-2 text-slate-400 transition hover:bg-white/10 hover:text-white lg:hidden"
            :disabled="submitting"
            @click="close"
          >
            <X size="18" />
          </button>

          <!-- Brand Pagar.me -->
          <div class="mb-8 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#00a868] text-sm font-black tracking-tight text-white shadow-lg shadow-[#00a868]/30">
              P
            </div>
            <div>
              <p class="text-sm font-bold leading-none">Pagar.me</p>
              <p class="mt-1 text-[11px] text-slate-400">Pagamento seguro e recorrente</p>
            </div>
          </div>

          <div class="rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur-sm">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Você está assinando</p>
            <h2 class="mt-2 text-2xl font-bold">{{ plan?.name || 'Plano' }}</h2>
            <p v-if="plan?.description" class="mt-2 text-sm leading-relaxed text-slate-300">
              {{ plan.description }}
            </p>
            <div class="mt-5 flex items-end gap-2 border-t border-white/10 pt-5">
              <span v-if="regularPlanPrice" class="mb-1 text-lg text-slate-500 line-through">{{ regularPlanPrice }}</span>
              <span class="text-3xl font-bold">{{ planPrice }}</span>
              <span class="mb-1 text-sm text-slate-400">/mês</span>
            </div>
            <p
              v-if="plan?.launch_offer_available && plan?.launch_slots_remaining != null"
              class="mt-3 text-xs font-semibold text-amber-200"
            >
              Oferta fundador · restam {{ plan.launch_slots_remaining }} vagas · preço promocional por {{ plan.launch_price_months || 12 }} meses
            </p>
          </div>

          <ul class="mt-6 space-y-3 text-sm text-slate-300">
            <li class="flex items-start gap-2.5">
              <CalendarSync size="16" class="mt-0.5 flex-shrink-0 text-[#00a868]" />
              Cobrança automática mensal no cartão
            </li>
            <li class="flex items-start gap-2.5">
              <ShieldCheck size="16" class="mt-0.5 flex-shrink-0 text-[#00a868]" />
              Dados tokenizados — não armazenamos seu cartão
            </li>
            <li class="flex items-start gap-2.5">
              <Lock size="16" class="mt-0.5 flex-shrink-0 text-[#00a868]" />
              Conexão criptografada via Pagar.me
            </li>
          </ul>

          <div
            v-if="isSandbox"
            class="mt-8 rounded-xl border border-amber-400/40 bg-amber-400/10 px-4 py-3"
          >
            <p class="flex items-center gap-2 text-xs font-bold text-amber-200">
              <CheckCircle2 size="14" />
              {{ environmentLabel }}
            </p>
            <p class="mt-1.5 text-[11px] leading-relaxed text-slate-400">
              Nenhuma cobrança real será feita. Use cartão 4111 1111 1111 1111 · CVV 123 · CPF 123.456.789-09
            </p>
          </div>

          <div
            v-else
            class="mt-8 rounded-xl border border-[#00a868]/30 bg-[#00a868]/10 px-4 py-3"
          >
            <p class="flex items-center gap-2 text-xs font-semibold text-[#7dffb8]">
              <ShieldCheck size="14" />
              {{ environmentLabel }}
            </p>
            <p class="mt-1.5 text-[11px] leading-relaxed text-slate-400">
              Cobrança real no cartão. Você pode cancelar a assinatura quando quiser.
            </p>
          </div>
        </aside>

        <!-- Painel direito — formulário -->
        <div class="flex min-h-0 flex-1 flex-col">
          <div class="hidden items-center justify-between border-b border-slate-100 px-8 py-5 lg:flex">
            <div>
              <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Checkout</p>
              <h3 class="text-lg font-bold text-slate-900">Dados de pagamento</h3>
            </div>
            <button
              type="button"
              class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
              :disabled="submitting"
              @click="close"
            >
              <X size="18" />
            </button>
          </div>

          <form class="flex flex-1 flex-col overflow-y-auto px-6 py-6 sm:px-8" @submit.prevent="handleSubmit">
            <p class="mb-5 text-sm text-slate-500 lg:hidden">
              Pagamento processado com segurança pelo <strong class="text-[#00a868]">Pagar.me</strong>
            </p>

            <div class="space-y-5">
              <div>
                <label :class="labelClass">
                  <Mail size="13" class="text-slate-400" />
                  E-mail de cobrança
                </label>
                <input
                  v-model="form.billing_email"
                  type="email"
                  autocomplete="email"
                  :class="fieldClass"
                  placeholder="seu@email.com"
                />
              </div>

              <div>
                <label :class="labelClass">
                  <Phone size="13" class="text-slate-400" />
                  WhatsApp do titular
                </label>
                <input
                  :value="form.holder_phone"
                  type="tel"
                  inputmode="tel"
                  autocomplete="tel"
                  :class="fieldClass"
                  placeholder="(85) 99999-9999"
                  @input="form.holder_phone = formatPhone($event.target.value)"
                />
              </div>

              <div class="space-y-4 border-t border-slate-100 pt-5">
                <p :class="labelClass">
                  <MapPin size="13" class="text-slate-400" />
                  Endereço de cobrança
                </p>

                <div class="grid gap-4 sm:grid-cols-[140px_1fr]">
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">CEP</label>
                    <input
                      :value="form.billing_zip_code"
                      type="text"
                      inputmode="numeric"
                      :class="fieldClass"
                      placeholder="00000-000"
                      @input="form.billing_zip_code = formatCep($event.target.value)"
                    />
                  </div>
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Rua</label>
                    <input
                      v-model="form.billing_street"
                      type="text"
                      :class="fieldClass"
                      placeholder="Nome da rua"
                    />
                  </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Número</label>
                    <input
                      v-model="form.billing_number"
                      type="text"
                      :class="fieldClass"
                      placeholder="123"
                    />
                  </div>
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Bairro</label>
                    <input
                      v-model="form.billing_district"
                      type="text"
                      :class="fieldClass"
                      placeholder="Centro"
                    />
                  </div>
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Complemento</label>
                    <input
                      v-model="form.billing_complement"
                      type="text"
                      :class="fieldClass"
                      placeholder="Apto, bloco..."
                    />
                  </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-[1fr_88px]">
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Cidade</label>
                    <input
                      v-model="form.billing_city"
                      type="text"
                      :class="fieldClass"
                      placeholder="Fortaleza"
                    />
                  </div>
                  <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">UF</label>
                    <select
                      v-model="form.billing_state"
                      :class="fieldClass"
                    >
                      <option v-for="uf in BRAZILIAN_STATES" :key="uf" :value="uf">{{ uf }}</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="space-y-4 border-t border-slate-100 pt-5">
              <div class="grid gap-4 sm:grid-cols-2">
                <div>
                  <label :class="labelClass">
                    <User size="13" class="text-slate-400" />
                    Nome no cartão
                  </label>
                  <input
                    v-model="form.holder_name"
                    type="text"
                    autocomplete="cc-name"
                    :class="fieldClass"
                    placeholder="Como impresso no cartão"
                  />
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-semibold text-slate-600">CPF do titular</label>
                  <input
                    :value="form.holder_document"
                    type="text"
                    inputmode="numeric"
                    :class="fieldClass"
                    placeholder="000.000.000-00"
                    @input="form.holder_document = formatDocument($event.target.value)"
                  />
                </div>
              </div>

              <div>
                <label :class="labelClass">
                  <CreditCard size="13" class="text-slate-400" />
                  Número do cartão
                </label>
                <input
                  :value="form.number"
                  type="text"
                  inputmode="numeric"
                  autocomplete="cc-number"
                  :class="[fieldClass, 'font-mono tracking-wider']"
                  placeholder="0000 0000 0000 0000"
                  @input="form.number = formatCardNumber($event.target.value)"
                />
              </div>

              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label class="mb-1.5 block text-xs font-semibold text-slate-600">Mês</label>
                  <input
                    v-model="form.exp_month"
                    type="text"
                    inputmode="numeric"
                    maxlength="2"
                    autocomplete="cc-exp-month"
                    :class="fieldClass"
                    placeholder="MM"
                    @input="form.exp_month = onlyDigits(form.exp_month, 2)"
                  />
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-semibold text-slate-600">Ano</label>
                  <input
                    v-model="form.exp_year"
                    type="text"
                    inputmode="numeric"
                    maxlength="4"
                    autocomplete="cc-exp-year"
                    :class="fieldClass"
                    placeholder="AAAA"
                    @input="form.exp_year = onlyDigits(form.exp_year, 4)"
                  />
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-semibold text-slate-600">CVV</label>
                  <input
                    v-model="form.cvv"
                    type="password"
                    inputmode="numeric"
                    maxlength="4"
                    autocomplete="cc-csc"
                    :class="fieldClass"
                    placeholder="•••"
                    @input="form.cvv = onlyDigits(form.cvv, 4)"
                  />
                </div>
              </div>
              </div>
            </div>

            <div
              v-if="formError"
              class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
              {{ formError }}
            </div>

            <div class="mt-auto space-y-4 pt-6">
              <button
                type="submit"
                :disabled="submitting"
                class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-[#00a868] py-3.5 text-sm font-bold text-white shadow-lg shadow-[#00a868]/25 transition hover:bg-[#00945c] disabled:cursor-not-allowed disabled:opacity-60"
              >
                <Loader2 v-if="submitting" class="animate-spin" size="18" />
                <Lock v-else size="18" />
                {{ submitting ? 'Processando no Pagar.me...' : `Assinar ${plan?.name} — ${planPrice}/mês` }}
              </button>

              <div class="flex items-center justify-center gap-2 text-[11px] text-slate-400">
                <ShieldCheck size="13" class="text-[#00a868]" />
                Processado e protegido pelo
                <span class="font-bold text-[#00a868]">Pagar.me</span>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </Teleport>
</template>
