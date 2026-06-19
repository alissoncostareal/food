import api from '@/services/api'

export async function createCardToken(card) {
  const { data } = await api.post('/merchant/billing/pagarme/token', {
    number: card.number.replace(/\D/g, ''),
    holder_name: card.holder_name.trim(),
    holder_document: card.holder_document.replace(/\D/g, ''),
    exp_month: Number(card.exp_month),
    exp_year: Number(card.exp_year),
    cvv: card.cvv.replace(/\D/g, ''),
    billing_zip_code: card.billing_zip_code.replace(/\D/g, ''),
    billing_street: card.billing_street.trim(),
    billing_number: card.billing_number.trim(),
    billing_city: card.billing_city.trim(),
    billing_state: card.billing_state.trim().toUpperCase().slice(0, 2),
    billing_district: card.billing_district?.trim() || null,
    billing_complement: card.billing_complement?.trim() || null,
  })

  if (!data?.token) {
    throw new Error('Não foi possível tokenizar o cartão.')
  }

  return data.token
}
