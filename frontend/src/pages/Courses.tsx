export default function Courses() {
  return (
    <div className="max-w-7xl mx-auto p-6">
      <h1 className="text-3xl font-bold text-gray-800 mb-6">Explore Our Programs & Courses</h1>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-white p-6 rounded-xl shadow-md border border-gray-100">
          <h2 className="text-xl font-semibold text-blue-600 mb-2">Full Stack Development</h2>
          <p className="text-gray-600 mb-4">Master React, Laravel, and PostgreSQL from scratch.</p>
          <span className="inline-block bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-sm font-medium">6 Months</span>
        </div>
        <div className="bg-white p-6 rounded-xl shadow-md border border-gray-100">
          <h2 className="text-xl font-semibold text-blue-600 mb-2">Data Science & AI</h2>
          <p className="text-gray-600 mb-4">Learn Python, machine learning models, and data analytics.</p>
          <span className="inline-block bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-sm font-medium">6 Months</span>
        </div>
        <div className="bg-white p-6 rounded-xl shadow-md border border-gray-100">
          <h2 className="text-xl font-semibold text-blue-600 mb-2">Cloud & DevOps</h2>
          <p className="text-gray-600 mb-4">Understand Docker, CI/CD pipelines, and cloud deployment.</p>
          <span className="inline-block bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-sm font-medium">4 Months</span>
        </div>
      </div>
    </div>
  )
}