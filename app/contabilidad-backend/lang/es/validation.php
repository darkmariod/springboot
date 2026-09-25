<?php

/**
 * Mensajes de validación en español.
 *
 * Sin este archivo Laravel devuelve la clave cruda ("validation.required") y el
 * usuario ve un texto que no significa nada, como si el sistema estuviera roto.
 */
return [
    'accepted' => 'Debe aceptar :attribute.',
    'after' => ':Attribute debe ser una fecha posterior a :date.',
    'alpha' => ':Attribute solo puede contener letras.',
    'alpha_num' => ':Attribute solo puede contener letras y números.',
    'array' => ':Attribute debe ser una lista.',
    'before' => ':Attribute debe ser una fecha anterior a :date.',
    'boolean' => ':Attribute debe ser verdadero o falso.',
    'confirmed' => ':Attribute no coincide con la confirmación.',
    'date' => ':Attribute no es una fecha válida.',
    'different' => ':Attribute y :other deben ser distintos.',
    'digits' => ':Attribute debe tener :digits dígitos.',
    'digits_between' => ':Attribute debe tener entre :min y :max dígitos.',
    'email' => ':Attribute debe ser un correo válido.',
    'exists' => ':Attribute no existe en el sistema.',
    'file' => ':Attribute debe ser un archivo.',
    'image' => ':Attribute debe ser una imagen.',
    'in' => ':Attribute no es un valor permitido.',
    'integer' => ':Attribute debe ser un número entero.',
    'max' => [
        'array' => ':Attribute no puede tener más de :max elementos.',
        'file' => ':Attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string' => ':Attribute no puede tener más de :max caracteres.',
    ],
    'mimes' => ':Attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':Attribute debe tener al menos :min elementos.',
        'file' => ':Attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':Attribute debe ser al menos :min.',
        'string' => ':Attribute debe tener al menos :min caracteres.',
    ],
    'numeric' => ':Attribute debe ser un número.',
    'present' => 'Falta :attribute.',
    'required' => 'Falta :attribute.',
    'required_if' => 'Falta :attribute cuando :other es :value.',
    'required_with' => 'Falta :attribute.',
    'same' => ':Attribute y :other deben coincidir.',
    'size' => [
        'array' => ':Attribute debe tener :size elementos.',
        'file' => ':Attribute debe pesar :size kilobytes.',
        'numeric' => ':Attribute debe ser :size.',
        'string' => ':Attribute debe tener :size caracteres.',
    ],
    'string' => ':Attribute debe ser texto.',
    'unique' => ':Attribute ya está registrado.',
    'uploaded' => 'No se pudo subir :attribute.',

    /**
     * Nombres de los campos tal como aparecen en pantalla. Sin esto el mensaje
     * diría "Falta descripcion" en vez de "Falta el nombre del artículo".
     */
    'attributes' => [
        'ruc' => 'el RUC',
        'razon_social' => 'la razón social',
        'nombre_comercial' => 'el nombre comercial',
        'dir_matriz' => 'la dirección de la matriz',
        'identificacion' => 'la identificación',
        'tipo_identificacion' => 'el tipo de identificación',
        'codigo' => 'el código del artículo',
        'descripcion' => 'el nombre del artículo',
        'precio' => 'el precio',
        'cantidad' => 'la cantidad',
        'costo' => 'el costo',
        'precio_unitario' => 'el precio unitario',
        'company_id' => 'la empresa',
        'contact_id' => 'el cliente',
        'product_id' => 'el artículo',
        'warehouse_id' => 'la bodega',
        'branch_id' => 'la sucursal',
        'emission_point_id' => 'el punto de emisión',
        'items' => 'el detalle',
        'series' => 'las series',
        'serie' => 'la serie',
        'estab' => 'el establecimiento',
        'punto' => 'el punto de emisión',
        'nombre' => 'el nombre',
        'email' => 'el correo',
        'password' => 'la contraseña',
        'rol' => 'el rol',
        'clave' => 'la clave',
        'certificado' => 'el certificado',
        'fecha' => 'la fecha',
        'fecha_emision' => 'la fecha de emisión',
        'forma_pago' => 'la forma de pago',
        'plan' => 'el plan',
        'estado' => 'el estado',
        'tipo' => 'el tipo',
        'logo' => 'el logo',
    ],
];
