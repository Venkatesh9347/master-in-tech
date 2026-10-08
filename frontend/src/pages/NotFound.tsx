import { Link } from 'react-router-dom';
import Navbar from '../components/Navbar';

export default function NotFound() {
  return (
    <div className="mit-stage min-h-screen">
      <Navbar />
      <div className="relative flex items-center justify-center px-6 py-24">
        <div className="text-center">
          <p className="mit-enter-scale mit-gradient-text text-7xl font-extrabold">404</p>
          <h1 className="mit-enter-rise mt-4 text-3xl font-bold text-white" style={{ animationDelay: '90ms' }}>
            Page Not Found
          </h1>
          <p className="mit-enter-rise mx-auto mt-3 max-w-md text-slate-300" style={{ animationDelay: '160ms' }}>
            The page you're looking for doesn't exist or may have been moved.
          </p>
          <div className="mit-enter-rise mt-8 flex flex-wrap justify-center gap-4" style={{ animationDelay: '230ms' }}>
            <Link
              to="/"
              className="mit-control rounded-lg bg-blue-600 px-6 py-3 font-semibold text-white hover:bg-blue-700"
            >
              Go to Home
            </Link>
            <Link
              to="/courses"
              className="mit-control rounded-lg border border-slate-600 bg-slate-900 px-6 py-3 font-semibold text-slate-200 hover:bg-slate-800"
            >
              Explore Courses
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}