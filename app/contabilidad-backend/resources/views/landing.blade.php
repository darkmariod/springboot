<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HasReset | Facturación electrónica SRI, inventario y contabilidad</title>
<meta name="description" content="Software de facturación electrónica autorizado por el SRI para pymes en Ecuador. Los seis comprobantes, inventario con series y contabilidad automática.">
<style>
  :root{
    --bg:#fff; --panel:#f5f8f9; --ink:#0f2430; --body:#48606e; --soft:#8fa2ad;
    --line:#dde7ec; --line-2:#eef3f6;
    --teal:#0f9b8e; --teal-dk:#0b7c72; --teal-soft:#e6f5f3;
    --deep:#0d5c58; --navy:#11212e; --gold:#e0b155;
    --mint:#e7f4f2; --mint-pill:#f2faf9; --mint-line:#cfe6e2;
    --mono:"SF Mono",ui-monospace,Menlo,Consolas,monospace;
  }
  @media (prefers-color-scheme:dark){:root{
    --bg:#0b1720; --panel:#101f29; --ink:#e6eef2; --body:#a2b5c0; --soft:#6d838f;
    --line:#20343f; --line-2:#182a34;
    --teal:#1cc0ae; --teal-dk:#15a294; --teal-soft:#0c2b29;
    --deep:#1cc0ae; --navy:#0a1620;
    --mint:#0e2725; --mint-pill:#123230; --mint-line:#1b3d39;
  }}
  *{box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{margin:0;background:var(--bg);color:var(--ink);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:inherit}
  .w{max-width:1140px;margin:0 auto;padding:0 24px}
  .mono{font-family:var(--mono);font-variant-numeric:tabular-nums}

  /* Barra */
  .bar{border-bottom:1px solid var(--line);background:var(--bg);position:sticky;top:0;z-index:20}
  .bar .w{display:flex;align-items:center;gap:28px;height:62px}
  .logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:16.5px;letter-spacing:-.2px}
  .logo i{width:26px;height:26px;background:var(--navy);color:#fff;border-radius:5px;display:grid;place-items:center;font-size:11px;font-weight:800;font-style:normal}
  .bar nav{display:flex;gap:22px;margin-left:auto}
  .bar nav a{text-decoration:none;color:var(--body);font-size:14px}
  .bar nav a:hover{color:var(--teal)}
  .bar .demo{font-size:14px;font-weight:600;text-decoration:none;border:1px solid var(--line);padding:8px 15px;border-radius:6px}
  .bar .demo:hover{border-color:var(--teal);color:var(--teal)}

  /* Encabezado */
  .head{padding:56px 0 52px;border-bottom:1px solid var(--line)}
  .head .w{display:grid;grid-template-columns:1.08fr .92fr;gap:54px;align-items:center}
  .kicker{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
    color:var(--teal-dk);background:var(--teal-soft);padding:5px 12px;border-radius:5px;margin-bottom:16px}
  .head h1{font-size:39px;line-height:1.14;letter-spacing:-1px;margin:0 0 14px;font-weight:700;max-width:15ch}
  .head .p{font-size:16.5px;color:var(--body);margin:0 0 20px;max-width:46ch}
  .head ul.check{list-style:none;padding:0;margin:0 0 22px}
  .head ul.check li{position:relative;padding-left:24px;color:var(--body);font-size:14.5px;margin-bottom:8px}
  .head ul.check li::before{content:"";position:absolute;left:0;top:7px;width:13px;height:8px;
    border-left:2px solid var(--teal);border-bottom:2px solid var(--teal);transform:rotate(-45deg)}
  .verplanes{font-size:14.5px;font-weight:600;color:var(--teal-dk);text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}
  .verplanes:hover{color:var(--teal)}

  /* Botones */
  .b{display:inline-block;padding:12px 24px;border-radius:6px;font-weight:600;font-size:15px;
    text-decoration:none;border:1px solid transparent;transition:background .16s,border-color .16s,color .16s}
  .b-main{background:var(--teal);color:#fff;border-color:var(--teal)}
  .b-main:hover{background:var(--teal-dk)}
  .b-line{border-color:var(--line);color:var(--ink)}
  .b-line:hover{border-color:var(--teal);color:var(--teal)}
  .b-wa{background:#25d366;color:#fff;border-color:#25d366;display:inline-flex;align-items:center;gap:9px;white-space:nowrap}
  .b-wa:hover{background:#1eb855;border-color:#1eb855}
  .b-wa svg{flex-shrink:0}

  /* Formulario de demo */
  .form{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:24px;
    box-shadow:0 16px 44px -26px rgba(15,36,48,.4)}
  .form h2{font-size:19px;margin:0 0 3px}
  .form .fp{font-size:13.5px;color:var(--body);margin:0 0 18px}
  .form label{display:block;font-size:12.5px;font-weight:600;color:var(--body);margin-bottom:5px}
  .form .fg{margin-bottom:13px}
  .form input,.form select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:6px;
    font-size:14.5px;font-family:inherit;background:var(--bg);color:var(--ink)}
  .form input:focus,.form select:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-soft)}
  .form button{width:100%;background:#25d366;color:#fff;border:0;padding:12px;border-radius:6px;
    font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
    transition:background .16s}
  .form button:hover{background:#1eb855}
  .form .nota{font-size:12px;color:var(--soft);text-align:center;margin:10px 0 0}

  /* Secciones */
  .s{padding:56px 0;border-bottom:1px solid var(--line)}
  .s.gray{background:var(--panel)}
  .sh{text-align:center;margin-bottom:36px}
  h2{font-size:27px;letter-spacing:-.6px;margin:0 0 8px;font-weight:700}
  .lead{color:var(--body);margin:0 auto;max-width:62ch}

  /* ── PLANES: bandas alternadas ── */
  .mint{background:var(--mint)}
  .switch{display:flex;align-items:center;justify-content:center;gap:14px;margin:0 0 8px}
  .switch span{font-size:12.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--soft);transition:color .18s}
  .switch span.on{color:var(--deep)}
  .tg{width:52px;height:28px;border-radius:999px;background:var(--teal);border:0;cursor:pointer;position:relative;flex-shrink:0;padding:0}
  .tg::after{content:"";position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:transform .22s cubic-bezier(.3,.8,.4,1)}
  .tg[data-per="semestral"]::after{transform:translateX(24px)}
  .off{background:#fff;color:var(--deep);font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.04em;box-shadow:0 1px 3px rgba(13,92,88,.14)}

  .pb{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center;padding:46px 0}
  .pb + .pb{border-top:1px solid var(--mint-line)}
  .pb.rev .txt{order:2}
  .pb.rev .art{order:1}
  .pill{display:inline-block;background:var(--mint-pill);color:var(--deep);
    font-size:25px;font-weight:800;letter-spacing:.02em;padding:12px 38px;border-radius:999px;margin-bottom:20px;
    box-shadow:7px 7px 16px rgba(13,92,88,.14), -7px -7px 16px rgba(255,255,255,.95)}
  .pb h3{font-size:29px;line-height:1.18;letter-spacing:-.7px;margin:0 0 12px;font-weight:700;max-width:16ch}
  .pb .desc{color:var(--body);font-size:14.5px;margin:0 0 18px;max-width:46ch}
  .pb ul{list-style:none;padding:0;margin:0 0 22px}
  .pb li{position:relative;padding-left:26px;margin-bottom:9px;font-size:14px;font-weight:600;color:var(--ink)}
  .pb li::after{content:"";position:absolute;left:0;top:2px;width:15px;height:15px;border-radius:50%;background:var(--teal)}
  .pb li::before{content:"";position:absolute;left:4.5px;top:6.5px;width:6px;height:3px;z-index:1;
    border-left:1.6px solid #fff;border-bottom:1.6px solid #fff;transform:rotate(-45deg)}
  .pb .pr{display:flex;align-items:baseline;gap:8px;margin:0 0 20px}
  .pb .pr b{font-size:36px;font-weight:800;letter-spacing:-1.2px;color:var(--deep)}
  .pb .pr b sup{font-size:18px;top:-.65em;position:relative}
  .pb .pr i{font-style:normal;font-size:13px;color:var(--body)}
  .pb .go{display:inline-block;background:var(--deep);color:#fff;padding:11px 26px;border-radius:6px;
    font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;text-decoration:none;transition:background .16s}
  .pb .go:hover{background:var(--teal-dk)}
  .art{display:grid;place-items:center}
  .art svg{width:100%;max-width:330px;height:auto}

  /* FAQ */
  .faq details{border-bottom:1px solid var(--line);padding:15px 0}
  .faq summary{cursor:pointer;font-weight:600;font-size:15.5px;list-style:none;display:flex;justify-content:space-between;gap:16px;transition:color .14s}
  .faq summary::-webkit-details-marker{display:none}
  .faq summary::after{content:"+";color:var(--teal);font-weight:700;font-size:19px;line-height:1}
  .faq details[open] summary{color:var(--teal-dk)}
  .faq details[open] summary::after{content:"–"}
  .faq summary:hover{color:var(--teal)}
  .faq p{color:var(--body);margin:11px 0 0;font-size:14.5px;max-width:74ch}

  .end{background:var(--navy);color:#fff;padding:48px 0;text-align:center}
  .end h2{color:#fff;margin-bottom:6px}
  .end p{color:#b0c4d0;margin:0 auto 22px;max-width:56ch}
  footer{border-top:1px solid var(--line);padding:22px 0;color:var(--soft);font-size:13px}
  footer .w{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}

  /* ── Responsive ── */
  /* Tablet: encabezado y bandas de planes pasan a una sola columna. */
  @media(max-width:900px){
    .head .w{grid-template-columns:1fr;gap:32px}
    .pb{grid-template-columns:1fr;gap:26px;padding:36px 0}
    /* En una columna el texto va siempre primero y la imagen debajo. */
    .pb.rev .txt,.pb.rev .art{order:0}
    .art svg{max-width:270px}
  }
  /* Teléfono */
  @media(max-width:720px){
    .w{padding:0 18px}
    .bar .w{gap:14px}
    .bar nav{display:none}
    .head{padding:38px 0 36px}
    .head h1{font-size:29px;max-width:none}
    .head .p{font-size:15.5px;max-width:none}
    h2{font-size:23px}
    .s{padding:42px 0}
    .form{padding:20px}
    .pill{font-size:20px;padding:10px 26px;margin-bottom:16px}
    .pb h3{font-size:23px;max-width:none}
    .pb .desc{max-width:none}
    .pb{padding:32px 0}
    .switch{flex-wrap:wrap;gap:10px 12px}
    .off{order:3;flex-basis:100%;width:fit-content;margin:2px auto 0}
    .art svg{max-width:230px}
    footer .w{flex-direction:column;text-align:center}
  }
  /* Teléfono angosto */
  @media(max-width:400px){
    .head h1{font-size:26px}
    .pill{font-size:18px;padding:9px 22px}
    .pb h3{font-size:21px}
    .art svg{max-width:200px}
    .b{width:100%;justify-content:center}
  }
</style>
</head>
<body>

<div class="bar"><div class="w">
  <div class="logo"><i>HR</i> HasReset</div>
  <nav>
    <a href="#planes">Planes</a>
    <a href="#faq">Preguntas</a>
  </nav>
  <a class="demo" href="#contacto">Solicitar demo</a>
</div></div>

<!-- ENCABEZADO -->
<section class="head"><div class="w">
  <div>
    <span class="kicker">Para pymes en Ecuador</span>
    <h1>Factura al SRI y cuadra tu contabilidad</h1>
    <p class="p">Un solo sistema para emitir tus comprobantes electrónicos, llevar el inventario y generar los asientos contables del día.</p>
    <ul class="check">
      <li>Firma con tu certificado <span class="mono">.p12</span> y envío automático al SRI</li>
      <li>Los datos del cliente se traen del SRI con el RUC o la cédula</li>
      <li>Cada venta descarga el inventario y genera su asiento</li>
      <li>Reportes para los formularios 103 y 104</li>
    </ul>
    <a class="verplanes" href="#planes">Ver planes y precios</a>
  </div>

  <div class="form">
    <h2>Solicita una demo</h2>
    <p class="fp">Te mostramos el sistema funcionando con un caso parecido al tuyo.</p>
    <div class="fg">
      <label for="f-nom">Tu nombre</label>
      <input id="f-nom" type="text" placeholder="Nombre y apellido">
    </div>
    <div class="fg">
      <label for="f-neg">Nombre del negocio</label>
      <input id="f-neg" type="text" placeholder="Ferretería, tienda, consultorio…">
    </div>
    <div class="fg">
      <label for="f-nec">¿Qué necesitas?</label>
      <select id="f-nec">
        <option>Empezar a facturar electrónicamente</option>
        <option>Cambiar del sistema que uso hoy</option>
        <option>Controlar mi inventario</option>
        <option>Llevar la contabilidad</option>
        <option>Todavía tengo dudas</option>
      </select>
    </div>
    <button id="f-env" type="button">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.5 14.1c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.8-4.4-4-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 1.9c.1.2.1.3 0 .5l-.3.5-.3.3c-.1.1-.3.3-.1.6.1.3.6 1.1 1.4 1.8 1 .8 1.7 1.1 2 1.2.2.1.4.1.6-.1l.8-.9c.2-.2.4-.2.6-.1l1.8.9c.2.1.4.2.5.3 0 .1 0 .7-.2 1.3z"/></svg>
      Enviar por WhatsApp
    </button>
    <p class="nota">Te respondemos el mismo día.</p>
  </div>
</div></section>

<!-- PLANES -->
<section class="s mint" id="planes"><div class="w">
  <div class="sh">
    <h2>Planes</h2>
    <p class="lead">Elige la forma de pago. Los módulos se activan al momento de contratar.</p>
  </div>

  <div class="switch">
    <span id="lb-anual" class="on">Anual</span>
    <button class="tg" id="tg" data-per="anual" aria-label="Cambiar entre pago anual y semestral"></button>
    <span id="lb-sem">Semestral</span>
    <span class="off">15% menos al año</span>
  </div>

  <!-- EMPRENDEDOR -->
  <div class="pb">
    <div class="txt">
      <span class="pill">EMPRENDEDOR</span>
      <h3>Para quienes empiezan y necesitan orden</h3>
      <p class="desc">¿Recién arrancas? Lleva el control de tus ventas y tu stock sin complicarte, y haz crecer tu negocio con la información al día.</p>
      <ul>
        <li>Punto de venta y cierres de caja</li>
        <li>Inventario y kárdex por producto</li>
        <li>Clientes, productos y reportes</li>
      </ul>
      <div class="pr"><b><sup>$</sup><span class="v" data-anual="49" data-sem="29">49</span></b><i class="per">al año + IVA</i></div>
      <a class="go" href="#contacto">Consulta aquí</a>
    </div>
    <div class="art">
      <svg viewBox="0 0 300 220" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="34" y="52" width="86" height="132" rx="9" fill="#dfeeeb" stroke="#0d5c58" stroke-width="2.4"/>
        <rect x="46" y="64" width="62" height="26" rx="4" fill="#fff" stroke="#0d5c58" stroke-width="2"/>
        <g fill="#0f9b8e"><rect x="46" y="100" width="17" height="15" rx="3"/><rect x="68" y="100" width="17" height="15" rx="3"/><rect x="91" y="100" width="17" height="15" rx="3"/>
        <rect x="46" y="122" width="17" height="15" rx="3"/><rect x="68" y="122" width="17" height="15" rx="3"/><rect x="91" y="122" width="17" height="15" rx="3"/>
        <rect x="46" y="144" width="17" height="15" rx="3"/><rect x="68" y="144" width="17" height="15" rx="3"/><rect x="91" y="144" width="17" height="32" rx="3"/></g>
        <path d="M132 40h74v150l-12-9-12 9-12-9-13 9-12-9-13 9z" fill="#fff" stroke="#0d5c58" stroke-width="2.4" stroke-linejoin="round"/>
        <path d="M146 62h46M146 78h46M146 94h30" stroke="#8fb8b3" stroke-width="3" stroke-linecap="round"/>
        <path d="M146 118h46M146 134h34" stroke="#8fb8b3" stroke-width="3" stroke-linecap="round"/>
        <rect x="206" y="28" width="62" height="120" rx="10" fill="#0d5c58"/>
        <rect x="213" y="42" width="48" height="92" rx="5" fill="#fff"/>
        <circle cx="237" cy="72" r="15" fill="none" stroke="#0f9b8e" stroke-width="3"/>
        <path d="M231 72l4.5 4.5 9-9" stroke="#0f9b8e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M222 98h30M222 110h20" stroke="#cfe0dd" stroke-width="3.4" stroke-linecap="round"/>
      </svg>
    </div>
  </div>

  <!-- NEGOCIO -->
  <div class="pb rev">
    <div class="txt">
      <span class="pill">NEGOCIO</span>
      <h3>Diseñado para ti y tu negocio que va creciendo</h3>
      <p class="desc">Empieza a emitir tus comprobantes electrónicos al SRI con tu propia firma, y registra tus compras sin volver a digitar nada.</p>
      <ul>
        <li>Plan Emprendedor +</li>
        <li>Facturación electrónica al SRI</li>
        <li>Compras e importación por lote</li>
        <li>Cotizaciones y cuentas por cobrar</li>
      </ul>
      <div class="pr"><b><sup>$</sup><span class="v" data-anual="99" data-sem="59">99</span></b><i class="per">al año + IVA</i></div>
      <a class="go" href="#contacto">Consulta aquí</a>
    </div>
    <div class="art">
      <svg viewBox="0 0 300 220" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="44" width="204" height="128" rx="10" fill="#0d5c58"/>
        <rect x="50" y="54" width="184" height="108" rx="5" fill="#fff"/>
        <path d="M24 172h236l-14 20H38z" fill="#dfeeeb" stroke="#0d5c58" stroke-width="2.4" stroke-linejoin="round"/>
        <path d="M68 138V112M96 138V92M124 138V120M152 138V78M180 138V100M208 138V64" stroke="#0f9b8e" stroke-width="8" stroke-linecap="round"/>
        <path d="M64 96l32-30 28 22 30-34 30 18 28-30" stroke="#0d5c58" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="212" cy="42" r="5" fill="#0d5c58"/>
        <path d="M198 34h28v18" stroke="#0d5c58" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" opacity=".35"/>
      </svg>
    </div>
  </div>

  <!-- PROFESIONAL -->
  <div class="pb">
    <div class="txt">
      <span class="pill">PROFESIONAL</span>
      <h3>Para negocios que requieren más control</h3>
      <p class="desc">Si ya estás obligado a llevar contabilidad, aquí tienes el sistema integrado: los seis comprobantes, series para garantías y tus libros al día.</p>
      <ul>
        <li>Plan Negocio +</li>
        <li>Los seis comprobantes del SRI</li>
        <li>Series y garantías por unidad</li>
        <li>Contabilidad, balances y mayores</li>
        <li>Bancos y conciliaciones</li>
      </ul>
      <div class="pr"><b><sup>$</sup><span class="v" data-anual="149" data-sem="89">149</span></b><i class="per">al año + IVA</i></div>
      <a class="go" href="#contacto">Consulta aquí</a>
    </div>
    <div class="art">
      <svg viewBox="0 0 300 220" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="26" width="112" height="168" rx="13" fill="#0d5c58"/>
        <rect x="48" y="40" width="96" height="140" rx="6" fill="#fff"/>
        <path d="M60 60h56M60 74h72M60 88h44" stroke="#cfe0dd" stroke-width="4" stroke-linecap="round"/>
        <rect x="60" y="104" width="72" height="30" rx="5" fill="#e6f5f3"/>
        <path d="M70 119h20" stroke="#0f9b8e" stroke-width="3.4" stroke-linecap="round"/>
        <path d="M60 148h56M60 162h36" stroke="#cfe0dd" stroke-width="4" stroke-linecap="round"/>
        <rect x="142" y="86" width="122" height="78" rx="9" fill="#0f9b8e" transform="rotate(-9 142 86)"/>
        <path d="M141 112l120-19" stroke="#fff" stroke-width="10" opacity=".65"/>
        <rect x="156" y="132" width="30" height="9" rx="3" fill="#fff" opacity=".85" transform="rotate(-9 156 132)"/>
        <circle cx="236" cy="140" r="11" fill="#fff" opacity=".9" transform="rotate(-9 236 140)"/>
        <circle cx="250" cy="138" r="11" fill="#fff" opacity=".55" transform="rotate(-9 250 138)"/>
      </svg>
    </div>
  </div>

  <!-- EMPRESARIAL -->
  <div class="pb rev">
    <div class="txt">
      <span class="pill">EMPRESARIAL</span>
      <h3>Para empresas consolidadas con varias sucursales</h3>
      <p class="desc">Lleva tu operación al siguiente nivel: nómina, varias sucursales y los módulos que tu giro de negocio necesite.</p>
      <ul>
        <li>Plan Profesional +</li>
        <li>Nómina y rol de pagos</li>
        <li>Varias sucursales y bodegas</li>
        <li>Módulos a la medida</li>
        <li>Capacitación incluida</li>
      </ul>
      <div class="pr"><b style="font-size:26px">A convenir</b><i class="per">según tu negocio</i></div>
      <a class="go" href="#contacto">Cotizar</a>
    </div>
    <div class="art">
      <svg viewBox="0 0 300 220" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="30" y="34" width="238" height="146" rx="11" fill="#0d5c58"/>
        <rect x="40" y="46" width="218" height="122" rx="5" fill="#fff"/>
        <rect x="52" y="58" width="60" height="98" rx="5" fill="#e6f5f3"/>
        <path d="M62 74h40M62 88h30M62 102h36M62 116h26" stroke="#0f9b8e" stroke-width="3.4" stroke-linecap="round" opacity=".75"/>
        <rect x="122" y="58" width="124" height="42" rx="5" fill="#f2faf9"/>
        <path d="M134 88V72M152 88V64M170 88V78M188 88V68M206 88V60M224 88V74" stroke="#0f9b8e" stroke-width="6" stroke-linecap="round"/>
        <rect x="122" y="110" width="124" height="46" rx="5" fill="#f2faf9"/>
        <path d="M134 124h100M134 138h72" stroke="#cfe0dd" stroke-width="4.4" stroke-linecap="round"/>
        <path d="M110 180h78v10h-78z" fill="#dfeeeb" stroke="#0d5c58" stroke-width="2.2"/>
        <path d="M96 196h106" stroke="#0d5c58" stroke-width="3" stroke-linecap="round"/>
      </svg>
    </div>
  </div>
</div></section>

<!-- FAQ -->
<section class="s faq" id="faq"><div class="w">
  <div class="sh"><h2>Preguntas frecuentes</h2></div>
  <details open>
    <summary>¿Necesito firma electrónica para facturar?</summary>
    <p>Sí. El SRI exige un certificado de firma electrónica vigente (archivo .p12) a nombre del contribuyente. Lo emiten entidades como Security Data, Banco Central o Uanataca. Tú lo cargas una sola vez en el sistema y desde ahí la firma es automática.</p>
  </details>
  <details>
    <summary>¿Qué necesito además del certificado?</summary>
    <p>Tu RUC habilitado para facturación electrónica y los puntos de emisión registrados en el portal del SRI. Si aún no lo tienes, te indicamos el trámite.</p>
  </details>
  <details>
    <summary>¿Se instala algo en mi computadora?</summary>
    <p>No. El sistema funciona en el navegador, así que puedes entrar desde el local, la casa o el celular. Solo necesitas conexión a internet.</p>
  </details>
  <details>
    <summary>¿Qué pasa si el SRI no responde?</summary>
    <p>El comprobante queda guardado y firmado. El sistema vuelve a consultar la autorización y actualiza el estado apenas el servicio del SRI responde, sin que pierdas la venta.</p>
  </details>
  <details>
    <summary>¿Puedo probarlo antes de contratar?</summary>
    <p>Sí. Configuramos tu empresa en el ambiente de pruebas del SRI, donde los comprobantes se emiten y autorizan igual pero sin validez tributaria. Cuando estés conforme, pasamos a producción.</p>
  </details>
  <details>
    <summary>¿Mi contador puede acceder?</summary>
    <p>Sí. Puedes crear usuarios con permisos por módulo, de manera que tu contador vea la información contable sin tocar la operación diaria.</p>
  </details>
</div></section>

<section class="end" id="contacto"><div class="w">
  <h2>Conversemos sobre tu negocio</h2>
  <p>Te mostramos el sistema funcionando con un caso parecido al tuyo y resolvemos tus dudas sobre la facturación electrónica.</p>
  <a class="b b-wa" href="https://wa.me/593000000000" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.5 14.1c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.8-4.4-4-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 1.9c.1.2.1.3 0 .5l-.3.5-.3.3c-.1.1-.3.3-.1.6.1.3.6 1.1 1.4 1.8 1 .8 1.7 1.1 2 1.2.2.1.4.1.6-.1l.8-.9c.2-.2.4-.2.6-.1l1.8.9c.2.1.4.2.5.3 0 .1 0 .7-.2 1.3z"/></svg>Escribir por WhatsApp</a>
</div></section>

<footer><div class="w">
  <span>HasReset · Facturación electrónica autorizada por el SRI</span>
  <span>Riobamba, Ecuador</span>
</div></footer>

<script>
  // Selector anual / semestral: cambia los precios de todas las tarjetas.
  (function () {
    var tg = document.getElementById('tg');
    if (!tg) return;
    var anual = document.getElementById('lb-anual');
    var sem = document.getElementById('lb-sem');

    function pintar(periodo) {
      tg.dataset.per = periodo;
      anual.classList.toggle('on', periodo === 'anual');
      sem.classList.toggle('on', periodo === 'semestral');

      document.querySelectorAll('.pb .v').forEach(function (el) {
        el.textContent = periodo === 'anual' ? el.dataset.anual : el.dataset.sem;
      });
      document.querySelectorAll('.pb .per').forEach(function (el) {
        if (el.textContent.indexOf('según') !== -1) return;   // el plan a convenir no cambia
        el.textContent = periodo === 'anual' ? 'al año + IVA' : 'cada 6 meses + IVA';
      });
    }

    tg.addEventListener('click', function () {
      pintar(tg.dataset.per === 'anual' ? 'semestral' : 'anual');
    });
  })();
</script>
<script>
  // Arma el mensaje de WhatsApp con lo que escribió la persona.
  (function () {
    var btn = document.getElementById('f-env');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var nombre = document.getElementById('f-nom').value.trim();
      var negocio = document.getElementById('f-neg').value.trim();
      var necesita = document.getElementById('f-nec').value;
      var txt = 'Hola, quiero una demo de HasReset.';
      if (nombre) txt += ' Soy ' + nombre + '.';
      if (negocio) txt += ' Mi negocio es ' + negocio + '.';
      txt += ' Necesito: ' + necesita + '.';
      window.open('https://wa.me/593000000000?text=' + encodeURIComponent(txt), '_blank', 'noopener');
    });
  })();
</script>
</body>
</html>
