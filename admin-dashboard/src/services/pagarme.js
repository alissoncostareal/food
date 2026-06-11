import api from '@/services/api'

export async function createCardToken(card) {
  const { data } = await api.post('/merchant/billing/pagarme/token', {
    number: card.number.replace(/\D/g, ''),
    holder_name: card.holder_name.trim(),
    holder_document: card.holder_document.replace(/\D/g, ''),
    exp_month: Number(card.exp_month),
    exp_year: Number(card.exp_year),
    cvv: card.cvv.replace(/\D/g, ''),
  })

  if (!data?.token) {
    throw new Error('Não foi possível tokenizar o cartão.')
  }

  return data.token
}
