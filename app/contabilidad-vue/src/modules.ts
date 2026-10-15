import type { WorkTab } from './stores/tabs'

// oculto: no sale en el lanzador ni en la búsqueda, pero la pantalla sigue existiendo y se abre
// desde otra (ej. Facturación Masiva, desde el botón de Facturas). Conserva su clave de PERMISOS.
export type ModuleItem = WorkTab & { feature?: string; disabled?: boolean; oculto?: boolean }

export const modules: { label: string; items: ModuleItem[] }[] = [
  {
    label: 'Inicio',
    items: [
      { key: 'inicio', label: 'Resumen del negocio', icon: 'pi pi-home', component: 'Dashboard' },
    ],
  },
  {
    label: 'Catálogo',
    items: [
      { key: 'contacts', label: 'Clientes', icon: 'pi pi-users', component: 'Contacts', feature: 'catalogo' },
      { key: 'bienes', label: 'Bienes (inventario)', icon: 'pi pi-box', component: 'Products', feature: 'catalogo', params: { tipo: 'bien' } },
      { key: 'servicios', label: 'Servicios', icon: 'pi pi-briefcase', component: 'Products', feature: 'catalogo', params: { tipo: 'servicio' } },
      { key: 'accounts', label: 'Plan de cuentas', icon: 'pi pi-sitemap', component: 'Accounts', feature: 'contabilidad' },
    ],
  },
  {
    label: 'Ventas',
    items: [
      { key: 'pos', label: 'Punto de Venta', icon: 'pi pi-shopping-cart', component: 'Pos', feature: 'ventas' },
      { key: 'invoices', label: 'Facturas', icon: 'pi pi-file', component: 'Invoices', feature: 'ventas' },
      { key: 'quotes', label: 'Cotizaciones', icon: 'pi pi-file-edit', component: 'Quotes', feature: 'ventas' },
      { key: 'advances', label: 'Anticipos', icon: 'pi pi-arrow-down-left', component: 'Advances', feature: 'cartera' },
      // Notas de crédito y de débito juntas. Van con 'cartera' (no con 'facturacion_sri'): las internas
      // regulan saldos y existen también en los planes sin facturación electrónica.
      { key: 'notas-credito-debito', label: 'Notas de crédito y débito', icon: 'pi pi-receipt', component: 'NotasCreditoDebito', feature: 'cartera' },
      { key: 'receivables', label: 'Cuentas por cobrar', icon: 'pi pi-wallet', component: 'Receivables', feature: 'cartera' },
      { key: 'estado-cuenta-clientes', label: 'Estado de cuenta de clientes', icon: 'pi pi-chart-bar', component: 'EstadoCuenta', feature: 'cartera' },
      { key: 'sales-ret', label: 'Retenciones recibidas', icon: 'pi pi-percentage', component: 'Withholdings', feature: 'ventas' },
      { key: 'guias-remision', label: 'Guía de Remisión', icon: 'pi pi-truck', component: 'GuiaRemision', feature: 'facturacion_sri' },
      { key: 'reportes-ventas', label: 'Reportes de ventas', icon: 'pi pi-file-export', component: 'SalesReports', feature: 'reportes', params: { reporte: 'ventas' } },
      // Se abre desde el botón "Facturación masiva" de Facturas
      { key: 'mass-invoicing', label: 'Facturación Masiva', icon: 'pi pi-copy', component: 'MassInvoicing', feature: 'facturacion_masiva', oculto: true },
    ],
  },
  {
    label: 'Compras',
    items: [
      { key: 'purchases', label: 'Compras', icon: 'pi pi-shopping-bag', component: 'Purchases', feature: 'compras' },
      { key: 'purchase-entry', label: 'Registro de Compras', icon: 'pi pi-file-edit', component: 'PurchaseEntry', feature: 'compras' },
      { key: 'batch-import', label: 'Importar del SRI (lote)', icon: 'pi pi-cloud-download', component: 'BatchImport', feature: 'import_lote' },
      { key: 'suppliers', label: 'Proveedores', icon: 'pi pi-truck', component: 'Suppliers', feature: 'compras' },
      { key: 'liquidacion-compra', label: 'Liq. de Compra', icon: 'pi pi-file', component: 'LiquidacionCompra', feature: 'facturacion_sri' },
      { key: 'payables', label: 'Cuentas por pagar', icon: 'pi pi-credit-card', component: 'Payables', feature: 'cartera' },
      { key: 'estado-cuenta-proveedores', label: 'Estado de cuenta de proveedores', icon: 'pi pi-chart-bar', component: 'EstadoCuenta', feature: 'cartera' },
      { key: 'reportes-compras', label: 'Reportes de compras', icon: 'pi pi-file-export', component: 'SalesReports', feature: 'reportes', params: { reporte: 'compras' } },
    ],
  },
  {
    label: 'Inventario',
    items: [
      { key: 'inventory', label: 'Inventario y kardex', icon: 'pi pi-database', component: 'Inventory', feature: 'inventario' },
      { key: 'warehouses', label: 'Bodegas', icon: 'pi pi-building', component: 'Warehouses', feature: 'inventario' },
      { key: 'series', label: 'Garantías por serie', icon: 'pi pi-qrcode', component: 'Series', feature: 'series' },
      { key: 'inventory-reports', label: 'Reportes de inventario', icon: 'pi pi-print', component: 'InventoryReports', feature: 'reportes' },
      { key: 'inventory-adjustment', label: 'Ajuste Inventario', icon: 'pi pi-sliders-h', component: 'InventoryAdjustment', feature: 'inventario' },
      { key: 'toma-fisica', label: 'Toma física', icon: 'pi pi-list-check', component: 'TomaFisica', feature: 'inventario' },
      { key: 'inventory-transfer', label: 'Transferencia', icon: 'pi pi-arrow-right-arrow-left', component: 'InventoryTransfer', feature: 'inventario' },
      { key: 'article-conversion', label: 'Conversión Artículos', icon: 'pi pi-refresh', component: 'ArticleConversion', feature: 'conversion_articulos' },
      { key: 'fractionation', label: 'Fraccionamiento', icon: 'pi pi-scissors', component: 'Fractionation', feature: 'fraccionamiento' },
      { key: 'stock-reservations', label: 'Reservas Stock', icon: 'pi pi-bookmark', component: 'StockReservations', feature: 'reservas_stock' },
    ],
  },
  {
    label: 'Caja y Bancos',
    items: [
      { key: 'cash', label: 'Caja', icon: 'pi pi-money-bill', component: 'Cash', feature: 'bancos' },
      { key: 'banks', label: 'Bancos', icon: 'pi pi-building-columns', component: 'Banks', feature: 'bancos' },
      { key: 'reconciliation', label: 'Conciliación bancaria', icon: 'pi pi-sync', component: 'Reconciliation', feature: 'conciliacion' },
      { key: 'card-reconciliation', label: 'Conciliación Tarjetas', icon: 'pi pi-credit-card', component: 'CardReconciliation', feature: 'conciliacion_tarjetas' },
    ],
  },
  {
    label: 'Contabilidad',
    items: [
      { key: 'journal', label: 'Libro diario', icon: 'pi pi-book', component: 'Accounting', feature: 'contabilidad' },
      { key: 'ledger', label: 'Libro mayor', icon: 'pi pi-list', component: 'Ledger', feature: 'contabilidad' },
      { key: 'estados-financieros', label: 'Estados financieros', icon: 'pi pi-chart-line', component: 'EstadosFinancieros', feature: 'contabilidad' },
      { key: 'periodos-contables', label: 'Períodos contables', icon: 'pi pi-calendar', component: 'PeriodosContables', feature: 'contabilidad' },
      { key: 'activos-fijos', label: 'Activos fijos', icon: 'pi pi-building', component: 'FixedAssets', feature: 'activos_fijos' },
      { key: 'taxes', label: 'Impuestos', icon: 'pi pi-percentage', component: 'Taxes', feature: 'contabilidad' },
    ],
  },
  {
    label: 'Nómina',
    items: [
      { key: 'employees', label: 'Empleados', icon: 'pi pi-id-card', component: 'Employees', feature: 'nomina' },
      { key: 'payroll', label: 'Rol de pagos', icon: 'pi pi-wallet', component: 'Payroll', feature: 'nomina' },
    ],
  },
  {
    // EDocuments (como KVS): la configuración de la firma y los comprobantes electrónicos.
    // Aquí vive "la magia": firma, clave, correo, websites del SRI.
    label: 'EDocuments',
    items: [
      { key: 'signature', label: 'Configuración de firma', icon: 'pi pi-shield', component: 'SignatureConfig', feature: 'facturacion_sri' },
      { key: 'documents', label: 'Documentos SRI', icon: 'pi pi-check-square', component: 'SriDocuments', feature: 'facturacion_sri' },
      { key: 'emission-edocs', label: 'Puntos de emisión', icon: 'pi pi-hashtag', component: 'EmissionPoints', feature: 'facturacion_sri' },
    ],
  },
  {
    label: 'Administración',
    items: [
      { key: 'branches', label: 'Sucursales', icon: 'pi pi-building-columns', component: 'Branches', feature: 'sucursales' },
      { key: 'companies', label: 'Empresas', icon: 'pi pi-building', component: 'Companies' },
      { key: 'users', label: 'Usuarios y roles', icon: 'pi pi-shield', component: 'Users', feature: 'usuarios' },
      { key: 'audit', label: 'Auditoría', icon: 'pi pi-history', component: 'Audit', feature: 'auditoria' },
      { key: 'sales-reports', label: 'Reportes de ventas y compras', icon: 'pi pi-file-export', component: 'SalesReports', feature: 'reportes' },
    ],
  },
]

