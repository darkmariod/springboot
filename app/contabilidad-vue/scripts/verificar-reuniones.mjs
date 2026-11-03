#!/usr/bin/env node
/**
 * Verificación de los pedidos de las reuniones que se ven en pantalla (frontend), sin instalar nada.
 *
 *   R12  Menú por módulos y flechas atrás / adelante     [CONS-4sep, CONS-18sep]
 *   R23  Permisos del menú por rol (cajero y contador)   [CONS-4sep, CONS-18sep]
 *   R24  Atajos de teclado y varias ventanas a la vez    [CONS-4sep, CONS-18sep]
 *
 * Cómo lee el código real: Node 26 entiende TypeScript, pero los archivos de `src/` importan sin extensión
 * (`./stores/tabs`) y usan Vue/Pinia. Por eso cada archivo se lee, se le quitan los tipos con
 * `module.stripTypeScriptTypes`, se reescriben sus `import` (Vue y Pinia salen del `node_modules` de este proyecto;
 * `./stores/auth` se reemplaza por un doble mínimo) y se carga como módulo. No se modifica ningún archivo del proyecto.
 *
 * Uso:   node scripts/verificar-reuniones.mjs                 (código de salida 0 = todo bien, 1 = algo falla)
 *        node scripts/verificar-reuniones.mjs --json=ruta.json  (además guarda el resultado de cada verificación, para el informe del ejecutor)
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { createRequire, stripTypeScriptTypes } from 'node:module'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const requerir = createRequire(pathToFileURL(resolve(RAIZ, 'package.json')))

// ------------------------------------------------------------------ carga tolerante de archivos .ts

/** Dobles de los módulos relativos que el archivo cargado importa y que aquí no hacen falta. */
const DOBLES = {
  './stores/auth': { useAuthStore: () => ({ esSuperadmin: false }) },
}

globalThis.__reunionesDobles = DOBLES
globalThis.__reunionesRequerir = requerir

async function cargarTs(rutaRelativa) {
  const fuente = readFileSync(resolve(RAIZ, rutaRelativa), 'utf8')
  const js = stripTypeScriptTypes(fuente, { mode: 'strip' })
    // import { a, b } from 'x'  ->  const { a, b } = __imp('x')
    .replace(/^import\s+\{([^}]*)\}\s+from\s+['"]([^'"]+)['"]\s*;?\s*$/gm, (_m, nombres, spec) => `const {${nombres}} = __imp(${JSON.stringify(spec)});`)
  const preludio = `const __imp = (spec) => {
  if (spec === 'vue' || spec === 'pinia') return globalThis.__reunionesRequerir(spec)
  if (globalThis.__reunionesDobles[spec]) return globalThis.__reunionesDobles[spec]
  throw new Error('Import sin resolver en la verificación: ' + spec)
}
`
  const url = 'data:text/javascript;base64,' + Buffer.from(preludio + js).toString('base64')
  return import(url)
}

// ------------------------------------------------------------------ mini marco de verificación

/** Quién pidió cada cosa (para el informe). */
const REUNION = { R12: 'CONS-4sep, CONS-18sep', R23: 'CONS-4sep, CONS-18sep', R24: 'CONS-4sep, CONS-18sep' }

const resultados = []
function comprobar(pedido, descripcion, funcion) {
  try {
    const detalle = funcion()
    resultados.push({ pedido, reunion: REUNION[pedido], descripcion, ok: true, detalle })
  } catch (e) {
    resultados.push({ pedido, reunion: REUNION[pedido], descripcion, ok: false, detalle: e.message })
  }
}
function exigir(condicion, mensaje) {
  if (!condicion) throw new Error(mensaje)
}
const mismos = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort())

// ------------------------------------------------------------------ cargar el código real

