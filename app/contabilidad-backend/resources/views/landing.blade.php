<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HasReset — Facturación Electrónica SRI</title>
    <style>
        :root {
            --brand: #1e5bb8; --brand-dk: #1a4a8c; --brand-soft: #e8f0fb;
            --ink: #16202e; --muted: #5a6675; --faint: #8592a3;
            --ground: #eef1f6; --card: #ffffff; --border: #e0e5ec;
            --good: #16a34a; --reco: #1e5bb8;
            --shadow: 0 1px 3px rgba(16,32,46,.06), 0 6px 24px rgba(16,32,46,.06);
            --shadow-reco: 0 4px 12px rgba(30,91,184,.20), 0 16px 40px rgba(30,91,184,.16);
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --brand: #4d9bff; --brand-dk: #2f6fd0; --brand-soft: #14243f;
                --ink: #e8edf4; --muted: #9aa7b8; --faint: #64748b;
                --ground: #0b1220; --card: #141d2c; --border: #263449;
                --good: #34d17f; --reco: #4d9bff;
                --shadow: 0 1px 3px rgba(0,0,0,.4); --shadow-reco: 0 8px 32px rgba(30,91,184,.4);
            }
        }
        :root[data-theme="dark"] {
            --brand: #4d9bff; --brand-dk: #2f6fd0; --brand-soft: #14243f;
            --ink: #e8edf4; --muted: #9aa7b8; --faint: #64748b;
            --ground: #0b1220; --card: #141d2c; --border: #263449;
            --good: #34d17f; --reco: #4d9bff;
            --shadow: 0 1px 3px rgba(0,0,0,.4); --shadow-reco: 0 8px 32px rgba(30,91,184,.4);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--ground); color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5; -webkit-font-smoothing: antialiased; padding: 0;
        }
        .wrap { max-width: 1140px; margin: 0 auto; padding: 40px 20px 56px; }

        /* Hero */
        .hero {
            text-align: center; padding: 80px 20px 60px;
            background: linear-gradient(135deg, var(--brand-soft) 0%, var(--ground) 100%);
        }
        .hero .logo { display: inline-flex; align-items: center; gap: 9px; font-weight: 800; font-size: 20px; letter-spacing: .2px; color: var(--brand); margin-bottom: 20px; }
        .hero .mark { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--brand), var(--brand-dk)); color: #fff; display: grid; place-items: center; font-size: 15px; font-weight: 800; }
        .hero h1 { font-size: 42px; margin: 0 0 16px; letter-spacing: -.5px; text-wrap: balance; line-height: 1.15; }
        .hero p { color: var(--muted); margin: 0; font-size: 18px; max-width: 600px; margin-inline: auto; }

        .head { text-align: center; margin-bottom: 38px; margin-top: 48px; }
        .head h2 { font-size: 30px; margin: 0 0 8px; letter-spacing: -.5px; }
        .head p { color: var(--muted); margin: 0; font-size: 15px; }

        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; align-items: start; }
        @media (max-width: 980px) { .grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }

        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: 16px;
            padding: 24px 20px; box-shadow: var(--shadow); display: flex; flex-direction: column;
            position: relative;
        }
        .card.reco { border-color: var(--reco); box-shadow: var(--shadow-reco); transform: translateY(-6px); }
        @media (max-width: 560px) { .card.reco { transform: none; } }
        .badge {
            position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
            background: var(--reco); color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .6px;
            text-transform: uppercase; padding: 5px 14px; border-radius: 999px; white-space: nowrap;
        }
        .tier { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--brand); }
        .pitch { font-size: 13px; color: var(--muted); margin: 4px 0 16px; min-height: 34px; }
        .price { display: flex; align-items: baseline; gap: 3px; margin-bottom: 2px; }
        .price .cur { font-size: 20px; font-weight: 700; color: var(--ink); }
        .price .amt { font-size: 40px; font-weight: 800; letter-spacing: -1px; font-variant-numeric: tabular-nums; }
        .price .per { font-size: 13px; color: var(--faint); }
        .price.custom .amt { font-size: 26px; }
        .subprice { font-size: 12px; color: var(--faint); margin-bottom: 18px; }

        .cta {
            display: block; text-align: center; padding: 11px; border-radius: 10px; font-weight: 700;
            font-size: 14px; border: 1.5px solid var(--brand); color: var(--brand); background: transparent;
            text-decoration: none; margin-bottom: 20px; cursor: pointer; transition: all .15s;
        }
        .cta:hover { background: var(--brand-soft); }
        .cta.solid { background: var(--brand); color: #fff; }
        .cta.solid:hover { background: var(--brand-dk); }

        .feats { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
        .feats li { display: flex; gap: 9px; font-size: 13px; align-items: flex-start; }
        .feats .ico { color: var(--good); flex-shrink: 0; font-weight: 800; line-height: 1.35; }
        .feats .star { color: var(--brand); }
        .feats .lead { font-weight: 700; color: var(--ink); }
        .limits { margin-top: 16px; padding-top: 14px; border-top: 1px dashed var(--border); display: flex; flex-direction: column; gap: 6px; }
        .limits li { display: flex; justify-content: space-between; font-size: 12.5px; color: var(--muted); }
        .limits b { color: var(--ink); font-variant-numeric: tabular-nums; }

        .note { text-align: center; color: var(--faint); font-size: 12.5px; margin-top: 30px; line-height: 1.7; }
        .note b { color: var(--muted); }

        /* Footer */
        .footer {
            text-align: center; padding: 32px 20px; margin-top: 48px;
            border-top: 1px solid var(--border); color: var(--faint); font-size: 13px;
        }
    </style>
</head>
<body>

    <div class="hero">
        <div class="logo"><span class="mark">HR</span> HasReset</div>
        <h1>Sistema de facturación electrónica y contabilidad para pymes en Ecuador</h1>
        <p>Facturación electrónica autorizada por el SRI · precios anuales + IVA · 15 días de prueba</p>
    </div>

    <div class="wrap">
        <div class="head">
            <h2>Planes de facturación e inventario</h2>
            <p>Elegí el plan que mejor se adapte a tu negocio</p>
        </div>

        <div class="grid">

            <!-- Emprendedor -->
            <div class="card">
                <div class="tier">Emprendedor</div>
                <div class="pitch">Organizá tus ventas y tu stock.</div>
                <div class="price"><span class="cur">$</span><span class="amt">49</span><span class="per">/ año</span></div>
                <div class="subprice">+ IVA · 1 usuario</div>
                <a class="cta" href="https://wa.me/5939900000000?text=Hola%20me%20interesa%20el%20plan%20Emprendedor" target="_blank" rel="noopener">Comenzar</a>
                <ul class="feats">
                    <li><span class="ico">✓</span> Punto de Venta (Caja / POS)</li>
                    <li><span class="ico">✓</span> Inventario y kardex</li>
                    <li><span class="ico">✓</span> Productos, clientes y categorías</li>
                    <li><span class="ico">✓</span> Ventas y reportes</li>
                    <li><span class="ico">✓</span> Ajustes de inventario</li>
                    <li><span class="ico">✓</span> Soporte</li>
                </ul>
                <ul class="limits">
                    <li>Productos <b>1.000</b></li>
                    <li>Clientes <b>1.000</b></li>
                    <li>Usuarios <b>1</b></li>
                    <li>Sucursales <b>1</b></li>
                </ul>
            </div>

            <!-- Negocio -->
            <div class="card">
                <div class="tier">Negocio</div>
                <div class="pitch">Empezá a facturar electrónicamente.</div>
                <div class="price"><span class="cur">$</span><span class="amt">99</span><span class="per">/ año</span></div>
                <div class="subprice">+ IVA · 3 usuarios</div>
                <a class="cta" href="https://wa.me/5939900000000?text=Hola%20me%20interesa%20el%20plan%20Negocio" target="_blank" rel="noopener">Comenzar</a>
                <ul class="feats">
                    <li><span class="ico star">★</span> <span class="lead">Todo lo de Emprendedor +</span></li>
                    <li><span class="ico">✓</span> <b>Facturación electrónica SRI</b></li>
                    <li><span class="ico star">★</span> Trae al cliente del SRI por RUC/cédula</li>
                    <li><span class="ico">✓</span> Certificado digital (.p12)</li>
                    <li><span class="ico">✓</span> Cotizaciones</li>
                    <li><span class="ico">✓</span> Compras</li>
                    <li><span class="ico">✓</span> Roles avanzados</li>
                </ul>
                <ul class="limits">
                    <li>Productos <b>Ilimitado</b></li>
                    <li>Clientes <b>Ilimitado</b></li>
                    <li>Documentos <b>Ilimitado</b></li>
                    <li>Sucursales <b>1</b></li>
                </ul>
            </div>

            <!-- Profesional (Recomendado) -->
            <div class="card reco">
                <span class="badge">Recomendado</span>
                <div class="tier">Profesional</div>
                <div class="pitch">Todos los comprobantes + inventario avanzado.</div>
                <div class="price"><span class="cur">$</span><span class="amt">149</span><span class="per">/ año</span></div>
                <div class="subprice">+ IVA · 6 usuarios</div>
                <a class="cta solid" href="https://wa.me/5939900000000?text=Hola%20me%20interesa%20el%20plan%20Profesional" target="_blank" rel="noopener">Comenzar</a>
                <ul class="feats">
                    <li><span class="ico star">★</span> <span class="lead">Todo lo de Negocio +</span></li>
                    <li><span class="ico">✓</span> Notas de crédito y débito</li>
                    <li><span class="ico">✓</span> Retenciones y guía de remisión</li>
                    <li><span class="ico">✓</span> Liquidación de compra + módulo tributario</li>
                    <li><span class="ico star">★</span> Series / garantías por unidad</li>
                    <li><span class="ico">✓</span> Fraccionamiento y conversión de artículos</li>
                    <li><span class="ico">✓</span> Conciliación bancaria y de tarjetas</li>
                    <li><span class="ico">✓</span> Importar compras del SRI (lote)</li>
                </ul>
                <ul class="limits">
                    <li>Usuarios <b>6</b></li>
                    <li>Sucursales <b>3</b></li>
                    <li>Documentos <b>Ilimitado</b></li>
                </ul>
            </div>

            <!-- Empresarial (Personalizado) -->
            <div class="card">
                <div class="tier">Empresarial</div>
                <div class="pitch">A la medida de tu equipo.</div>
                <div class="price custom"><span class="amt">Personalizado</span></div>
                <div class="subprice">Cotización a medida</div>
                <a class="cta" href="https://wa.me/5939900000000?text=Hola%20me%20interesa%20el%20plan%20Empresarial" target="_blank" rel="noopener">Solicitar cotización</a>
                <ul class="feats">
                    <li><span class="ico star">★</span> <span class="lead">Todo lo de Profesional +</span></li>
                    <li><span class="ico">✓</span> Nómina / rol de pagos</li>
                    <li><span class="ico">✓</span> Multiempresa</li>
                    <li><span class="ico">✓</span> Capacitación incluida</li>
                    <li><span class="ico">✓</span> Módulos corporativos a medida<br><span style="color:var(--faint)">(activos fijos, producción, presupuestos, centro de costo)</span></li>
                </ul>
                <ul class="limits">
                    <li>Usuarios <b>Ilimitado</b></li>
                    <li>Sucursales <b>Ilimitado</b></li>
                    <li>Documentos <b>Ilimitado</b></li>
                </ul>
            </div>

        </div>

        <p class="note">
            <b>★ Exclusivo de HasReset:</b> traer los datos del cliente directo del SRI y garantías por número de serie.<br>
            Todos los planes incluyen facturación con validez tributaria una vez activado el ambiente de producción del SRI.
        </p>
    </div>

    <footer class="footer">
        &copy; 2026 HasReset — Facturación electrónica SRI
    </footer>

</body>
</html>
