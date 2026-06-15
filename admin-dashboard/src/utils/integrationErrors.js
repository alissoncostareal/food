export const integrationErrorNotifyMessage = (error, fallback = 'Erro na integração.') => {
  const data = error?.response?.data || {}
  const message = data.message || fallback

  if (data.error_ref) {
    return `${message} (código: ${data.error_ref})`
  }

  return message
}
