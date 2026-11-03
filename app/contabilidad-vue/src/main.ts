import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import Tooltip from 'primevue/tooltip'

import 'primeicons/primeicons.css'
import './style.css'

import App from './App.vue'
import router from './router'
import { instalarInterceptoresDeSesion } from './stores/auth'

// Textos de PrimeVue en español (calendario, listas vacías, filtros, contraseña, etc.).
// No hay paquete de idiomas instalado, por eso va aquí.
const localeEs = {
  startsWith: 'Empieza con',
  contains: 'Contiene',
  notContains: 'No contiene',
  endsWith: 'Termina con',
  equals: 'Es igual a',
  notEquals: 'No es igual a',
  noFilter: 'Sin filtro',
  lt: 'Menor que',
  lte: 'Menor o igual que',
  gt: 'Mayor que',
  gte: 'Mayor o igual que',
  dateIs: 'La fecha es',
  dateIsNot: 'La fecha no es',
  dateBefore: 'La fecha es anterior a',
  dateAfter: 'La fecha es posterior a',
  clear: 'Limpiar',
  apply: 'Aplicar',
  matchAll: 'Cumple todas',
  matchAny: 'Cumple alguna',
  addRule: 'Agregar regla',
  removeRule: 'Quitar regla',
  accept: 'Sí',
  reject: 'No',
  choose: 'Elegir',
  upload: 'Subir',
  cancel: 'Cancelar',
  completed: 'Completado',
  pending: 'Pendiente',
  fileSizeTypes: ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'],
  dayNames: ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
  dayNamesShort: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'],
  dayNamesMin: ['D', 'L', 'M', 'X', 'J', 'V', 'S'],
  monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
  monthNamesShort: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
  chooseYear: 'Elegir año',
  chooseMonth: 'Elegir mes',
  chooseDate: 'Elegir fecha',
  prevDecade: 'Década anterior',
  nextDecade: 'Década siguiente',
  prevYear: 'Año anterior',
  nextYear: 'Año siguiente',
  prevMonth: 'Mes anterior',
  nextMonth: 'Mes siguiente',
  prevHour: 'Hora anterior',
  nextHour: 'Hora siguiente',
  prevMinute: 'Minuto anterior',
  nextMinute: 'Minuto siguiente',
  prevSecond: 'Segundo anterior',
  nextSecond: 'Segundo siguiente',
  am: 'a. m.',
  pm: 'p. m.',
  today: 'Hoy',
  weekHeader: 'Sem.',
  firstDayOfWeek: 1,
  showMonthAfterYear: false,
  dateFormat: 'dd/mm/yy',
  weak: 'Débil',
  medium: 'Media',
  strong: 'Fuerte',
  passwordPrompt: 'Ingresa una contraseña',
  emptyFilterMessage: 'No se encontraron resultados',
  searchMessage: '{0} resultados disponibles',
  selectionMessage: '{0} elementos seleccionados',
  emptySelectionMessage: 'Ningún elemento seleccionado',
  emptySearchMessage: 'No se encontraron resultados',
  fileChosenMessage: '{0} archivos',
  noFileChosenMessage: 'Ningún archivo seleccionado',
  emptyMessage: 'No hay opciones disponibles',
  aria: {
    trueLabel: 'Verdadero',
    falseLabel: 'Falso',
    nullLabel: 'Sin seleccionar',
    star: '1 estrella',
    stars: '{star} estrellas',
    selectAll: 'Todos los elementos seleccionados',
    unselectAll: 'Ningún elemento seleccionado',
    close: 'Cerrar',
    previous: 'Anterior',
    next: 'Siguiente',
    navigation: 'Navegación',
    scrollTop: 'Ir arriba',
    moveTop: 'Mover al inicio',
    moveUp: 'Subir',
    moveDown: 'Bajar',
    moveBottom: 'Mover al final',
    moveToTarget: 'Mover al destino',
    moveToSource: 'Mover al origen',
    moveAllToTarget: 'Mover todo al destino',
    moveAllToSource: 'Mover todo al origen',
    pageLabel: 'Página {page}',
    firstPageLabel: 'Primera página',
    lastPageLabel: 'Última página',
    nextPageLabel: 'Página siguiente',
    prevPageLabel: 'Página anterior',
    rowsPerPageLabel: 'Filas por página',
    jumpToPageDropdownLabel: 'Ir a la página (lista)',
    jumpToPageInputLabel: 'Ir a la página (campo)',
    selectRow: 'Fila seleccionada',
    unselectRow: 'Fila no seleccionada',
    expandRow: 'Fila expandida',
    collapseRow: 'Fila contraída',
    showFilterMenu: 'Mostrar menú de filtros',
    hideFilterMenu: 'Ocultar menú de filtros',
    filterOperator: 'Operador del filtro',
    filterConstraint: 'Condición del filtro',
    editRow: 'Editar fila',
    saveEdit: 'Guardar cambios',
    cancelEdit: 'Cancelar edición',
    listView: 'Vista de lista',
    gridView: 'Vista de cuadrícula',
    slide: 'Diapositiva',
    slideNumber: '{slideNumber}',
    zoomImage: 'Ampliar imagen',
    zoomIn: 'Acercar',
    zoomOut: 'Alejar',
    rotateRight: 'Girar a la derecha',
    rotateLeft: 'Girar a la izquierda',
    listLabel: 'Lista de opciones',
  },
}

// Sesión vencida o usuario desactivado → login bajo /app/ (reemplaza el interceptor de lib/api.ts, que mandaba a '/login')
instalarInterceptoresDeSesion()

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, {
  theme: {
    preset: Aura,
    options: {
      darkModeSelector: '.app-dark',
    },
  },
  ripple: true,
  locale: localeEs,
})
app.directive('tooltip', Tooltip)
app.use(ToastService)
app.use(ConfirmationService)

app.mount('#app')
