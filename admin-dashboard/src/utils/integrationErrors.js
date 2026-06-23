export const integrationErrorNotifyMessage = (error, fallback = 'Erro na integração.') => {
  if (error?.code === 'ECONNABORTED' || String(error?.message || '').toLowerCase().includes('timeout')) {
    return 'A Evolution está demorando para responder (pode estar iniciando). Aguarde e tente novamente.'
  }

  const data = error?.response?.data || {}
  const message = data.message || fallback

  if (data.error_ref) {
    return `${message} (código: ${data.error_ref})`
  }

  return message
}
