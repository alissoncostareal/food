/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        store: {
          primary: 'var(--store-primary)',
          secondary: 'var(--store-secondary)',
        },
      },
    },
  },
  plugins: [],
}
