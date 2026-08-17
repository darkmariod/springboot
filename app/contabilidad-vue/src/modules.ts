import type { WorkTab } from './stores/tabs'

export type ModuleItem = WorkTab & { feature?: string; disabled?: boolean }

export const modules: { label: string; items: ModuleItem[] }[] = [
  {
    label: 'Catálogo',
    items: [
      { key: 'contacts', label: 'Clientes', icon: 'pi pi-users', component: 'Contacts', feature: 'catalogo' },
      { key: 'products', label: 'Productos y servicios', icon: 'pi pi-box', component: 'Products', feature: 'catalogo' },
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
      { key: 'credit-notes', label: 'Notas de crédito', icon: 'pi pi-file-excel', component: 'CreditNotes', feature: 'cartera' },
      { key: 'receivables', label: 'Cuentas por cobrar', icon: 'pi pi-wallet', component: 'Receivables', feature: 'cartera' },
      { key: 'sales-ret', label: 'Retenciones recibidas', icon: 'pi pi-percentage', component: 'Withholdings', feature: 'ventas' },
      { key: 'notas-debito', label: 'Nota de Débito', icon: 'pi pi-file-edit', component: 'NotaDebito', feature: 'ventas' },
      { key: 'guias-remision', label: 'Guía de Remisión', icon: 'pi pi-truck', component: 'GuiaRemision', feature: 'ventas' },
      { key: 'mass-invoicing', label: 'Facturación Masiva', icon: 'pi pi-copy', component: 'MassInvoicing', feature: 'facturacion_masiva' },
    ],
  },
  {
    label: 'Compras',
    items: [
      { key: 'purchases', label: 'Compras', icon: 'pi pi-shopping-bag', component: 'Purchases', feature: 'compras' },
      { key: 'purchase-entry', label: 'Registro de Compras', icon: 'pi pi-file-edit', component: 'PurchaseEntry', feature: 'compras' },
      { key: 'batch-import', label: 'Importar del SRI (lote)', icon: 'pi pi-cloud-download', component: 'BatchImport', feature: 'import_lote' },
      { key: 'suppliers', label: 'Proveedores', icon: 'pi pi-truck', component: 'Suppliers', feature: 'compras' },
      { key: 'liquidacion-compra', label: 'Liq. de Compra', icon: 'pi pi-file', component: 'LiquidacionCompra', feature: 'ventas' },
      { key: 'payables', label: 'Cuentas por pagar', icon: 'pi pi-credit-card', component: 'Payables', feature: 'cartera' },
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
      { key: 'statements', label: 'Estados financieros', icon: 'pi pi-chart-line', component: 'Accounting', feature: 'contabilidad' },
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
    // Acá vive "la magia": firma, clave, correo, websites del SRI.
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
      { key: 'companies', label: 'Empresas', icon: 'pi pi-building', component: 'Companies' },
      { key: 'users', label: 'Usuarios y roles', icon: 'pi pi-shield', component: 'Users', feature: 'usuarios' },
      { key: 'audit', label: 'Auditoría', icon: 'pi pi-history', component: 'Audit', feature: 'auditoria' },
      { key: 'reports', label: 'Reportes', icon: 'pi pi-chart-bar', component: 'ReportViewer', feature: 'reportes' },
    ],
  },
  {
    label: 'Corporativo',
    items: [
      { key: 'c-fixed-assets', label: 'Activos Fijos', icon: 'pi pi-calculator', component: '', disabled: true },
      { key: 'c-production', label: 'Producción', icon: 'pi pi-wrench', component: '', disabled: true },
      { key: 'c-online-sales', label: 'Ventas Online', icon: 'pi pi-globe', component: '', disabled: true },
      { key: 'c-academic', label: 'Académico', icon: 'pi pi-book', component: '', disabled: true },
      { key: 'c-budgets', label: 'Presupuestos', icon: 'pi pi-chart-pie', component: '', disabled: true },
      { key: 'c-cost-center', label: 'Centro Costo', icon: 'pi pi-sitemap', component: '', disabled: true },
    ],
  },
]

export function modulesPara(tiene: (f?: string) => boolean) {
  return modules
    .map((g) => ({ ...g, items: g.items.filter((i) => tiene(i.feature)) }))
    .filter((g) => g.items.length > 0)
}
