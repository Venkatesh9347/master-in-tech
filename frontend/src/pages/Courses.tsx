import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import CourseCard from '../components/courses/CourseCard'
import Pagination from '../components/Pagination'
import PublicAccessGateModal from '../components/PublicAccessGateModal'
import { usePagedQuery } from '../hooks/usePagedQuery'
import type { Course } from '../types/course'

const difficultyOptions = ['All Levels', 'Basic', 'Intermediate', 'Advanced']

/** B16: server-side page size for the public catalog (matches the API default). */
const CATALOG_PER_PAGE = 12

export default function Courses() {
  const [searchParams, setSearchParams] = useSearchParams()
  const initialSearch = searchParams.get('search') || ''
  const initialCategory = searchParams.get('category') || 'All'
  const initialLevel = searchParams.get('level') || searchParams.get('difficulty') || 'All Levels'

  const [dbCategories, setDbCategories] = useState<{ category: string; count: number }[]>([])

  // Filters State
  const [search, setSearch] = useState(initialSearch)
  const [selectedCategory, setSelectedCategory] = useState(initialCategory)
  const [selectedDifficulty, setSelectedDifficulty] = useState(initialLevel)

  // Lead Generation Enquiry Modal
  const [enquiryOpen, setEnquiryOpen] = useState(false)
  const [enquiryCourse, setEnquiryCourse] = useState<Course | null>(null)

  // B16: the public catalog is server-driven. `/public/courses` paginates
  // (default 12, max 24) and applies the same search/category/level filters
  // server-side, so the page no longer downloads the entire catalog at once.
  // usePagedQuery resets to page 1 whenever a filter changes and recovers from
  // an out-of-range page.
  const {
    items: courses,
    meta: pageMeta,
    loading,
    error,
    page,
    setPage,
  } = usePagedQuery<Course>(
    '/public/courses',
    {
      search: search || undefined,
      category: selectedCategory !== 'All' ? selectedCategory : undefined,
      difficulty:
        selectedDifficulty !== 'All' && selectedDifficulty !== 'All Levels'
          ? selectedDifficulty
          : undefined,
    },
    { perPage: CATALOG_PER_PAGE, errorMessage: 'Failed to load courses from the platform.' },
  )

  // Category dropdown options come from the dedicated categories endpoint, so
  // they are stable regardless of which catalog page is currently loaded.
  useEffect(() => {
    API.get('/course-categories')
      .then((res) => {
        if (Array.isArray(res?.data) && res.data.length > 0) {
          setDbCategories(res.data)
        }
      })
      .catch(() => {
        /* Non-fatal: the dropdown falls back to whatever the page contains. */
      })
  }, [])

  // Derive dynamic category list from database
  const categoryOptions = useMemo(() => {
    const set = new Set<string>()
    dbCategories.forEach((c) => {
      if (c.category) set.add(c.category)
    })
    courses.forEach((c) => {
      if (c.category) set.add(c.category)
    })
    return ['All', ...Array.from(set).sort()]
  }, [dbCategories, courses])

  // Sync category, level, search or page if the URL changes.
  // B16-J: this page already keeps filter state in the query string, so the
  // page number is added to that same pattern rather than new global state.
  useEffect(() => {
    const urlCategory = searchParams.get('category')
    const urlSearch = searchParams.get('search')
    const urlLevel = searchParams.get('level') || searchParams.get('difficulty')
    if (urlCategory) setSelectedCategory(urlCategory)
    if (urlSearch !== null) setSearch(urlSearch)
    if (urlLevel) setSelectedDifficulty(urlLevel)

    const urlPage = Number(searchParams.get('page'))
    if (Number.isFinite(urlPage) && urlPage > 1) setPage(urlPage)
  }, [searchParams, setPage])

  // Reflect the current page back into the query string so a page is
  // shareable/bookmarkable. Page 1 stays out of the URL for readability, and a
  // stale `?page=` must be dropped whenever the page falls back to 1 (a filter
  // change, or a page that no longer exists), otherwise the URL would keep
  // advertising a page the visitor is not on.
  useEffect(() => {
    const next = new URLSearchParams(searchParams)
    if (page <= 1) next.delete('page')
    else next.set('page', String(page))
    if (next.toString() === searchParams.toString()) return
    setSearchParams(next, { replace: true })
  }, [page, searchParams, setSearchParams])

  const clearAllFilters = () => {
    setSearch('')
    setSelectedCategory('All')
    setSelectedDifficulty('All Levels')
    setSearchParams({})
  }

  const handleOpenEnquiry = (course?: Course) => {
    setEnquiryCourse(course || null)
    setEnquiryOpen(true)
  }

  // B16: filtering and ordering are performed server-side by
  // `/public/courses`, so the page renders exactly the rows for the current
  // page. The former client-side filter/sort memo is intentionally gone:
  // re-applying it here would filter only the current page and silently hide
  // matching courses.
  const filteredCourses = courses

  const hasActiveFilters =
    search !== '' ||
    selectedCategory !== 'All' ||
    (selectedDifficulty !== 'All' && selectedDifficulty !== 'All Levels')

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar />

      {/* Header Banner */}
      <section className="bg-slate-900 text-white py-10 border-b border-slate-800">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="max-w-3xl">
            <span className="text-[11px] font-bold uppercase tracking-wider text-blue-400">
              Technology Learning Catalog
            </span>
            <h1 className="text-2xl sm:text-3xl font-black mt-1 text-white tracking-tight">
              Explore Course Catalog
            </h1>
            <p className="text-xs sm:text-sm text-slate-300 mt-1.5">
              Comprehensive curriculums from fundamentals to advanced enterprise architectures.
            </p>
          </div>
        </div>
      </section>

      {/* Search & Filter Toolbar */}
      <section className="bg-white border-b border-slate-200 sticky top-16 z-30 shadow-xs">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
            {/* Search Input */}
            <div className="relative flex-1 max-w-md">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search courses, skills, or instructors..."
                className="w-full pl-9 pr-3 py-1.5 rounded-lg text-xs bg-slate-50 border border-slate-300 focus:border-blue-500 focus:bg-white focus:outline-none transition"
              />
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
              {search && (
                <button
                  type="button"
                  onClick={() => setSearch('')}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs"
                >
                  ✕
                </button>
              )}
            </div>

            {/* Level Filter Pills */}
            <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0">
              {difficultyOptions.map((level) => (
                <button
                  key={level}
                  type="button"
                  onClick={() => setSelectedDifficulty(level)}
                  className={`px-3 py-1 rounded-lg text-xs font-semibold whitespace-nowrap transition border ${
                    selectedDifficulty === level
                      ? 'bg-blue-600 text-white border-blue-600 shadow-xs'
                      : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'
                  }`}
                >
                  {level}
                </button>
              ))}
            </div>
          </div>

          {/* Category Filter Pills Row */}
          <div className="flex items-center gap-1.5 overflow-x-auto pt-3 mt-2 border-t border-slate-100 no-scrollbar">
            {categoryOptions.map((cat) => (
              <button
                key={cat}
                type="button"
                onClick={() => setSelectedCategory(cat)}
                className={`px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap transition border ${
                  selectedCategory === cat
                    ? 'bg-slate-900 text-white border-slate-900 font-bold'
                    : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
                }`}
              >
                {cat}
              </button>
            ))}
          </div>
        </div>
      </section>

      {/* Main Catalog View */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow">
        {/* Results Metadata Bar */}
        <div className="flex items-center justify-between mb-6">
          <p className="text-xs text-slate-600 font-medium">
            {/* B16: report the authoritative filtered total, not just the rows
                on this page, so the catalog never implies only 12 courses exist. */}
            Showing{' '}
            <span className="font-bold text-slate-900">
              {pageMeta ? pageMeta.from ?? 0 : 0}–{pageMeta ? pageMeta.to ?? 0 : 0}
            </span>{' '}
            of <span className="font-bold text-slate-900">{pageMeta ? pageMeta.total : 0}</span> courses
            {selectedCategory !== 'All' && <span> in <span className="font-bold text-blue-600">{selectedCategory}</span></span>}
          </p>

          {hasActiveFilters && (
            <button
              type="button"
              onClick={clearAllFilters}
              className="text-xs text-blue-600 hover:text-blue-800 font-semibold"
            >
              Reset Filters
            </button>
          )}
        </div>

        {error && (
          <div className="p-4 mb-6 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs">
            {error}
          </div>
        )}

        {/* Loading Skeletons */}
        {loading ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {[1, 2, 3, 4, 5, 6].map((n) => (
              <div key={n} className="h-80 rounded-xl bg-white border border-slate-200 p-4 animate-pulse space-y-4">
                <div className="h-44 bg-slate-100 rounded-lg" />
                <div className="h-4 bg-slate-100 rounded w-3/4" />
                <div className="h-3 bg-slate-100 rounded w-1/2" />
              </div>
            ))}
          </div>
        ) : filteredCourses.length === 0 ? (
          <div className="text-center py-16 bg-white rounded-xl border border-slate-200 space-y-3">
            <span className="text-3xl">🔍</span>
            <h3 className="text-sm font-bold text-slate-900">No courses match your criteria</h3>
            <p className="text-xs text-slate-500 max-w-sm mx-auto">
              Try adjusting your search keywords or clearing active category filters.
            </p>
            <button
              type="button"
              onClick={clearAllFilters}
              className="px-4 py-2 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-500 transition"
            >
              Clear All Filters
            </button>
          </div>
        ) : (
          <>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {filteredCourses.map((course) => (
                <CourseCard
                  key={course.id}
                  course={course}
                  onEnquireClick={handleOpenEnquiry}
                />
              ))}
            </div>

            {/* B16: page-number navigation reusing the shared Pagination
                component; it is omitted by the component itself on a single
                page or an empty result. */}
            {pageMeta && <Pagination meta={pageMeta} onPageChange={setPage} label="Course catalog pages" />}
          </>
        )}
      </main>

      {/* Course Access Lead Gate Modal */}
      {enquiryOpen && (
        <PublicAccessGateModal
          isOpen={enquiryOpen}
          onClose={() => setEnquiryOpen(false)}
          courseId={enquiryCourse?.id}
          courseTitle={enquiryCourse?.title}
        />
      )}

      <Footer />
    </div>
  )
}
