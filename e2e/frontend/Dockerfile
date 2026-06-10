# syntax=docker/dockerfile:1
#
# e2e demo frontend: build the committed SPA (which already imports the generated
# openapi-zod-ts client from src/api) and serve the static bundle via nginx.
#
# VITE_API_BASE is a BUILD-time value: Vite inlines import.meta.env into the
# bundle. It must be the URL the user's BROWSER will reach, i.e. the host-mapped
# backend port (http://localhost:8088/api), NOT the internal compose service
# name, because the fetch runs in the browser, not inside this container.

FROM node:20-alpine AS build

# Bake the backend URL the browser will call. Overridable via --build-arg.
ARG VITE_API_BASE=http://localhost:8088/api
ENV VITE_API_BASE=${VITE_API_BASE}

WORKDIR /app

# Pin pnpm explicitly. corepack's default pin (11.x) currently fatals on this
# node image (ERR_UNKNOWN_BUILTIN_MODULE); pnpm 9 reads the v9.0 lockfile fine.
RUN npm install -g pnpm@9

# Install deps first for layer caching. The committed pnpm-lock.yaml pins
# everything (including openapi-zod-ts, used only at gen time, not needed here).
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

# App source incl. the committed generated client in src/api. No generation runs:
# `pnpm build` just typechecks and bundles the already-generated code.
COPY . .
RUN pnpm build

# Serve the static dist/ with nginx.
FROM nginx:1.27-alpine AS serve
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
