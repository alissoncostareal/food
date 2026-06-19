export function getApiErrorMessage(error, fallback = 'Não foi possível completar a ação.') {
  const data = error?.response?.data || {}
  const status = error?.response?.status
  const validationErrors = data.errors

  if (validationErrors && typeof validationErrors === 'object') {
    for (const messages of Object.values(validationErrors)) {
      const message = Array.isArray(messages) ? messages[0] : messages

      if (typeof message === 'string' && message.trim() !== '') {
        if (message.includes('uploaded') || message.includes('failed to upload')) {
          return 'Não foi possível enviar a imagem. Tente outro arquivo (JPG, PNG ou WebP, até 10 MB) ou salve sem foto.'
        }

        return message
      }
    }
  }

  if (typeof data.message === 'string' && data.message.trim() !== '') {
    if (typeof data.details === 'string' && data.details.trim() !== '') {
      return `${data.message} ${data.details}`
    }

    return data.message
  }

  if (typeof data.error === 'string' && data.error.trim() !== '') {
    if (typeof data.details === 'string' && data.details.trim() !== '') {
      if (data.details.includes('upload') || data.details.includes('image')) {
        return 'Não foi possível enviar a imagem. Use JPG, PNG ou WebP (logo até 2 MB, banner até 4 MB).'
      }
    }

    return data.error
  }

  if (typeof data.details === 'string' && data.details.trim() !== '') {
    return data.details
  }

  if (status === 429) {
    return 'Muitas tentativas. Aguarde um momento e tente novamente.'
  }

  return fallback
}
