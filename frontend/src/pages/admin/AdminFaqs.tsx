import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

interface FaqItem {
  id: number
  category: string
  question: string
  answer: string
  display_order: number
  is_published: boolean
}

export default function AdminFaqs() {
  const [faqs, setFaqs] = useState<FaqItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingFaq, setEditingFaq] = useState<FaqItem | null>(null)

  // Form State
  const [category, setCategory] = useState('Programs & Learning Experience')
  const [question, setQuestion] = useState('')
  const [answer, setAnswer] = useState('')
  const [displayOrder, setDisplayOrder] = useState(0)
  const [isPublished, setIsPublished] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = useCallback(() => {
    setLoading(true)
    API.get<FaqItem[]>('/admin/faqs')
      .then((res) => setFaqs(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load FAQs.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const openCreateModal = () => {
    setEditingFaq(null)
    setCategory('Programs & Learning Experience')
    setQuestion('')
    setAnswer('')
    setDisplayOrder(faqs.length + 1)
    setIsPublished(true)
    setShowModal(true)
  }

  const openEditModal = (item: FaqItem) => {
    setEditingFaq(item)
    setCategory(item.category)
    setQuestion(item.question)
    setAnswer(item.answer)
    setDisplayOrder(item.display_order)
    setIsPublished(item.is_published)
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      category: category.trim(),
      question: question.trim(),
      answer: answer.trim(),
      display_order: Number(displayOrder),
      is_published: isPublished,
    }

    try {
      if (editingFaq) {
        await API.put(`/admin/faqs/${editingFaq.id}`, payload)
        setSuccessMsg('FAQ updated successfully!')
      } else {
        await API.post('/admin/faqs', payload)
        setSuccessMsg('FAQ created successfully!')
      }
      setShowModal(false)
      loadData()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save FAQ.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (item: FaqItem) => {
    if (!window.confirm(`Delete question '${item.question}'?`)) return

    try {
      await API.delete(`/admin/faqs/${item.id}`)
      setSuccessMsg('FAQ deleted.')
      loadData()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to delete FAQ.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Frequently Asked Questions (FAQ)</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Manage admissions questions, certification details, and curriculum FAQs.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Add FAQ
        </button>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold">
          ✓ {successMsg}
        </div>
      )}

      {errorMsg && (
        <div className="p-4 bg-red-950/80 border border-red-800 text-red-300 rounded-2xl text-xs font-bold">
          ⚠️ {errorMsg}
        </div>
      )}

      {/* List */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
        {loading ? (
          <p className="text-xs text-slate-500 py-8 text-center">Loading FAQs...</p>
        ) : faqs.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">❓</span>
            <p className="font-bold text-slate-300">No FAQs found</p>
          </div>
        ) : (
          <div className="space-y-4">
            {faqs.map((f, idx) => (
              <div
                key={f.id}
                className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-2 hover:border-slate-700 transition"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="space-y-1">
                    <span className="px-2 py-0.5 rounded-md bg-slate-950 text-purple-300 border border-slate-800 text-[10px] font-bold">
                      {f.category}
                    </span>
                    <h3 className="font-bold text-white text-sm mt-1">
                      #{idx + 1}. {f.question}
                    </h3>
                  </div>

                  <div className="flex items-center gap-2 shrink-0">
                    <button
                      type="button"
                      onClick={() => openEditModal(f)}
                      className="px-2.5 py-1 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(f)}
                      className="px-2.5 py-1 rounded-lg bg-red-950/60 text-red-300 hover:bg-red-900 text-xs font-bold transition"
                    >
                      Delete
                    </button>
                  </div>
                </div>

                <p className="text-xs text-slate-300 leading-relaxed pt-1 border-t border-slate-800/60">
                  {f.answer}
                </p>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* MODAL */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                FAQ Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingFaq ? 'Edit Question' : 'Add FAQ'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Category Topic <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={category}
                  onChange={(e) => setCategory(e.target.value)}
                  placeholder="Programs & Learning Experience"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Question <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={question}
                  onChange={(e) => setQuestion(e.target.value)}
                  placeholder="e.g. How are Master In Tech programs structured?"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Answer <span className="text-red-400">*</span>
                </label>
                <textarea
                  value={answer}
                  onChange={(e) => setAnswer(e.target.value)}
                  rows={4}
                  placeholder="Detailed answer explanation..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-md transition disabled:opacity-50"
                >
                  {saving ? 'Saving...' : 'Save FAQ'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
