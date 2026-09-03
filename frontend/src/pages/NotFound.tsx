import { Link } from 'react-router-dom';
import Navbar from '../components/Navbar';

export default function NotFound() {
  return (
    <div className="min-h-screen bg-slate-50">
      <Navbar />
      <div className="flex items-center justify-center px-6 py-24">
        <div className="text-center">
          <p className="text-7xl font-extrabold text-blue-600">404</p>
          <h1 className="mt-4 text-3xl font-bold text-slate-900">
            Page Not Found
          </h1>
          <p className="mt-3 text-slate-600 max-w-md mx-auto">
            The page you're looking for doesn't exist or may have been moved.
          </p>
          <div className="mt-8 flex flex-wrap gap-4 justify-center">
            <Link
              to="/"
              className="rounded-lg bg-blue-600 px-6 py-3 font-semibold text-white transition hover:bg-blue-700"
            >
              Go to Home
            </Link>
            <Link
              to="/courses"
              className="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Explore Courses
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}