let modulos, tabs, atajos, Pinia, Vue
try {
  modulos = await cargarTs('src/modules.ts')
  tabs = await cargarTs('src/stores/tabs.ts')
  atajos = await cargarTs('src/composables/useShortcuts.ts')
  Pinia = requerir('pinia')
  Vue = requerir('vue')
} catch (e) {
  console.error('No se pudo leer el código del frontend: ' + e.message)
  console.error('Revise que exista node_modules (npm install) y que se ejecute con Node 24 o superior.')
  process.exit(1)
}

const { modules, modulesPara, PERMISOS } = modulos
const { SHORTCUTS, actionFor } = atajos

/** Los módulos que vería el administrador de un cliente con el plan Completo (todo activo). */
const menuCompleto = (rol = 'admin', superadmin = true) => modulesPara(() => true, rol, false, superadmin)
const etiquetas = (menu) => menu.flatMap((g) => g.items.map((i) => i.label))
const grupoDe = (menu, etiqueta) => menu.find((g) => g.items.some((i) => i.label === etiqueta))?.label

// ------------------------------------------------------------------ R12 · menú por módulos

const menu = menuCompleto('admin', true)
const todas = etiquetas(menu)

comprobar('R12', 'Bienes (inventario) y Servicios van separados (no hay un solo «Productos y servicios»)', () => {
  exigir(todas.includes('Bienes (inventario)'), 'Falta «Bienes (inventario)» en el menú.')
  exigir(todas.includes('Servicios'), 'Falta «Servicios» en el menú.')
  exigir(!todas.some((e) => /productos y servicios/i.test(e)), 'Sigue habiendo un «Productos y servicios» juntos.')
  const bien = menu.flatMap((g) => g.items).find((i) => i.label === 'Bienes (inventario)')
  const servicio = menu.flatMap((g) => g.items).find((i) => i.label === 'Servicios')
  exigir(bien.params?.tipo === 'bien' && servicio.params?.tipo === 'servicio', 'Cada pantalla debe abrir su tipo (bien / servicio).')
  return 'Catálogo → Bienes (inventario) y Servicios'
})

comprobar('R12', 'Hay UNA sola entrada «Notas de crédito y débito» (no dos menús separados)', () => {
  exigir(todas.filter((e) => e === 'Notas de crédito y débito').length === 1, 'Debe haber exactamente una «Notas de crédito y débito».')
  exigir(!todas.some((e) => /^notas? de (cr[eé]dito|d[eé]bito)$/i.test(e)), 'Hay una nota de crédito o de débito suelta en el menú.')
  return 'Ventas → Notas de crédito y débito'
})

comprobar('R12', 'Las notas de crédito y las de débito se ven juntas: una pantalla con las dos listas', () => {
  const pantalla = readFileSync(resolve(RAIZ, 'src/views/NotasCreditoDebito.vue'), 'utf8')
  exigir(/import CreditNotes from '\.\/CreditNotes\.vue'/.test(pantalla), 'La pantalla conjunta no trae las notas de crédito.')
  exigir(/import NotaDebito from '\.\/NotaDebito\.vue'/.test(pantalla), 'La pantalla conjunta no trae las notas de débito.')
  const entrada = menu.flatMap((g) => g.items).find((i) => i.label === 'Notas de crédito y débito')
  exigir(entrada?.component === 'NotasCreditoDebito', 'El menú debe abrir la pantalla conjunta.')
  return 'Ventas → Notas de crédito y débito → pestañas «Notas de crédito» y «Notas de débito»'
})

comprobar('R12', 'Los reportes están dentro de cada módulo: «Reportes de ventas» en Ventas y «Reportes de compras» en Compras', () => {
  exigir(grupoDe(menu, 'Reportes de ventas') === 'Ventas', '«Reportes de ventas» no está dentro de Ventas.')
  exigir(grupoDe(menu, 'Reportes de compras') === 'Compras', '«Reportes de compras» no está dentro de Compras.')
  return 'Ventas → Reportes de ventas · Compras → Reportes de compras'
})

