<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { fetchCurrentUser } from '@/composables/useFeatureAccess'
import {
  CheckCircle,
  Copy,
  Loader2,
  Mail,
  Shield,
  Trash2,
  UserPlus,
  Users,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const inviting = ref(false)
const canManageTeam = ref(false)
const members = ref([])
const invitations = ref([])
const roles = ref([])
const storeName = ref('')
const teamLimits = ref(null)
const showAddForms = ref(false)
const toast = ref({ show: false, message: '', type: 'success' })

const createForm = reactive({
  name: '',
  email: '',
  password: '',
  role: 'staff'
})

const inviteForm = reactive({
  email: '',
  role: 'staff'
})

const roleLabels = computed(() => ({
  manager: 'Gerente',
  staff: 'Operação'
}))

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const fetchTeam = async () => {
  loading.value = true

  try {
    const { data } = await api.get('/merchant/team')
    members.value = data.members || []
    invitations.value = data.invitations || []
    roles.value = data.roles || []
    storeName.value = data.store?.name || ''
    teamLimits.value = data.limits || null
  } catch (error) {
    if (error.response?.status === 403) {
      router.push('/dashboard')
      return
    }

    showNotify(error.response?.data?.message || 'Erro ao carregar equipe.', 'error')
  } finally {
    loading.value = false
  }
}

const createMember = async () => {
  if (saving.value) return

  saving.value = true

  try {
    await api.post('/merchant/team/members', { ...createForm })
    showNotify('Funcionário criado com sucesso.')
    createForm.name = ''
    createForm.email = ''
    createForm.password = ''
    createForm.role = 'staff'
    await fetchTeam()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Não foi possível criar o funcionário.', 'error')
  } finally {
    saving.value = false
  }
}

const sendInvite = async () => {
  if (inviting.value) return

  inviting.value = true

  try {
    const { data } = await api.post('/merchant/team/invitations', { ...inviteForm })
    showNotify('Convite gerado. Copie o link e envie ao funcionário.')
    inviteForm.email = ''
    inviteForm.role = 'staff'

    if (data.invitation?.invite_url) {
      await copyInviteLink(data.invitation.invite_url)
    }

    await fetchTeam()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Não foi possível gerar o convite.', 'error')
  } finally {
    inviting.value = false
  }
}

const copyInviteLink = async (url) => {
  try {
    await navigator.clipboard.writeText(url)
    showNotify('Link do convite copiado.')
  } catch {
    showNotify('Convite criado. Copie o link manualmente.', 'error')
  }
}

const removeMember = async (memberId) => {
  if (!confirm('Remover este funcionário da equipe?')) return

  try {
    await api.delete(`/merchant/team/members/${memberId}`)
    showNotify('Funcionário removido.')
    await fetchTeam()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Não foi possível remover.', 'error')
  }
}

const cancelInvitation = async (invitationId) => {
  try {
    await api.delete(`/merchant/team/invitations/${invitationId}`)
    showNotify('Convite cancelado.')
    await fetchTeam()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Não foi possível cancelar.', 'error')
  }
}

onMounted(async () => {
  try {
    const user = await fetchCurrentUser({ force: true })
    canManageTeam.value = Boolean(user?.permissions?.can_manage_team)

    if (!canManageTeam.value) {
      router.push('/dashboard')
      return
    }

    await fetchTeam()
  } catch {
    router.push('/login')
  }
})
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <PageHeader
        eyebrow="Equipe"
        :title="'Funcionários da loja'"
        :subtitle="storeName ? `Gerencie quem opera ${storeName} no dia a dia.` : 'Convide ou crie acessos para sua equipe.'"
      >
        <template #icon>
          <Users size="22" />
        </template>
      </PageHeader>

      <div v-if="loading" class="pm-loading">
        <Loader2 class="animate-spin" size="40" />
      </div>

      <template v-else>
        <section class="pm-card">
          <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 class="font-black text-lg text-slate-900">Equipe ativa</h2>
              <p class="text-xs font-bold text-slate-400 mt-1">
                {{ storeName ? `Acessos vinculados a ${storeName}` : 'Funcionários com login no painel' }}
              </p>
            </div>
            <div v-if="teamLimits" class="text-right">
              <p class="text-sm font-black text-slate-900">
                {{ teamLimits.current_members }}<span class="text-slate-300">/{{ teamLimits.max_team_members ?? '∞' }}</span>
              </p>
              <p class="text-[10px] font-black uppercase text-slate-400">funcionários</p>
            </div>
          </div>

          <div v-if="members.length === 0" class="p-8 text-center text-sm font-bold text-slate-400">
            Nenhum funcionário cadastrado. Adicione abaixo ou envie um convite.
          </div>

          <div v-else class="divide-y divide-slate-100">
            <div v-for="member in members" :key="member.id" class="p-4 flex items-center justify-between gap-4">
              <div>
                <p class="font-black text-slate-900">{{ member.user.name }}</p>
                <p class="text-xs font-bold text-slate-400">{{ member.user.email }}</p>
              </div>
              <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-[10px] font-black uppercase text-slate-600">
                  <Shield size="12" />
                  {{ roleLabels[member.role] || member.role }}
                </span>
                <button
                  type="button"
                  class="rounded-xl border border-red-100 p-2 text-red-500 hover:bg-red-50"
                  title="Remover acesso"
                  @click="removeMember(member.id)"
                >
                  <Trash2 size="16" />
                </button>
              </div>
            </div>
          </div>
        </section>

        <section v-if="invitations.length" class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="p-6 border-b border-slate-100">
            <h2 class="font-black text-lg text-slate-900">Convites pendentes</h2>
          </div>

          <div class="divide-y divide-slate-100">
            <div v-for="invitation in invitations" :key="invitation.id" class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <p class="font-black text-slate-900">{{ invitation.email }}</p>
                <p class="text-xs font-bold text-slate-400">{{ roleLabels[invitation.role] || invitation.role }}</p>
              </div>
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black text-slate-600 hover:border-red-200 hover:text-red-600"
                  @click="copyInviteLink(invitation.invite_url)"
                >
                  <Copy size="14" />
                  Copiar link
                </button>
                <button
                  type="button"
                  class="rounded-xl border border-red-100 p-2 text-red-500 hover:bg-red-50"
                  @click="cancelInvitation(invitation.id)"
                >
                  <Trash2 size="16" />
                </button>
              </div>
            </div>
          </div>
        </section>

        <section class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
          <button
            type="button"
            class="w-full flex items-center justify-between gap-3 text-left"
            @click="showAddForms = !showAddForms"
          >
            <div>
              <h2 class="font-black text-lg text-slate-900">Adicionar funcionário</h2>
              <p class="text-xs font-bold text-slate-400 mt-1">Crie login direto ou envie convite por e-mail</p>
            </div>
            <UserPlus size="20" class="text-red-600 flex-shrink-0" />
          </button>

          <div v-if="showAddForms" class="grid grid-cols-1 xl:grid-cols-2 gap-6 mt-6 pt-6 border-t border-slate-100">
            <div>
              <h3 class="font-black text-sm text-slate-800 mb-4">Criar acesso com senha</h3>
              <form class="space-y-4" @submit.prevent="createMember">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Nome</span>
                  <input v-model="createForm.name" required type="text" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">E-mail</span>
                  <input v-model="createForm.email" required type="email" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Senha inicial</span>
                  <input v-model="createForm.password" required minlength="8" type="password" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Função</span>
                  <select v-model="createForm.role" class="pm-select w-full">
                    <option value="staff">Operação</option>
                    <option value="manager">Gerente</option>
                  </select>
                </label>
                <button type="submit" :disabled="saving || teamLimits?.can_add_member === false" class="w-full rounded-2xl bg-red-500 py-3 text-sm font-black text-white hover:bg-red-600 transition disabled:opacity-60">
                  {{ saving ? 'Criando...' : 'Criar funcionário' }}
                </button>
              </form>
            </div>

            <div>
              <h3 class="font-black text-sm text-slate-800 mb-4">Convidar por e-mail</h3>
              <form class="space-y-4" @submit.prevent="sendInvite">
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">E-mail</span>
                  <input v-model="inviteForm.email" required type="email" class="pm-input-sm" />
                </label>
                <label class="block space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Função</span>
                  <select v-model="inviteForm.role" class="pm-select w-full">
                    <option value="staff">Operação</option>
                    <option value="manager">Gerente</option>
                  </select>
                </label>
                <button type="submit" :disabled="inviting || teamLimits?.can_add_member === false" class="w-full rounded-2xl border border-red-200 bg-red-50 py-3 text-sm font-black text-red-600 hover:bg-red-100 transition disabled:opacity-60">
                  {{ inviting ? 'Gerando...' : 'Gerar convite' }}
                </button>
              </form>
            </div>
          </div>

          <p v-if="teamLimits && !teamLimits.can_add_member" class="mt-4 text-sm font-bold text-amber-600">
            Limite de funcionários atingido no plano Premium.
          </p>
        </section>
      </template>
    </div>
</template>
