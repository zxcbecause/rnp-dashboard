# Production-like build, used only to verify that `nuxt build` succeeds (make fe-build-image).
# Local development runs `nuxt dev` from docker-compose.yml instead.
FROM node:24-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN unset http_proxy HTTP_PROXY https_proxy HTTPS_PROXY && npm ci --ignore-scripts
COPY . .
RUN npx nuxi prepare && npm run build

FROM node:24-alpine
WORKDIR /app
COPY --from=builder /app/.output .
EXPOSE 3000
CMD ["node", "server/index.mjs"]