/**
 * Qué pantallas ve cada rol.
 *
 * Esto es lo que se muestra; el backend vuelve a revisar el rol en cada ruta
 * sensible. Ocultar un menú no es seguridad: es comodidad. La puerta está atrás.
 *
 * '*' = ve todo.
 */
export const PERMISOS: Record<string, '*' | string[]> = {
  admin: '*',

  // Lleva la contabilidad y declara. No toca empresas, usuarios ni la firma.
  contador: [
    'inicio',
    'contacts', 'bienes', 'servicios', 'accounts',
    'pos', 'invoices', 'quotes', 'advances', 'notas-credito-debito', 'receivables', 'estado-cuenta-clientes',
    'sales-ret', 'guias-remision', 'mass-invoicing', 'reportes-ventas',
    'purchases', 'purchase-entry', 'batch-import', 'suppliers',
    'liquidacion-compra', 'payables', 'estado-cuenta-proveedores', 'reportes-compras',
    'inventory', 'warehouses', 'series', 'inventory-reports',
    'inventory-adjustment', 'toma-fisica', 'inventory-transfer',
    'cash', 'banks', 'reconciliation', 'card-reconciliation',
    'journal', 'ledger', 'estados-financieros', 'periodos-contables', 'activos-fijos', 'taxes',
    'employees', 'payroll',
    'documents',
    'audit', 'sales-reports',
  ],

  // Atiende y factura, nada más. En un laboratorio, el rol del doctor o de
  // recepción: registra el examen del paciente y emite la factura.
  cajero: ['pos', 'invoices', 'quotes', 'contacts'],
}

function puedeVer(rol: string | undefined, key: string) {
  const permisos = PERMISOS[rol ?? '']
  if (permisos === '*') return true
  if (!permisos) return false        // rol desconocido: no ve nada
  return permisos.includes(key)
}

/**
 * Los módulos que ve un usuario según su plan y su rol. Los ítems `oculto` quedan fuera del
 * menú salvo que se pida `incluirOcultos` (para resolver una pantalla por su clave).
 */
export function modulesPara(tiene: (f?: string) => boolean, rol?: string, incluirOcultos = false) {
  return modules
    .map((g) => ({
      ...g,
      items: g.items.filter((i) => (incluirOcultos || !i.oculto) && tiene(i.feature) && puedeVer(rol, i.key)),
    }))
    .filter((g) => g.items.length > 0)
}
