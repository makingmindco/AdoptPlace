export async function lookupAddress(cep, request, options = {}) {
  try {
    return await request(`/address/cep/${encodeURIComponent(cep)}`, options)
  } catch (error) {
    if (error.name === 'AbortError' || (error.status && error.status < 500)) throw error
    // The browser has its own certificate store and can work when PHP cannot reach ViaCEP.
    const signal = options.signal
      ? AbortSignal.any([options.signal, AbortSignal.timeout(8000)])
      : AbortSignal.timeout(8000)
    const response = await fetch(`https://viacep.com.br/ws/${encodeURIComponent(cep)}/json/`, { signal, credentials: 'omit' })
    if (!response.ok) throw new Error('Não foi possível consultar o CEP. Preencha o endereço manualmente.', { cause: error })
    const data = await response.json()
    if (data.erro) throw new Error('CEP não encontrado. Confira os oito dígitos.', { cause: error })
    if (!data.localidade || !data.uf) throw new Error('O serviço retornou um endereço incompleto. Preencha manualmente.', { cause: error })
    return { cep, street: data.logradouro || '', neighborhood: data.bairro || '', city: data.localidade, state: data.uf }
  }
}
