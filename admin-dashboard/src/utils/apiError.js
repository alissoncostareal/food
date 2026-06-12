export function getApiErrorMessage(error, fallback = 'Não foi possível completar a ação.') {
  const data = error?.response?.data || {}
  const status = error?.response?.status

  if (typeof data.message === 'string' && data.message.trim() !== '') {
    return data.message
  }

  if (typeof data.error === 'string' && data.error.trim() !== '') {
    return data.error
  }

  if (status === 429) {
    return 'Muitas tentativas. Aguarde um momento e tente novamente.'
  }

  return fallback
}
