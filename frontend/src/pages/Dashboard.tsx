export default function Dashboard() {
  return (
    <div className="max-w-7xl mx-auto p-6">
      <div className="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-2xl p-8 text-white shadow-lg mb-8">
        <h1 className="text-3xl font-bold mb-2">Welcome Back, Student! 👋</h1>
        <p className="text-blue-100">Track your learning progress, view enrolled courses, and continue your tech journey.</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div className="bg-white p-6 rounded-xl shadow-md border border-gray-100">
          <h3 className="text-gray-500 text-sm font-medium">Enrolled Courses</h3>
          <p className="text-3xl font-bold text-gray-800 mt-2">2</p>
        </div>
        <div className="bg-white p-6 rounded-xl shadow-md border border-gray-100">
          <h3 className="text-gray-500 text-sm font-medium">Hours Learned</h3>
          <p className="text-3xl font-bold text-gray-800 mt-2">38.5</p>
        </div>
        <div className="bg-white p-6 rounded-xl shadow-md border border-gray-100">
          <h3 className="text-gray-500 text-sm font-medium">Certificates Earned</h3>
          <p className="text-3xl font-bold text-gray-800 mt-2">1</p>
        </div>
      </div>
    </div>
  )
}