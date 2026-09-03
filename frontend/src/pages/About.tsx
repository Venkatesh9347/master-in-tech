import { Link } from 'react-router-dom'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'

export default function About() {
  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      {/* Header Banner */}
      <section className="bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-white py-16 border-b border-slate-800 text-center">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          <span className="text-xs font-extrabold uppercase tracking-widest text-blue-400">
            Our Mission & Story
          </span>
          <h1 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-2">
            Transforming Tech Careers Worldwide
          </h1>
          <p className="text-sm sm:text-base text-slate-300 mt-3 max-w-2xl mx-auto font-normal leading-relaxed">
            Bridging the gap between academic theory and real-world engineering excellence through mentor-led technical mastery.
          </p>
        </div>
      </section>

      <main className="flex-grow py-16 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 w-full space-y-12">
        {/* Story Card */}
        <div className="bg-white rounded-3xl p-8 sm:p-12 border border-slate-200/80 shadow-xs">
          <h2 className="text-2xl font-black text-slate-900 mb-4">
            The Master In Tech Vision
          </h2>
          <p className="text-sm text-slate-600 leading-relaxed mb-4">
            Master In Tech was founded on a straightforward principle: tech education should be practical, rigorous, and directly applicable to production systems. We believe every aspiring technologist deserves mentorship from engineers who have built high-scale systems themselves.
          </p>
          <p className="text-sm text-slate-600 leading-relaxed">
            Today, our platform trains tens of thousands of professionals in Full Stack Development, AI & Machine Learning, Cloud DevOps, Data Science, and Enterprise ERP, enabling career transitions into Fortune 500 tech teams.
          </p>
        </div>

        {/* Core Pillars */}
        <div className="grid gap-6 sm:grid-cols-3">
          <div className="bg-white p-7 rounded-3xl border border-slate-200/80 shadow-xs">
            <span className="text-3xl mb-3 block">🎯</span>
            <h3 className="text-base font-bold text-slate-900 mb-2">Outcome-Driven</h3>
            <p className="text-xs text-slate-600 leading-relaxed">
              Every lesson, quiz, and capstone project is mapped to the exact competency benchmarks demanded by hiring managers.
            </p>
          </div>

          <div className="bg-white p-7 rounded-3xl border border-slate-200/80 shadow-xs">
            <span className="text-3xl mb-3 block">🤝</span>
            <h3 className="text-base font-bold text-slate-900 mb-2">Human Mentorship</h3>
            <p className="text-xs text-slate-600 leading-relaxed">
              1-on-1 code reviews, weekly interactive workshops, and dedicated career guidance to ensure no learner gets stuck.
            </p>
          </div>

          <div className="bg-white p-7 rounded-3xl border border-slate-200/80 shadow-xs">
            <span className="text-3xl mb-3 block">🏆</span>
            <h3 className="text-base font-bold text-slate-900 mb-2">Accredited Rigor</h3>
            <p className="text-xs text-slate-600 leading-relaxed">
              Tamper-proof verifiable credentials backed by real codebase submissions and automated assessment benchmarks.
            </p>
          </div>
        </div>

        {/* Numbers Row */}
        <div className="bg-slate-900 text-white rounded-3xl p-8 sm:p-10 shadow-xl grid grid-cols-2 sm:grid-cols-4 gap-6 text-center">
          <div>
            <p className="text-3xl sm:text-4xl font-black text-blue-400">50K+</p>
            <p className="text-xs text-slate-400 mt-1 font-medium">Graduates Placed</p>
          </div>
          <div>
            <p className="text-3xl sm:text-4xl font-black text-amber-400">4.9 ★</p>
            <p className="text-xs text-slate-400 mt-1 font-medium">Average Rating</p>
          </div>
          <div>
            <p className="text-3xl sm:text-4xl font-black text-emerald-400">100+</p>
            <p className="text-xs text-slate-400 mt-1 font-medium">Hiring Partners</p>
          </div>
          <div>
            <p className="text-3xl sm:text-4xl font-black text-purple-400">15+</p>
            <p className="text-xs text-slate-400 mt-1 font-medium">Industry Bootcamps</p>
          </div>
        </div>

        {/* CTA */}
        <div className="text-center pt-4">
          <Link
            to="/courses"
            className="inline-block px-8 py-3.5 rounded-xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-700 transition shadow-md"
          >
            Explore Our Learning Programs →
          </Link>
        </div>
      </main>

      <Footer />
    </div>
  )
}
