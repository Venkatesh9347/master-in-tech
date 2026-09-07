# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# MasterInTech frontend (React SPA) - development image
#
# Build stage: installs from the lockfile, then runs the production Vite build
# with a mandatory VITE_API_URL build-arg (passing the browser-facing API
# origin). Runtime stage: nginx serves the static bundle with an SPA fallback
# so react-router deep links resolve to index.html, and proxies /api to the
# backend service.
#
# To keep the build working everywhere it must always receive VITE_API_URL.
# ---------------------------------------------------------------------------

FROM node:22-alpine AS build

WORKDIR /app

ARG VITE_API_URL

# Fail fast at build time when the caller forgot to provide the API origin.
RUN test -n "$VITE_API_URL" \
    || (echo "ERROR: VITE_API_URL build-arg is required (see docker-compose.yml and DEPLOYMENT.md)." && exit 1)

ENV VITE_API_URL=$VITE_API_URL

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ .
RUN npm run build

FROM nginx:alpine AS runtime

COPY deploy/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]