comprobar('R12', 'El estado de cuenta está en cada lado: de clientes (Ventas) y de proveedores (Compras)', () => {
  exigir(grupoDe(menu, 'Estado de cuenta de clientes') === 'Ventas', 'Falta «Estado de cuenta de clientes» en Ventas.')
  exigir(grupoDe(menu, 'Estado de cuenta de proveedores') === 'Compras', 'Falta «Estado de cuenta de proveedores» en Compras.')
  return 'Ventas → Estado de cuenta de clientes · Compras → Estado de cuenta de proveedores'
})

comprobar('R12', 'Existen Toma física, Activos fijos, Estados financieros y Períodos contables', () => {
  const esperado = { 'Toma física': 'Inventario', 'Activos fijos': 'Contabilidad', 'Estados financieros': 'Contabilidad', 'Períodos contables': 'Contabilidad' }
  for (const [etiqueta, grupo] of Object.entries(esperado)) {
    exigir(grupoDe(menu, etiqueta) === grupo, `«${etiqueta}» debe estar en ${grupo}.`)
  }
  return Object.entries(esperado).map(([e, g]) => `${g} → ${e}`).join(' · ')
})

comprobar('R12', '«Facturación Masiva» no sale en el menú pero se abre (desde el botón de Facturas)', () => {
  exigir(!todas.includes('Facturación Masiva'), '«Facturación Masiva» no debería verse en el menú.')
  const conOcultos = etiquetas(modulesPara(() => true, 'admin', true, true))
  exigir(conOcultos.includes('Facturación Masiva'), 'La pantalla de Facturación Masiva no se puede resolver por su clave.')
  const facturas = readFileSync(resolve(RAIZ, 'src/views/Invoices.vue'), 'utf8')
  exigir(/label="Facturación masiva"/.test(facturas) && /key: 'mass-invoicing'/.test(facturas), 'El botón «Facturación masiva» no está dentro de Facturas.')
  return 'Ventas → Facturas → botón «Facturación masiva»'
})

comprobar('R12', 'Las flechas atrás / adelante recuerdan las pantallas (abrir 3, atrás, adelante, cerrar una)', () => {
  Pinia.setActivePinia(Pinia.createPinia())
  const t = tabs.useTabsStore()
  const A = { key: 'a', label: 'A', icon: '', component: 'A' }
  const B = { key: 'b', label: 'B', icon: '', component: 'B' }
  const C = { key: 'c', label: 'C', icon: '', component: 'C' }
  t.open(A); t.open(B); t.open(C)
  exigir(t.tabs.length === 3 && t.activeKey === 'c', 'Debían quedar 3 pantallas abiertas y la tercera al frente.')
  exigir(t.puedeAtras && !t.puedeAdelante, 'Con la tercera al frente se puede ir atrás, no adelante.')
  t.atras()
  exigir(t.activeKey === 'b', `Atrás debía llevar a la segunda (quedó en «${t.activeKey}»).`)
  exigir(t.puedeAtras && t.puedeAdelante, 'En la segunda se puede ir atrás y adelante.')
  t.adelante()
  exigir(t.activeKey === 'c', `Adelante debía volver a la tercera (quedó en «${t.activeKey}»).`)
  t.atras(); t.atras()
  exigir(t.activeKey === 'a' && !t.puedeAtras, 'Dos veces atrás debía llegar a la primera, sin más hacia atrás.')
  t.adelante()
  t.close('b')
  exigir(t.tabs.length === 2 && !t.tabs.some((x) => x.key === 'b'), 'Al cerrar la segunda debían quedar 2 pantallas.')
  exigir(!t.historial.claves.includes('b'), 'Atrás no debe apuntar a una pantalla que ya se cerró.')
  t.atras()
  exigir(t.activeKey !== 'b', 'Atrás volvió a una pantalla cerrada.')
  return 'tabs.ts: abrir, atrás, adelante y cerrar'
})

