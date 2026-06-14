import { ref, onMounted, onBeforeUnmount } from 'vue'

const MOBILE_MAX_WIDTH = 767

export function useIsMobileViewport() {
  const isMobileViewport = ref(false)

  let mediaQuery = null

  const update = () => {
    isMobileViewport.value = window.matchMedia(`(max-width: ${MOBILE_MAX_WIDTH}px)`).matches
  }

  onMounted(() => {
    mediaQuery = window.matchMedia(`(max-width: ${MOBILE_MAX_WIDTH}px)`)
    update()
    mediaQuery.addEventListener('change', update)
  })

  onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', update)
  })

  return { isMobileViewport }
}
