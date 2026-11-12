<template>
  <div class="grid min-h-screen lg:grid-cols-[1.15fr_1fr]">
    <!-- Brand side: dark monitor with the running pulse -->
    <aside class="login-monitor relative hidden overflow-hidden bg-rnp-sidebar px-12 py-10 text-white lg:flex lg:flex-col">
      <img
        src="/logo-light.svg"
        alt="Acme"
        class="rnp-rise relative h-9 w-auto self-start"
        :style="{ '--i': 0 }"
      >

      <div class="relative my-auto flex flex-col gap-8">
        <div
          class="rnp-rise flex flex-col gap-5"
          :style="{ '--i': 1 }"
        >
          <span class="inline-flex w-fit items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold tracking-wide text-rnp-sidebar-text">
            <span class="login-beat h-2 w-2 rounded-full bg-rnp-accent" />
            {{ t('auth.login.eyebrow') }}
          </span>
          <h1 class="text-5xl font-bold leading-[1.05] tracking-tight xl:text-6xl">
            {{ t('auth.login.titleStart') }}
            <span class="text-rnp-accent">{{ t('auth.login.titleAccent') }}</span>
          </h1>
          <p class="max-w-md text-base leading-relaxed text-rnp-sidebar-text">
            {{ t('auth.login.lead') }}
          </p>
        </div>

        <PulseLine
          class="rnp-rise -mx-12 w-[calc(100%+6rem)]"
          :style="{ '--i': 2 }"
          :label="t('auth.subtitle')"
        />

        <ul class="flex flex-wrap gap-2">
          <li
            v-for="(feature, index) in features"
            :key="feature.key"
            class="rnp-rise inline-flex items-center gap-2 rounded-full bg-white/[0.07] px-4 py-2 text-sm text-rnp-sidebar-text"
            :style="{ '--i': 3 + index }"
          >
            <i
              :class="feature.icon"
              class="text-xs text-rnp-accent"
              aria-hidden="true"
            />
            {{ t(`auth.login.features.${feature.key}`) }}
          </li>
        </ul>
      </div>

      <p class="relative text-xs text-white/40">
        © {{ year }} Acme
      </p>
    </aside>

    <!-- Form side: the same monitor, a glass card with the form -->
    <main class="login-panel relative flex items-center justify-center overflow-hidden bg-[#1a1917] px-4 py-10 sm:px-8">
      <!-- Divider between the halves with an impulse running down it -->
      <span
        class="login-divider absolute inset-y-0 left-0 hidden w-px lg:block"
        aria-hidden="true"
      />

      <div class="relative w-full max-w-sm">
        <div class="mb-8 lg:hidden">
          <img
            src="/logo-light.svg"
            alt="Acme"
            class="h-8 w-auto"
          >
          <PulseLine
            class="mt-4 -mx-4 w-[calc(100%+2rem)]"
            :label="t('auth.subtitle')"
          />
        </div>

        <div
          class="login-card rnp-rise relative rounded-3xl p-8"
          :style="{ '--i': 2 }"
        >
          <div class="mb-7 flex items-center gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rnp-accent shadow-[0_0_24px_rgb(238_197_43/0.35)]">
              <!-- Mini pulse glyph -->
              <svg
                viewBox="0 0 24 24"
                class="h-6 w-6"
                fill="none"
                stroke="#1a1a1a"
                stroke-width="2.2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path d="M2 12h4l2-4 3 9 3-12 2.5 7H22" />
              </svg>
            </span>
            <div>
              <h2 class="text-2xl font-bold text-white">
                {{ t('auth.login.heading') }}
              </h2>
              <p class="text-sm text-white/55">
                {{ t('auth.login.hint') }}
              </p>
            </div>
          </div>

          <div class="login-form">
            <LoginForm />
          </div>

          <div class="mt-7 flex items-center gap-2 border-t border-white/10 pt-5 text-xs text-white/50">
            <span class="login-live h-1.5 w-1.5 shrink-0 rounded-full bg-rnp-green" />
            {{ t('auth.login.liveNote') }}
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { definePageMeta } from '#imports'
import LoginForm from '~/features/auth/components/LoginForm.vue'
import PulseLine from '~/features/auth/components/PulseLine.vue'

// Leaving it swaps the layout: an out-in page transition across layouts never completes in Nuxt.
definePageMeta({ layout: 'auth', pageTransition: false })

const { t } = useI18n()
const year = new Date().getFullYear()

const features = [
  { key: 'planFact', icon: 'pi pi-chart-line' },
  { key: 'sources', icon: 'pi pi-sync' },
  { key: 'actions', icon: 'pi pi-check-circle' },
]
</script>

