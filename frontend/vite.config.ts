import { loadEnv } from 'vite'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Load VITE_* variables from .env files (Vite injects them into the client
  // bundle at build time). `process.env.VITE_API_URL` is honoured as well so a
  // CI runner can pass it without creating a .env.production file.
  const env = loadEnv(mode, process.cwd(), '')
  const apiUrl = env.VITE_API_URL || process.env.VITE_API_URL

  // Production bundles MUST carry an explicit API origin. A silent fallback to
  // http://127.0.0.1:8001 would be baked into the minified JS and pointed the
  // deployed site at someone's local machine. Fail the build instead.
  if (mode === 'production' && !apiUrl) {
    throw new Error(
      'VITE_API_URL is required for production builds. ' +
        'Set it to the HTTPS API origin in frontend/.env.production (e.g. ' +
        'VITE_API_URL=https://api.yourdomain.com/api) or pass VITE_API_URL in ' +
        'the environment. See frontend/.env.example and DEPLOYMENT.md.',
    )
  }

  return {
    plugins: [
      react(),
      tailwindcss(),
    ],
  }
})
