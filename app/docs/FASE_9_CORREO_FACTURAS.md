# FASE 9 — Envío de facturas por correo (lo que pidió el cliente)

> De los audios del cliente (16-jul):
> - La factura **debe llegar al correo del comprador** (XML + PDF). El SRI lo exige.
> - Correo de envío configurable: corporativo (`facturas@suempresa.com`) o Gmail/Yahoo/Hotmail.
> - **Configuración SMTP** en el sistema: servidor, puerto, usuario, clave, cifrado.
>
> Va todo en **EDocuments → Configuración de firma**, sección "Envío".
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`

---

## A. BACKEND

### 9.1 Instalar el generador de PDF (una sola vez)
```bash
cd contabilidad-backend
composer require barryvdh/laravel-dompdf
```

### 9.2 Migración — `database/migrations/2026_07_29_000001_add_smtp_to_companies.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('smtp_host')->nullable();       // ej. smtp.gmail.com
            $t->unsignedInteger('smtp_port')->nullable(); // 587 (TLS) o 465 (SSL)
            $t->string('smtp_user')->nullable();
            $t->text('smtp_password')->nullable();     // encriptada
            $t->string('smtp_encryption')->nullable(); // tls | ssl
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) =>
            $t->dropColumn(['smtp_host','smtp_port','smtp_user','smtp_password','smtp_encryption']));
    }
};
```

### 9.3 En `app/Models/Company.php`
Agregá al `$fillable`:
```php
'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_encryption',
```
Y para que la clave se guarde encriptada, agregá al `$casts` (o creá la propiedad si no existe):
```php
    protected $casts = [
        'certificado_clave' => 'encrypted',
        'smtp_password' => 'encrypted',
        'plan_vence' => 'date',
    ];
```
> ⚠️ Si `certificado_clave` ya estaba en tu `$casts`, solo agregá la línea de `smtp_password`.

### 9.4 Servicio de envío — `app/Services/InvoiceMailer.php`
```php
<?php
namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;

