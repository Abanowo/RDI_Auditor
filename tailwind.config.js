// tailwind.config.js
module.exports = {
  purge: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
  ],
  darkMode: false, // or 'media' or 'class'
  theme: {
    extend: { // Usamos 'extend' para AÑADIR colores, no para reemplazar los de Tailwind
      colors: {
        'theme-dark': '#3d4852',       // Azul/Gris oscuro para el navbar
        'theme-secondary': '#6574cd',  // Azul/Púrpura para botones secundarios
        'theme-primary': '#f6993f',    // Naranja/Dorado para botones y acentos principales
        'theme-light': '#f8fafc',      // Gris muy claro para el fondo del contenido
        'theme-error': '#e3342f',      // Rojo para notificaciones de error
      },
    },
  },
  plugins: [],
  variants: {
    extend: {},
  },
  plugins: [],
}
