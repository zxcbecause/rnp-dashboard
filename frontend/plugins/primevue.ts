import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import Tooltip from 'primevue/tooltip'
import Aura from '@primeuix/themes/aura'
import { definePreset } from '@primeuix/themes'
import { defineNuxtPlugin } from '#app'

/**
 * Acme preset on top of Aura: brand yellow (dark text on it), warm neutrals instead of Aura's slate,
 * large radii and pill-shaped controls, so PrimeVue components match the hand-made UI (see main.css tokens).
 */
const AcmePreset = definePreset(Aura, {
  primitive: {
    borderRadius: { none: '0', xs: '4px', sm: '8px', md: '12px', lg: '16px', xl: '20px' },
  },
  semantic: {
    primary: {
      50: '#fefdf5',
      100: '#fdf9e8',
      200: '#fbf3d1',
      300: '#f8eab0',
      400: '#f5e08e',
      500: '#eec52b',
      600: '#d4a824',
      700: '#a8841c',
      800: '#7d6215',
      900: '#52400e',
      950: '#2a2007',
    },
    focusRing: { width: '2px', style: 'solid', color: '{primary.400}', offset: '2px', shadow: 'none' },
    formField: {
      paddingX: '0.875rem',
      paddingY: '0.5rem',
      borderRadius: '12px',
    },
    overlay: {
      modal: { borderRadius: '20px', padding: '1.5rem' },
      popover: { borderRadius: '16px' },
      select: { borderRadius: '16px' },
    },
    colorScheme: {
      light: {
        primary: {
          color: '#eec52b',
          contrastColor: '#1a1a1a',
          hoverColor: '#d4a824',
          activeColor: '#d4a824',
        },
        // Warm neutral scale (Aura uses slate): 0 white pills, 50 cards, 100 page background, 950 sidebar.
        surface: {
          0: '#ffffff',
          50: '#fbf9f4',
          100: '#f6f2e9',
          200: '#ebe5d9',
          300: '#ddd5c6',
          400: '#b9b1a3',
          500: '#8a857c',
          600: '#6b665e',
          700: '#4d4943',
          800: '#2f2c28',
          900: '#1a1a1a',
          950: '#141414',
        },
        highlight: {
          background: '#fdf6dc',
          focusBackground: '#fbf3d1',
          color: '#1a1a1a',
          focusColor: '#1a1a1a',
        },
        formField: {
          background: '#ffffff',
          borderColor: '#ebe5d9',
          hoverBorderColor: '#d8cfbe',
          focusBorderColor: '#eec52b',
          color: '#1a1a1a',
          placeholderColor: '#8a857c',
          floatLabelColor: '#8a857c',
          iconColor: '#8a857c',
          shadow: 'none',
        },
        text: { color: '#1a1a1a', mutedColor: '#8a857c', hoverMutedColor: '#4d4943' },
        content: { background: '#fbf9f4', borderColor: '#ebe5d9', color: '#1a1a1a' },
        overlay: {
          modal: { background: '#fbf9f4', borderColor: '#ebe5d9', color: '#1a1a1a' },
          popover: { background: '#ffffff', borderColor: '#ebe5d9', color: '#1a1a1a' },
          select: { background: '#ffffff', borderColor: '#ebe5d9', color: '#1a1a1a' },
        },
      },
    },
  },
  components: {
    // Pill buttons, as in the reference.
    button: { root: { borderRadius: '999px' } },
    selectbutton: { root: { borderRadius: '999px' } },
    togglebutton: { root: { borderRadius: '999px' } },
    message: { root: { borderRadius: '14px' } },
    tag: { root: { borderRadius: '999px' } },
  },
})

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.use(PrimeVue, {
    theme: {
      preset: AcmePreset,
      options: { darkModeSelector: false },
    },
  })
  nuxtApp.vueApp.use(ToastService)
  nuxtApp.vueApp.directive('tooltip', Tooltip)
})
