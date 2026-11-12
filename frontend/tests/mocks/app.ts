// Shim for the Nuxt "#app" virtual module in Vitest.
export const navigateTo = (..._args: unknown[]) => undefined
export const defineNuxtPlugin = <T>(plugin: T): T => plugin
export const defineNuxtRouteMiddleware = <T>(guard: T): T => guard
export const useRuntimeConfig = () => ({ public: {} })
export const useRouter = () => ({ push: () => undefined, replace: () => undefined })
export const useRoute = () => ({ params: {}, query: {}, path: '/' })
