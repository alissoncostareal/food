import { useState } from 'react'
import { Loader2 } from 'lucide-react'
import { submitLead } from '../api'

export default function LeadForm({ form }) {
  const [fields, setFields] = useState({
    name: '',
    email: '',
    phone: '',
    store_name: '',
    message: '',
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const updateField = (key) => (event) => {
    setFields((current) => ({ ...current, [key]: event.target.value }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    if (loading) return

    setLoading(true)
    setError('')
    setSuccess('')

    try {
      const response = await submitLead(fields)
      setSuccess(response.message || form.success_message)
      setFields({
        name: '',
        email: '',
        phone: '',
        store_name: '',
        message: '',
      })
    } catch (err) {
      setError(err.response?.data?.message || 'Não foi possível enviar. Tente novamente.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <form className="space-y-4" onSubmit={handleSubmit}>
      <div className="grid gap-4 sm:grid-cols-2">
        <label className="block">
          <span className="text-[11px] font-black uppercase tracking-wider text-slate-400">Nome</span>
          <input
            value={fields.name}
            onChange={updateField('name')}
            type="text"
            required
            className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold outline-none transition focus:border-red-500 focus:ring-2 focus:ring-red-100"
          />
        </label>

        <label className="block">
          <span className="text-[11px] font-black uppercase tracking-wider text-slate-400">E-mail</span>
          <input
            value={fields.email}
            onChange={updateField('email')}
            type="email"
            required
            className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold outline-none transition focus:border-red-500 focus:ring-2 focus:ring-red-100"
          />
        </label>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <label className="block">
          <span className="text-[11px] font-black uppercase tracking-wider text-slate-400">WhatsApp</span>
          <input
            value={fields.phone}
            onChange={updateField('phone')}
            type="tel"
            className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold outline-none transition focus:border-red-500 focus:ring-2 focus:ring-red-100"
          />
        </label>

        <label className="block">
          <span className="text-[11px] font-black uppercase tracking-wider text-slate-400">Nome da loja</span>
          <input
            value={fields.store_name}
            onChange={updateField('store_name')}
            type="text"
            className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold outline-none transition focus:border-red-500 focus:ring-2 focus:ring-red-100"
          />
        </label>
      </div>

      <label className="block">
        <span className="text-[11px] font-black uppercase tracking-wider text-slate-400">Mensagem</span>
        <textarea
          value={fields.message}
          onChange={updateField('message')}
          rows={3}
          className="mt-1.5 w-full resize-none rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold outline-none transition focus:border-red-500 focus:ring-2 focus:ring-red-100"
        />
      </label>

      {error ? (
        <p className="rounded-2xl bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{error}</p>
      ) : null}

      {success ? (
        <p className="rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">{success}</p>
      ) : null}

      <button
        type="submit"
        disabled={loading}
        className="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white shadow-lg shadow-red-500/20 transition hover:bg-red-700 disabled:opacity-60 sm:w-auto"
      >
        {loading ? <Loader2 className="animate-spin" size={18} /> : null}
        {form.button_text}
      </button>
    </form>
  )
}
