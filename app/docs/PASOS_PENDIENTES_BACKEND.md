> # ⛔ ARCHIVO SUPERADO — NO PEGUES DESDE ACÁ
> Todo esto está actualizado y corregido en **[FASES_COMPLETAS.md](FASES_COMPLETAS.md)**.
> Este archivo quedó con numeración vieja y datos sin corregir. Podés borrarlo.

# ⚠️ 2 pasos de backend que tenés que hacer vos

> Yo no puedo tocar el `.env` (está protegido) ni correr migraciones contra él.
> Son 2 minutos. Después ya probás todo.

---

## PASO 1 (OBLIGATORIO) — arreglar la ruta de la base de datos

Al mover el proyecto a `springboot/app/`, el `.env` quedó apuntando a la carpeta **vieja**
(`/Users/mariopazmino/Desktop/app/...`). Por eso el backend no arranca.

Abrí `contabilidad-backend/.env` y dejá esta línea con la ruta NUEVA:

```
DB_DATABASE=/Users/mariopazmino/Desktop/springboot/app/contabilidad-backend/database/database.sqlite
```

Verificá que funcione:
```bash
cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend
php artisan migrate
php artisan serve            # deja esto corriendo
```

> El archivo `database/database.sqlite` YA existe en la ruta nueva con todos tus datos.

---

## PASO 2 (opcional) — guardar el correo de envío de la firma

La pantalla **Firma electrónica** ya funciona con lo esencial (.p12 + clave), porque usa el
endpoint que ya existe. Si querés que además **guarde el correo y el ambiente**, hacé esto:

### 2.1 Migración — `database/migrations/2026_07_23_000001_add_signature_config.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('email_envio')->nullable();
            $t->string('cert_sujeto')->nullable();
            $t->date('cert_valido_hasta')->nullable();
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn(['email_envio','cert_sujeto','cert_valido_hasta']));
    }
};
```
> Este archivo YA lo creé en tu proyecto. Solo corré `php artisan migrate` después del PASO 1.

### 2.2 En `app/Models/Company.php` — agregá al `$fillable`:
```php
'email_envio', 'cert_sujeto', 'cert_valido_hasta',
```

### 2.3 En `app/Http/Controllers/CompanyController.php` — reemplazá `uploadCertificate` por:
```php
    public function uploadCertificate(\Illuminate\Http\Request $request, Company $company)
    {
        $request->validate([
            "certificado" => ["required", "file", "max:5120"],
            "clave" => ["required", "string"],
            "email_envio" => ["nullable", "email"],
            "ambiente" => ["nullable", "in:1,2"],
        ]);
        $contenido = file_get_contents($request->file("certificado")->getRealPath());
        if (! @openssl_pkcs12_read($contenido, $info, $request->input("clave"))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "clave" => ["El certificado no abre con esa clave, o el archivo no es un .p12 válido."],
            ]);
        }
        // Datos del certificado para mostrar titular y vencimiento en la pantalla
        $cert = openssl_x509_parse($info["cert"] ?? "");
        $company->update([
            "certificado_p12" => $contenido,
            "certificado_clave" => $request->input("clave"),
            "email_envio" => $request->input("email_envio") ?: $company->email_envio,
            "ambiente" => $request->input("ambiente") ?: $company->ambiente,
            "cert_sujeto" => $cert["subject"]["CN"] ?? null,
            "cert_valido_hasta" => isset($cert["validTo_time_t"]) ? date("Y-m-d", $cert["validTo_time_t"]) : null,
        ]);
        return ["ok" => true, "mensaje" => "Firma cargada y validada. La facturación ya firma y envía al SRI."];
    }
```

Con esto, la pantalla te muestra **"Firma cargada · vence 2027-07-14"** como en el video.
