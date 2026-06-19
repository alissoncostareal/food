export function getApiErrorMessage(error, fallback = 'Não foi possível completar a ação.') {
  const data = error?.response?.data || {}
  const status = error?.response?.status
  const validationErrors = data.errors

  if (validationErrors && typeof validationErrors === 'object') {
    for (const messages of Object.values(validationErrors)) {
      const message = Array.isArray(messages) ? messages[0] : messages

      if (typeof message === 'string' && message.trim() !== '') {
        return message
      }
    }
  }

  const message = typeof data.message === 'string' ? data.message.trim() : ''
  const details = typeof data.details === 'string' ? data.details.trim() : ''

  if (message && details) {
    return `${message} ${details}`
  }

  if (message) {
    return message
  }

  if (details) {
    return details
  }

  if (typeof data.error === 'string' && data.error.trim() !== '') {
    return data.error
  }

  if (status === 429) {
    return 'Muitas tentativas. Aguarde um momento e tente novamente.'
  }

  return fallback
}
