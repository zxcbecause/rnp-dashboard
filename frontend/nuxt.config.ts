import { defineNuxtConfig } from 'nuxt/config'

export default defineNuxtConfig({
  modules: [
    '@pinia/nuxt',
    '@nuxtjs/i18n',
    '@nuxt/eslint',
  ],

  // SPA: everything renders in the browser, Nitro only proxies /api/*.
  ssr: false,
  devtools: { enabled: false },

  app: {
    // Content-only transition: <NuxtPage> lives inside the layout, so sidebar and header do not move.
    pageTransition: { name: 'page', mode: 'out-in' },
    head: {
      title: 'РНП · Acme',
      htmlAttrs: { lang: 'ru' },
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
      link: [
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap' },
      ],
    },
  },

  css: [
    '~/assets/css/main.css',
    'primeicons/primeicons.css',
  ],

  runtimeConfig: {
    // Server-only: backend base URL used by the Nitro proxy. Never exposed to the browser.
    apiBase: 'http://rnp-nginx/api',
    cookieSecure: false,
    // Behind a reverse proxy (Caddy on the server) the client address comes from X-Forwarded-For; without one
    // the header is client-controlled and must be ignored. NUXT_TRUST_PROXY=true in production only.
    trustProxy: false,
  },

  // Spread object: the route rule types of this Nitro version omit `headers`,
  // although the runtime supports it (same workaround as the platform portal).
  ...{
    routeRules: {
      '/**': {
        headers: {
          'X-Content-Type-Options': 'nosniff',
          'X-Frame-Options': 'DENY',
          'Referrer-Policy': 'no-referrer',
        },
      },
    },
  },

  devServer: { host: '0.0.0.0', port: 3000 },

  // Windows bind mounts deliver no file events into the container: Nuxt's own watcher (new or removed
  // pages → routes) needs polling too, otherwise the route map keeps stale files and the app is a white screen.
  watchers: process.env.WATCH_POLLING === 'true'
    ? { chokidar: { usePolling: true, interval: 500 } }
    : {},
  compatibilityDate: '2026-06-18',

  vite: {
    // Pre-bundled up front: otherwise Vite discovers them on the first page load and reloads the page.
    optimizeDeps: {
      include: [
        '@primeuix/themes',
        '@primeuix/themes/aura',
        'primevue/config',
        'primevue/toast',
        'primevue/toastservice',
        'primevue/tooltip',
        'primevue/menu',
        'chart.js',
      ],
    },
    server: {
      // Bind mounts from a Windows host do not deliver file events into the container.
      watch: process.env.WATCH_POLLING === 'true' ? { usePolling: true, interval: 300 } : undefined,
    },
  },

  postcss: {
    plugins: {
      '@tailwindcss/postcss': {},
    },
  },

  // @ts-expect-error - @nuxt/eslint module options are not part of the generated config type
  eslint: {
    config: {
      stylistic: true,
    },
  },

  i18n: {
    locales: [
      { code: 'ru', language: 'ru-RU', name: 'Русский', file: 'ru.json' },
      { code: 'en', language: 'en-US', name: 'English', file: 'en.json' },
    ],
    defaultLocale: 'ru',
    langDir: 'locales/',
    strategy: 'no_prefix',
    detectBrowserLanguage: false,
  },
})