comprobar('R12', 'El historial es como el del navegador: ir atrás y abrir otra pantalla descarta lo de adelante, y tiene tope', () => {
  let h = tabs.historialVacio()
  for (const k of ['a', 'b', 'c']) h = tabs.registrarActivacion(h, k)
  h = tabs.moverHistorial(h, -1).historial
  h = tabs.registrarActivacion(h, 'd')
  exigir(JSON.stringify(h.claves) === JSON.stringify(['a', 'b', 'd']), 'Abrir una pantalla nueva debía descartar lo de adelante (c).')
  exigir(tabs.moverHistorial(h, 1) === null, 'Ya no hay nada adelante.')
  let largo = tabs.historialVacio()
  for (let i = 0; i < tabs.HISTORIAL_MAX + 20; i++) largo = tabs.registrarActivacion(largo, 'k' + i)
  exigir(largo.claves.length === tabs.HISTORIAL_MAX, `El historial debe tener tope de ${tabs.HISTORIAL_MAX}.`)
  return `tope de ${tabs.HISTORIAL_MAX} pantallas`
})

// ------------------------------------------------------------------ R23 · permisos del menú por rol

comprobar('R23', 'El cajero solo ve Punto de Venta, Facturas, Cotizaciones y Clientes (nada más)', () => {
  exigir(mismos(PERMISOS.cajero, ['pos', 'invoices', 'quotes', 'contacts']), `El cajero ve: ${JSON.stringify(PERMISOS.cajero)}`)
  const verCajero = etiquetas(modulesPara(() => true, 'cajero', false, false))
  exigir(mismos(verCajero, ['Punto de Venta', 'Facturas', 'Cotizaciones', 'Clientes']), `El menú del cajero muestra: ${verCajero.join(', ')}`)
  return 'cajero: ' + verCajero.join(' · ')
})

comprobar('R23', 'El contador no administra: sin usuarios, empresas, sucursales, firma ni puntos de emisión', () => {
  const claveAdministrar = ['users', 'companies', 'mi-empresa', 'branches', 'signature', 'emission-edocs']
  const sobran = claveAdministrar.filter((k) => PERMISOS.contador.includes(k))
  exigir(sobran.length === 0, `El contador tiene claves de administración: ${sobran.join(', ')}`)
  const verContador = etiquetas(modulesPara(() => true, 'contador', false, false))
  for (const e of ['Usuarios y roles', 'Empresas', 'Mi empresa', 'Sucursales', 'Configuración de firma', 'Puntos de emisión']) {
    exigir(!verContador.includes(e), `El contador ve «${e}».`)
  }
  exigir(verContador.includes('Libro diario') && verContador.includes('Rol de pagos'), 'El contador debe ver la contabilidad y la nómina.')
  // El grupo «Administración» del menú también guarda Auditoría y los reportes: el contador los ve (solo lectura), lo demás no.
  const admin = modulesPara(() => true, 'contador', false, false).find((g) => g.label === 'Administración')?.items.map((i) => i.label) ?? []
  exigir(mismos(admin, ['Auditoría', 'Reportes de ventas y compras']), `En Administración el contador ve: ${admin.join(', ') || 'nada'}`)
  return 'el contador ve contabilidad, nómina y reportes; en Administración solo Auditoría y Reportes (lectura); no ve usuarios, empresas, sucursales ni firma'
})

comprobar('R23', 'El administrador de un cliente ve todo salvo «Empresas» (solo la plataforma) y ve «Mi empresa»', () => {
  const adminCliente = etiquetas(modulesPara(() => true, 'admin', false, false))
  exigir(!adminCliente.includes('Empresas') && adminCliente.includes('Mi empresa'), 'El administrador de un cliente debe ver «Mi empresa», no «Empresas».')
  const superadmin = etiquetas(modulesPara(() => true, 'admin', false, true))
  exigir(superadmin.includes('Empresas') && !superadmin.includes('Mi empresa'), 'El operador de la plataforma debe ver «Empresas».')
  return 'admin cliente: Mi empresa · superadmin: Empresas'
})