<style scoped>
/* Monitor backdrop: a faint grid and a warm glow behind the pulse. */
.login-monitor::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgb(255 255 255 / 0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgb(255 255 255 / 0.04) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: radial-gradient(ellipse at 50% 55%, black 30%, transparent 75%);
}

.login-monitor::after {
  content: '';
  position: absolute;
  left: 10%;
  right: 10%;
  top: 45%;
  height: 40%;
  background: radial-gradient(ellipse at center, rgb(238 197 43 / 0.16), transparent 70%);
  pointer-events: none;
}

/* Content above the backdrop layers. */
.login-monitor::before,
.login-monitor::after {
  z-index: 0;
}

.login-monitor > * {
  z-index: 1;
}

/* Form side: the grid continues around the card, with a warm glow behind it. */
.login-panel::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgb(255 255 255 / 0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgb(255 255 255 / 0.035) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: radial-gradient(ellipse at 50% 50%, black 20%, transparent 70%);
}

.login-panel::after {
  content: '';
  position: absolute;
  width: 34rem;
  height: 34rem;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle, rgb(238 197 43 / 0.13), transparent 62%);
  pointer-events: none;
}

.login-panel > * {
  z-index: 1;
}

/* Glass card: translucent, hairline border, a yellow highlight along the top edge. */
.login-card {
  background: linear-gradient(180deg, rgb(255 255 255 / 0.07), rgb(255 255 255 / 0.03));
  border: 1px solid rgb(255 255 255 / 0.1);
  box-shadow: 0 30px 80px rgb(0 0 0 / 0.45);
  backdrop-filter: blur(14px);
}

.login-card::before {
  content: '';
  position: absolute;
  top: -1px;
  left: 18%;
  right: 18%;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--rnp-accent), transparent);
}

/* Vertical divider with an impulse travelling down. */
.login-divider {
  background: linear-gradient(180deg, transparent, rgb(255 255 255 / 0.1) 20%, rgb(255 255 255 / 0.1) 80%, transparent);
}

.login-divider::after {
  content: '';
  position: absolute;
  left: -1px;
  width: 3px;
  height: 90px;
  border-radius: 3px;
  background: linear-gradient(180deg, transparent, var(--rnp-accent));
  box-shadow: 0 0 12px var(--rnp-accent);
  animation: login-impulse 4.2s linear infinite;
}

@keyframes login-impulse {
  from { transform: translateY(-100px); opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  to { transform: translateY(100vh); opacity: 0; }
}

/* Form controls in the dark theme of the page. */
.login-form :deep(label) {
  color: rgb(255 255 255 / 0.7);
}

.login-form :deep(.p-inputtext) {
  height: 2.875rem;
  background: rgb(255 255 255 / 0.06);
  border-color: rgb(255 255 255 / 0.12);
  color: #fff;
}

.login-form :deep(.p-inputtext:enabled:hover) {
  border-color: rgb(255 255 255 / 0.25);
}

.login-form :deep(.p-inputtext:enabled:focus) {
  border-color: var(--rnp-accent);
  box-shadow: 0 0 0 3px rgb(238 197 43 / 0.2);
}

.login-form :deep(.p-inputtext.p-invalid) {
  border-color: rgb(248 113 113 / 0.8);
}

.login-form :deep(input:-webkit-autofill) {
  -webkit-text-fill-color: #fff;
  caret-color: #fff;
  box-shadow: 0 0 0 1000px #2b2925 inset;
}

.login-form :deep(.p-password-toggle-mask-icon),
.login-form :deep(.p-password .p-icon) {
  color: rgb(255 255 255 / 0.5);
}

.login-form :deep(.p-button) {
  height: 2.875rem;
  margin-top: 0.25rem;
  font-weight: 700;
  box-shadow: 0 8px 24px rgb(238 197 43 / 0.25);
}

/* Heartbeat of the eyebrow dot: a double beat, then rest. */
@keyframes login-beat {
  0%, 40%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgb(238 197 43 / 0.6); }
  10% { transform: scale(1.35); }
  20% { transform: scale(1); }
  30% { transform: scale(1.25); box-shadow: 0 0 0 8px rgb(238 197 43 / 0); }
}

.login-beat {
  animation: login-beat 1.4s ease-out infinite;
}

/* "Live" dot of the data note: a calm green ripple. */
@keyframes login-live {
  0% { box-shadow: 0 0 0 0 rgb(22 163 74 / 0.6); }
  70%, 100% { box-shadow: 0 0 0 6px rgb(22 163 74 / 0); }
}

.login-live {
  animation: login-live 2s ease-out infinite;
}
</style>