class InvoiceMailer
{
    /** Sends the RIDE (PDF) + XML to the buyer using the company's own SMTP. */
    public function send(Invoice $invoice): array
    {
        $company = Company::findOrFail($invoice->company_id);
        $invoice->loadMissing('contact', 'sriDocument');

        $destinatario = $invoice->contact?->email;
        if (! $destinatario) {
            return ['ok' => false, 'error' => 'El cliente no tiene correo registrado.'];
        }
        if (! $company->smtp_host || ! $company->email_envio) {
            return ['ok' => false, 'error' => 'Configura primero el SMTP en EDocuments > Configuracion de firma.'];
        }

        // Mailer dinamico: cada empresa envia con SU correo
        config([
            'mail.mailers.empresa' => [
                'transport' => 'smtp',
                'host' => $company->smtp_host,
                'port' => (int) ($company->smtp_port ?? 587),
                'username' => $company->smtp_user,
                'password' => $company->smtp_password,
                'encryption' => $company->smtp_encryption ?: 'tls',
                'timeout' => 15,
            ],
        ]);

        $pdf = Pdf::loadView('ride', ['invoice' => $invoice, 'company' => $company]);
        $xml = $invoice->sriDocument?->xml_firmado ?? $invoice->sriDocument?->xml;

        try {
            Mail::mailer('empresa')->send([], [], function ($m) use ($company, $invoice, $destinatario, $pdf, $xml) {
                $m->from($company->email_envio, $company->razon_social)
                  ->to($destinatario)
                  ->subject('Factura electronica '.$invoice->numero.' - '.$company->razon_social)
                  ->html('<p>Estimado cliente,</p><p>Adjuntamos su factura electronica <b>'
                      .$invoice->numero.'</b> emitida por '.$company->razon_social
                      .'.</p><p>Gracias por su compra.</p>')
                  ->attachData($pdf->output(), 'factura-'.$invoice->numero.'.pdf',
                      ['mime' => 'application/pdf']);
                if ($xml) {
                    $m->attachData($xml, 'factura-'.$invoice->numero.'.xml',
                        ['mime' => 'application/xml']);
                }
            });
            return ['ok' => true, 'enviado_a' => $destinatario];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)];
        }
    }
}
```

### 9.5 Plantilla del RIDE — `resources/views/ride.blade.php`
```html
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
  .caja { border: 1.5px solid #444; border-radius: 4px; margin-bottom: 8px; padding: 8px 10px; }
  .fila { width: 100%; }
  .col { display: inline-block; vertical-align: top; width: 48%; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; background: #f2f2f2; border-bottom: 1px solid #444; padding: 4px 6px; }
  td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
  .der { text-align: right; }
  .clave { font-family: DejaVu Sans Mono, monospace; font-size: 9px; word-break: break-all; }
</style></head>
<body>
  <div class="caja fila">
    <div class="col">
      <h2 style="margin:0;">{{ $company->razon_social }}</h2>
      <div><b>RUC:</b> {{ $company->ruc }}</div>
      <div><b>Matriz:</b> {{ $company->dir_matriz }}</div>
      <div><b>Obligado a llevar contabilidad:</b> SI</div>
    </div>
    <div class="col">
      <h3 style="margin:0;">FACTURA No. {{ $invoice->numero }}</h3>
      <div><b>NUMERO DE AUTORIZACION:</b></div>
      <div class="clave">{{ $invoice->sriDocument->numero_autorizacion ?? $invoice->sriDocument->clave_acceso ?? 'PENDIENTE' }}</div>
      <div><b>AMBIENTE:</b> {{ (int)($company->ambiente) === 2 ? 'PRODUCCION' : 'PRUEBAS' }}</div>
      <div><b>CLAVE DE ACCESO:</b></div>
      <div class="clave">{{ $invoice->sriDocument->clave_acceso ?? '' }}</div>
    </div>
  </div>

  <div class="caja">
    <div><b>Razon Social:</b> {{ $invoice->contact->razon_social }}</div>
    <div><b>RUC/CI:</b> {{ $invoice->contact->identificacion }}
      &nbsp;&nbsp; <b>Fecha:</b> {{ optional($invoice->fecha_emision)->format('Y-m-d') }}</div>
  </div>

  <table>
    <thead><tr>
      <th>Codigo</th><th>Cant.</th><th>Descripcion</th>
      <th class="der">P. Unitario</th><th class="der">Total</th>
    </tr></thead>
    <tbody>
      @foreach (($invoice->items ?? []) as $it)
      <tr>
        <td>{{ $it['codigo_principal'] ?? '' }}</td>
        <td>{{ $it['cantidad'] ?? '' }}</td>
        <td>{{ $it['descripcion'] ?? '' }}
          @if (!empty($it['series'])) (Serie: {{ implode(', ', $it['series']) }}) @endif</td>
        <td class="der">${{ number_format($it['precio_unitario'] ?? 0, 2) }}</td>
        <td class="der">${{ number_format(($it['cantidad'] ?? 0) * ($it['precio_unitario'] ?? 0), 2) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="caja" style="margin-top:8px;">
    <div class="col"><b>Forma de pago:</b> {{ strtoupper($invoice->forma_pago ?? '') }}</div>
    <div class="col der">
      <div>SUBTOTAL 15%: <b>${{ number_format($invoice->total_sin_impuestos, 2) }}</b></div>
      <div>IVA 15%: <b>${{ number_format($invoice->total_impuesto, 2) }}</b></div>
      <div style="font-size:13px;">VALOR TOTAL: <b>${{ number_format($invoice->importe_total, 2) }}</b></div>
    </div>
  </div>
</body>
</html>
```

### 9.6 Guardar SMTP + probar + enviar — en `app/Http/Controllers/CompanyController.php`
Agregá estos dos métodos:
```php
    public function updateSmtp(\Illuminate\Http\Request $request, Company $company)
    {
        $d = $request->validate([
            'email_envio' => ['required', 'email'],
            'smtp_host' => ['required', 'string'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_user' => ['required', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_encryption' => ['required', 'in:tls,ssl'],
        ]);
        if (empty($d['smtp_password'])) unset($d['smtp_password']); // vacia = no cambiar
        $company->update($d);
        return ['ok' => true, 'mensaje' => 'Configuracion de correo guardada.'];
    }

    public function testSmtp(\Illuminate\Http\Request $request, Company $company)
    {
        $request->validate(['destinatario' => ['required', 'email']]);
        config(['mail.mailers.empresa' => [
            'transport' => 'smtp', 'host' => $company->smtp_host,
            'port' => (int) ($company->smtp_port ?? 587), 'username' => $company->smtp_user,
            'password' => $company->smtp_password,
            'encryption' => $company->smtp_encryption ?: 'tls', 'timeout' => 15,
        ]]);
        try {
            \Illuminate\Support\Facades\Mail::mailer('empresa')->raw(
                'Prueba de envio del sistema contable. Si lees esto, el SMTP funciona.',
                fn($m) => $m->from($company->email_envio, $company->razon_social)
                    ->to($request->destinatario)->subject('Prueba SMTP - '.$company->razon_social));
            return ['ok' => true, 'mensaje' => 'Correo de prueba enviado. Revisa la bandeja (y spam).'];
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => substr($e->getMessage(), 0, 200)], 422);
        }
    }
```

### 9.7 Enviar la factura — en `app/Http/Controllers/InvoiceController.php`
```php
    public function sendEmail(\App\Models\Invoice $invoice, \App\Services\InvoiceMailer $mailer)
    {
        $res = $mailer->send($invoice);
        return $res['ok'] ? $res : response()->json($res, 422);
    }
```

### 9.8 Envío automático al emitir — en `app/Services/InvoiceEmitter.php`
Al FINAL de `emit()`, justo antes del `return`:
```php
        // Envio automatico al correo del cliente (si hay SMTP configurado).
        // Nunca rompe la emision: si el correo falla, la factura ya quedo emitida.
        try {
            if ($company->smtp_host && $contact->email) {
                app(InvoiceMailer::class)->send($invoice);
            }
        } catch (\Throwable $e) {
            // se puede reenviar a mano desde Facturas
        }
```

### 9.9 Rutas — en `routes/api.php`
```php
    // Fase 9 — Correo de facturas (SMTP por empresa)
    Route::post("companies/{company}/smtp", [\App\Http\Controllers\CompanyController::class, "updateSmtp"]);
    Route::post("companies/{company}/smtp/test", [\App\Http\Controllers\CompanyController::class, "testSmtp"]);
    Route::post("invoices/{invoice}/send-email", [\App\Http\Controllers\InvoiceController::class, "sendEmail"]);
```
```bash
php artisan migrate
```

---

## B. FRONTEND

### 9.10 Sección SMTP — en `src/views/SignatureConfig.vue`

**En el `<script setup>`, agregá:**
```ts
const smtp = ref<any>({ smtp_host: '', smtp_port: 587, smtp_user: '', smtp_password: '', smtp_encryption: 'tls' })
const probando = ref(false)
const cifrados = [{ label: 'TLS (puerto 587)', value: 'tls' }, { label: 'SSL (puerto 465)', value: 'ssl' }]

async function guardarSmtp() {
  try {
    const res = await api.post('/companies/' + companyId.value + '/smtp',
      { ...smtp.value, email_envio: email.value })
    msg.value = { type: 'success', text: res.data.mensaje }
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'Revisa los campos del SMTP.' }
  }
}
async function probarSmtp() {
  const destino = prompt('¿A qué correo mando la prueba?', email.value)
  if (!destino) return
  probando.value = true
  try {
    const res = await api.post('/companies/' + companyId.value + '/smtp/test', { destinatario: destino })
    msg.value = { type: 'success', text: res.data.mensaje }
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.error ?? 'Falló el envío de prueba.' }
  } finally { probando.value = false }
}
```
Y dentro de `load()`, después de asignar `email.value`, agregá:
```ts
  if (c) {
    smtp.value.smtp_host = c.smtp_host ?? ''
    smtp.value.smtp_port = Number(c.smtp_port ?? 587)
    smtp.value.smtp_user = c.smtp_user ?? ''
    smtp.value.smtp_encryption = c.smtp_encryption ?? 'tls'
  }
```

**En el template, después del bloque "2. Envío", agregá:**
```html
      <div style="border-top:1px solid #f1f3f6; padding-top:16px;">
        <p style="margin:0 0 4px; font-weight:600; color:#4a3220;">2b. Servidor de correo (SMTP)</p>
        <p style="margin:0 0 12px; font-size:12px; color:#64748b;">
          Con esto la factura llega sola al correo del cliente (XML + PDF). Se recomienda un
          correo corporativo para no caer en spam; con Gmail usá una "contraseña de aplicación".
        </p>
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px;">
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Servidor SMTP</span>
            <InputText v-model="smtp.smtp_host" placeholder="smtp.gmail.com" fluid /></label>
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Puerto</span>
            <InputText v-model="smtp.smtp_port" fluid /></label>
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Cifrado</span>
            <Select v-model="smtp.smtp_encryption" :options="cifrados" optionLabel="label" optionValue="value" fluid /></label>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:10px;">
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Usuario</span>
            <InputText v-model="smtp.smtp_user" placeholder="facturas@suempresa.com" fluid /></label>
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Contraseña (vacía = no cambiar)</span>
            <Password v-model="smtp.smtp_password" :feedback="false" toggleMask fluid /></label>
        </div>
        <div style="display:flex; gap:8px; margin-top:12px;">
          <Button label="Guardar correo" icon="pi pi-save" size="small" @click="guardarSmtp" />
          <Button label="Enviar prueba" icon="pi pi-send" size="small" outlined :loading="probando" @click="probarSmtp" />
        </div>
      </div>
```

### 9.11 Botón "Enviar por correo" — en `src/views/Invoices.vue`

En el `<script setup>`:
```ts
const enviandoId = ref<number | null>(null)
async function enviarCorreo(row: any) {
  enviandoId.value = row.id
  try {
    const res = await api.post('/invoices/' + row.id + '/send-email')
    alert('Enviada a ' + res.data.enviado_a)
  } catch (err: any) {
    alert(err.response?.data?.error ?? 'No se pudo enviar.')
  } finally { enviandoId.value = null }
}
```
Y en la columna de acciones, junto al botón "Ver":
```html
        <Button icon="pi pi-envelope" size="small" text :loading="enviandoId === data.id"
                title="Enviar por correo" @click="enviarCorreo(data)" />
```

---

## Probar
1. EDocuments → Configuración de firma → llená el SMTP (con Gmail: `smtp.gmail.com`, 587, TLS,
   y una **contraseña de aplicación** — no la clave normal de la cuenta).
2. "Enviar prueba" a tu propio correo → debe llegar.
3. Asegurate de que el cliente demo tenga correo (Contactos → editá a Emily con tu correo real).
4. Emití una factura desde el POS → **debe llegarte sola** con el PDF y el XML adjuntos.
5. En Facturas, botón ✉️ para reenviar cualquier factura a mano.

## Lo que el cliente dijo, respondido
| Él pidió | Queda así |
|---|---|
| "Debe llegar al correo del que se le facturó" | Envío automático al emitir + reenvío manual |
| "Un correo tipo facturas@reset.com, o Gmail/Yahoo" | SMTP configurable por empresa, cualquiera |
| "Se registra el puerto, el SMTP" | Servidor, puerto, usuario, clave, TLS/SSL + botón de prueba |
| "Campo obligatorio" | Si el cliente no tiene correo, el sistema lo dice al enviar |