// ------------------------------------------------------------------ R24 · atajos y ventanas múltiples

comprobar('R24', 'La lista de atajos trae F2, Ctrl+S, F3, F4, F8, Alt+1…9 y Alt+←/→', () => {
  const teclas = SHORTCUTS.map((s) => s.keys.replace(/\s+/g, ''))
  for (const esperado of ['F2', 'Ctrl+S', 'F3', 'F4', 'F8', 'Alt+1…9', 'Alt+←', 'Alt+→']) {
    exigir(teclas.includes(esperado), `Falta el atajo ${esperado}.`)
  }
  return SHORTCUTS.map((s) => `${s.keys} = ${s.label}`).join(' · ')
})

comprobar('R24', 'Cada atajo hace lo que dice (F2 nuevo, Ctrl+S guardar, F3 buscar, F4 módulos, F8 cerrar, Alt+N pestaña, Alt+flechas)', () => {
  const tecla = (init) => actionFor({ ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, key: '', code: '', ...init })
  const casos = [
    [{ key: 'F2' }, 'nuevo'], [{ key: 's', ctrlKey: true }, 'guardar'], [{ key: 'F3' }, 'buscar'], [{ key: 'F4' }, 'modulos'],
    [{ key: 'F8' }, 'cerrar-pestana'], [{ key: 'ArrowLeft', altKey: true }, 'atras'], [{ key: 'ArrowRight', altKey: true }, 'adelante'],
  ]
  for (const [evento, accion] of casos) {
    const r = tecla(evento)
    exigir(r?.action === accion, `${JSON.stringify(evento)} debía hacer «${accion}» y hizo «${r?.action}».`)
  }
  for (let n = 1; n <= 9; n++) {
    const r = tecla({ key: String(n), code: 'Digit' + n, altKey: true })
    exigir(r?.action === 'pestana' && r.payload === n - 1, `Alt+${n} debía ir a la pestaña ${n}.`)
  }
  return 'F2, Ctrl+S, F3, F4, F8, Alt+1…9, Alt+←, Alt+→'
})

comprobar('R24', 'Se pueden tener varias ventanas abiertas a la vez y volver a una ya abierta no la duplica', () => {
  Pinia.setActivePinia(Pinia.createPinia())
  const t = tabs.useTabsStore()
  const lista = modules.flatMap((g) => g.items).filter((i) => !i.oculto).slice(0, 6)
  lista.forEach((m) => t.open(m))
  exigir(t.tabs.length === 6, 'Debían quedar 6 ventanas abiertas a la vez.')
  t.open(lista[2])
  exigir(t.tabs.length === 6 && t.activeKey === lista[2].key, 'Abrir una ventana ya abierta debe llevar a ella, sin duplicarla.')
  t.closeAll(lista[2].key)
  exigir(t.tabs.length === 1, 'Cerrar las demás debía dejar una sola ventana.')
  return '6 ventanas a la vez, sin duplicar'
})

// ------------------------------------------------------------------ informe

let fallas = 0
console.log('\nVerificación de los pedidos de las reuniones (pantalla)\n')
for (const r of resultados) {
  const marca = r.ok ? 'OK    ' : 'FALLA '
  if (!r.ok) fallas++
  console.log(`${marca} ${r.pedido.padEnd(3)} ${r.descripcion}`)
  console.log(`       ${r.ok ? r.detalle : '-> ' + r.detalle}`)
}
console.log('')
console.log(`Resultado: ${resultados.length - fallas} de ${resultados.length} verificaciones pasan.`)
console.log(fallas === 0 ? 'LISTO: lo que se ve en pantalla cumple R12, R23 y R24.' : `HAY ${fallas} VERIFICACIONES QUE FALLAN.`)

const archivoJson = process.argv.find((a) => a.startsWith('--json='))?.slice('--json='.length)
if (archivoJson) writeFileSync(archivoJson, JSON.stringify({ resultados }, null, 2))
process.exit(fallas === 0 ? 0 : 1)
