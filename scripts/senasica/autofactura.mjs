// ============================================================================
// Autofactura SENASICA (Playwright)
// ----------------------------------------------------------------------------
// Uso:  node autofactura.mjs '<json>'
// JSON de entrada:
//   url, urlLogin, cuenta {email, password}, numOperacion, llavePago,
//   receptor {nombre, rfc, regimen, uso_cfdi, codigo_postal, correo, metodo_pago},
//   dirDescarga, headless, debugDir (opcional)
//
// Imprime en la ÚLTIMA línea de stdout un JSON:
//   { estado: "timbrada" | "ya_timbrada" | "no_disponible" | "error",
//     mensaje, pdf, xml }
//
// El portal no tiene API pública: se maneja como lo haría una persona
// (llenar operación + llave, Agregar, Siguiente, datos del receptor,
// Crear Factura, Descargar). Los campos se buscan por su texto visible,
// no por IDs internos, para aguantar cambios menores del portal.
// ============================================================================
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const p = JSON.parse(process.argv[2] || '{}');

// Mensajes del portal (ajustables si el portal usa otras palabras)
const RE_YA_TIMBRADA = /ya\s+(fue|ha\s+sido|se\s+encuentra|est[aá]|se\s+ha)\s*(factur|timbr)|previamente\s+(factur|timbr)|ya\s+(existe|cuenta\s+con|tiene)\s.*factura|factura\s+(existente|ya\s+generada)|ya\s+facturad/i;
const RE_NO_DISPONIBLE = /48\s*h|h[aá]biles|a[uú]n\s+no|todav[ií]a\s+no|no\s+(se\s+)?(encontr|exist|localiz)|no\s+es\s+v[aá]lid|incorrect|inv[aá]lid|no\s+corresponde|no\s+disponible/i;
const RE_DESCARGA_LISTA = /ya\s+puede\s+descargar/i;

const SELECTOR_MENSAJES = [
  '.alert', '.swal2-title', '.swal2-html-container', '.sweet-alert p', '.sweet-alert h2',
  '.modal.show .modal-body', '.modal.in .modal-body', '.toast-body', '.toast-message',
  '.text-danger', '.errorlist', '.messages li', '.help-block', '.invalid-feedback'
].join(', ');

let paso = 0;
const dialogos = [];

function terminar(resultado) {
  process.stdout.write('\n' + JSON.stringify(resultado) + '\n');
}

async function evidencia(page, nombre) {
  if (!p.debugDir) return;
  paso++;
  fs.mkdirSync(p.debugDir, { recursive: true });
  const base = path.join(p.debugDir, String(paso).padStart(2, '0') + '_' + nombre);
  try { await page.screenshot({ path: base + '.png', fullPage: true }); } catch (e) {}
  try { fs.writeFileSync(base + '.html', await page.content()); } catch (e) {}
}

async function mensajesVisibles(page) {
  try {
    return await page.locator(SELECTOR_MENSAJES).evaluateAll(nodos =>
      nodos
        .filter(n => n.offsetParent !== null)
        .map(n => (n.innerText || '').replace(/\s+/g, ' ').trim())
        .filter(t => t.length > 0)
    );
  } catch (e) {
    return [];
  }
}

// Todas las líneas de texto visibles de la página
async function lineasVisibles(page) {
  try {
    return await page.evaluate(() => (document.body.innerText || '')
      .split('\n')
      .map(t => t.replace(/\s+/g, ' ').trim())
      .filter(t => t.length > 0));
  } catch (e) {
    return [];
  }
}

const RE_TRANSITORIO = /cargando|procesando|espere|loading|enviando/i;

// Busca un campo por su etiqueta visible: <label for>, placeholder o el primer
// input/select que sigue al texto de la etiqueta.
async function campoPorEtiqueta(page, etiqueta) {
  let loc = page.getByLabel(etiqueta);
  if (await loc.count() > 0 && await loc.first().isVisible().catch(() => false)) return loc.first();

  loc = page.getByPlaceholder(etiqueta);
  if (await loc.count() > 0 && await loc.first().isVisible().catch(() => false)) return loc.first();

  const textos = page.getByText(etiqueta);
  const n = await textos.count();
  for (let i = 0; i < n; i++) {
    const t = textos.nth(i);
    if (!(await t.isVisible().catch(() => false))) continue;
    const sig = t.locator('xpath=following::*[self::input[not(@type="hidden")] or self::select or self::textarea][1]');
    if (await sig.count() > 0 && await sig.first().isVisible().catch(() => false)) return sig.first();
  }
  return null;
}

async function llenar(page, etiqueta, valor, { opcional = false, soloSiVacio = false } = {}) {
  if (valor === undefined || valor === null || String(valor).trim() === '') return false;

  const el = await campoPorEtiqueta(page, etiqueta);
  if (!el) {
    if (opcional) return false;
    throw new Error('No encontré el campo "' + etiqueta + '" en el portal.');
  }

  const tag = await el.evaluate(nodo => nodo.tagName.toLowerCase());
  const texto = String(valor).trim();

  if (tag === 'select') {
    if (soloSiVacio) {
      const actual = await el.inputValue().catch(() => '');
      if (actual) return false;
    }
    const opciones = await el.locator('option').evaluateAll(os => os.map(o => ({ v: o.value, t: (o.textContent || '').trim() })));
    const buscado = texto.toUpperCase();
    const opcion = opciones.find(o => o.v.toUpperCase() === buscado)
      || opciones.find(o => o.t.toUpperCase().startsWith(buscado))
      || opciones.find(o => o.t.toUpperCase().includes(buscado));
    if (!opcion) {
      if (opcional) return false;
      throw new Error('La opción "' + texto + '" no existe en "' + etiqueta + '". Opciones: ' + opciones.map(o => o.t).join(' | '));
    }
    await el.selectOption(opcion.v);
  } else {
    if (soloSiVacio) {
      const actual = await el.inputValue().catch(() => '');
      if (actual && actual.trim() !== '') return false;
    }
    await el.fill(texto);
    await el.dispatchEvent('change').catch(() => {});
    await el.press('Tab').catch(() => {});
  }
  return true;
}

async function clic(page, texto) {
  const candidatos = [
    page.getByRole('button', { name: texto }),
    page.getByRole('link', { name: texto }),
    page.locator('input[type="submit"], input[type="button"]').filter({ hasText: texto }),
    page.getByText(texto, { exact: false }),
  ];
  for (const loc of candidatos) {
    const n = await loc.count();
    for (let i = 0; i < n; i++) {
      const el = loc.nth(i);
      if (await el.isVisible().catch(() => false)) {
        await el.click();
        return true;
      }
    }
  }
  throw new Error('No encontré el botón "' + texto + '" en el portal.');
}

// Espera hasta que aparezca algo: el localizador "exito" o un mensaje nuevo del portal
async function esperarResultado(page, exito, mensajesBase, timeoutMs, lineasBase = null) {
  const limite = Date.now() + timeoutMs;
  const dialogosIniciales = dialogos.length;

  const textoNuevo = async () => {
    if (!lineasBase) return [];
    return (await lineasVisibles(page)).filter(l => !lineasBase.has(l) && !RE_TRANSITORIO.test(l));
  };

  while (Date.now() < limite) {
    if (exito && await exito.count() > 0 && await exito.first().isVisible().catch(() => false)) {
      return { tipo: 'exito', texto: '' };
    }

    if (dialogos.length > dialogosIniciales) {
      return { tipo: 'mensaje', texto: dialogos.slice(dialogosIniciales).join(' | ') };
    }

    const nuevos = (await mensajesVisibles(page)).filter(m => !mensajesBase.has(m));
    if (nuevos.length > 0) {
      return { tipo: 'mensaje', texto: nuevos.join(' | ') };
    }

    // Texto nuevo en cualquier parte de la página (mensajes con clases que no conocemos)
    const lineas = await textoNuevo();
    if (lineas.length > 0) {
      await page.waitForTimeout(1500); // dejamos que termine de pintar
      if (exito && await exito.count() > 0 && await exito.first().isVisible().catch(() => false)) {
        return { tipo: 'exito', texto: '' };
      }
      const confirmadas = await textoNuevo();
      if (confirmadas.length > 0) {
        return { tipo: 'mensaje', texto: confirmadas.slice(0, 5).join(' | ') };
      }
    }

    await page.waitForTimeout(500);
  }
  return { tipo: 'timeout', texto: '' };
}

// Valor que quedó en un campo (para el diagnóstico)
async function valorDeCampo(page, etiqueta) {
  const el = await campoPorEtiqueta(page, etiqueta);
  if (!el) return '(campo no encontrado)';
  return await el.inputValue().catch(() => '(sin valor)');
}

function esArchivoValido(ruta, tipo) {
  try {
    const cabeza = fs.readFileSync(ruta).subarray(0, 600).toString('utf8');
    return tipo === 'pdf' ? cabeza.startsWith('%PDF') : /<\?xml|cfdi:Comprobante|<Comprobante/i.test(cabeza);
  } catch (e) {
    return false;
  }
}

// Devuelve el elemento clicable (a / button) del texto indicado: él mismo, su contenedor o el siguiente
async function elementoDescarga(page, textoEnlace) {
  const candidatos = [
    page.getByRole('link', { name: textoEnlace }),
    page.getByRole('button', { name: textoEnlace }),
  ];
  for (const loc of candidatos) {
    const n = await loc.count();
    for (let i = 0; i < n; i++) {
      if (await loc.nth(i).isVisible().catch(() => false)) return loc.nth(i);
    }
  }

  const textos = page.getByText(textoEnlace);
  const n = await textos.count();
  for (let i = 0; i < n; i++) {
    const t = textos.nth(i);
    if (!(await t.isVisible().catch(() => false))) continue;

    const contenedor = t.locator('xpath=ancestor-or-self::*[self::a or self::button][1]');
    if (await contenedor.count() > 0) return contenedor.first();

    const siguiente = t.locator('xpath=following::*[self::a or self::button][1]');
    if (await siguiente.count() > 0 && await siguiente.first().isVisible().catch(() => false)) return siguiente.first();

    return t; // último recurso: el texto mismo
  }
  return null;
}

// Para el diagnóstico: cómo son los enlaces de descarga en el portal
async function diagnosticoEnlaces(page) {
  const partes = [];
  for (const [nombre, re] of [['PDF', /archivo\s+pdf/i], ['XML', /archivo\s+xml/i]]) {
    const el = await elementoDescarga(page, re);
    if (!el) { partes.push(nombre + ': no encontrado'); continue; }
    const html = await el.evaluate(n => n.outerHTML).catch(() => '');
    partes.push(nombre + ': ' + html.replace(/\s+/g, ' ').slice(0, 250));
  }
  return partes.join(' || ');
}

async function seccionDescargaVisible(page) {
  const listo = page.getByText(RE_DESCARGA_LISTA);
  return (await listo.count() > 0) && await listo.first().isVisible().catch(() => false);
}

// Descarga un archivo del portal: por href directo, por evento de descarga o por pestaña nueva
async function descargar(page, context, textoEnlace, destino, tipo) {
  const enlace = await elementoDescarga(page, textoEnlace);
  if (!enlace) return null;

  // 1. href directo
  const href = await enlace.getAttribute('href').catch(() => null);
  if (href && href !== '#' && !href.startsWith('javascript')) {
    const url = new URL(href, page.url()).toString();
    const resp = await context.request.get(url);
    if (resp.ok()) {
      fs.writeFileSync(destino, await resp.body());
      if (esArchivoValido(destino, tipo)) return destino;
    }
  }

  // 2. evento de descarga o pestaña nueva
  const esperaDescarga = page.waitForEvent('download', { timeout: 45000 }).catch(() => null);
  const esperaPestana = context.waitForEvent('page', { timeout: 45000 }).catch(() => null);
  await enlace.click();
  const res = await Promise.race([esperaDescarga, esperaPestana]);

  if (res && typeof res.saveAs === 'function') {
    await res.saveAs(destino);
  } else if (res && typeof res.url === 'function') {
    await res.waitForLoadState().catch(() => {});
    const resp = await context.request.get(res.url());
    fs.writeFileSync(destino, await resp.body());
    await res.close().catch(() => {});
  } else {
    return null;
  }

  return esArchivoValido(destino, tipo) ? destino : null;
}

async function descargarAmbos(page, context) {
  fs.mkdirSync(p.dirDescarga, { recursive: true });
  const pdf = await descargar(page, context, /archivo\s+pdf/i, path.join(p.dirDescarga, p.llavePago + '.pdf'), 'pdf');
  const xml = await descargar(page, context, /archivo\s+xml/i, path.join(p.dirDescarga, p.llavePago + '.xml'), 'xml');
  return { pdf, xml };
}

async function iniciarSesion(page) {
  await page.goto(p.urlLogin, { waitUntil: 'networkidle', timeout: 60000 });
  await evidencia(page, 'login');
  await llenar(page, /correo|e-?mail|usuario/i, p.cuenta.email);
  await llenar(page, /contrase[ñn]a|password/i, p.cuenta.password);
  await clic(page, /ingresar|iniciar\s+sesi[oó]n|entrar|acceder/i);
  await page.waitForLoadState('networkidle').catch(() => {});
  await evidencia(page, 'login_resultado');
}

async function main() {
  let browser;
  try {
    const opciones = { headless: p.headless !== false };
    if (p.canal) opciones.channel = p.canal; // 'chrome' para usar el Chrome instalado
    browser = await chromium.launch(opciones);
  } catch (e) {
    return terminar({
      estado: 'error',
      mensaje: 'No se pudo abrir el navegador. Ejecuta "npx playwright install chromium" en la carpeta del proyecto. Detalle: '
        + String(e && e.message ? e.message : e).split('\n')[0],
      pdf: null,
      xml: null
    });
  }
  const context = await browser.newContext({ acceptDownloads: true, locale: 'es-MX' });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  page.on('dialog', async d => { dialogos.push(d.message()); await d.accept().catch(() => {}); });

  try {
    const conCuenta = !!(p.cuenta && p.cuenta.email && p.cuenta.password);
    if (conCuenta) await iniciarSesion(page);

    await page.goto(p.url, { waitUntil: 'networkidle', timeout: 60000 });
    await evidencia(page, 'inicio');
    const base = new Set(await mensajesVisibles(page));

    // 1. Concepto: número de operación + llave de pago
    const ETQ_OPERACION = /n[uú]mero\s+de\s+operaci[oó]n/i;
    const ETQ_LLAVE = /llave\s+de\s+pago/i;

    await llenar(page, ETQ_OPERACION, p.numOperacion);
    await llenar(page, ETQ_LLAVE, p.llavePago);
    const valorOperacion = await valorDeCampo(page, ETQ_OPERACION);
    const valorLlave = await valorDeCampo(page, ETQ_LLAVE);
    await evidencia(page, 'campos_llenos');

    const lineasAntes = new Set(await lineasVisibles(page));
    await clic(page, /^\s*agregar\s*$/i);

    // La fila puede mostrar la llave en otro formato: se busca sin distinguir mayúsculas
    const fila = page.locator('table tr').filter({ hasText: new RegExp(p.llavePago, 'i') });
    const agregado = await esperarResultado(page, fila, base, 30000, lineasAntes);
    await evidencia(page, 'despues_agregar');

    if (agregado.tipo === 'timeout') {
      const filasTabla = await page.locator('table tr').count().catch(() => 0);
      return terminar({
        estado: 'error',
        mensaje: 'El portal no agregó el pago ni mostró mensaje en 30 s. '
          + 'Campos capturados -> Operación: "' + valorOperacion + '" (se envió "' + p.numOperacion + '"), '
          + 'Llave: "' + valorLlave + '" (se envió "' + p.llavePago + '"). '
          + 'Filas en tablas: ' + filasTabla + '.',
        pdf: null,
        xml: null
      });
    }

    // El portal brincó directo a "Ya puede descargar su factura": ya estaba timbrada
    if ((agregado.tipo === 'mensaje' && RE_DESCARGA_LISTA.test(agregado.texto)) || await seccionDescargaVisible(page)) {
      const archivos = await descargarAmbos(page, context);
      await evidencia(page, 'descarga');

      if (!archivos.pdf && !archivos.xml) {
        return terminar({
          estado: 'error',
          mensaje: 'La factura ya estaba timbrada y el portal ofrece la descarga, pero no se pudieron bajar los archivos. ' + await diagnosticoEnlaces(page),
          pdf: null,
          xml: null
        });
      }

      const faltante = !archivos.pdf ? ' (no se pudo bajar el PDF)' : (!archivos.xml ? ' (no se pudo bajar el XML)' : '');
      return terminar({ estado: 'ya_timbrada', mensaje: 'La factura ya estaba timbrada; se descargó' + faltante + '.', ...archivos });
    }

    if (agregado.tipo === 'mensaje') {
      if (RE_YA_TIMBRADA.test(agregado.texto)) {
        // Ya estaba timbrada. Si el portal ofrece la descarga, se baja.
        if (await seccionDescargaVisible(page)) {
          const archivos = await descargarAmbos(page, context);
          return terminar({ estado: 'ya_timbrada', mensaje: agregado.texto, ...archivos });
        }
        return terminar({ estado: 'ya_timbrada', mensaje: agregado.texto, pdf: null, xml: null });
      }
      if (RE_NO_DISPONIBLE.test(agregado.texto)) {
        return terminar({ estado: 'no_disponible', mensaje: agregado.texto, pdf: null, xml: null });
      }
      // Mensaje sin clasificar: si de todos modos se agregó la fila, seguimos
      if (await fila.count() === 0) {
        return terminar({ estado: 'error', mensaje: 'Mensaje del portal: ' + agregado.texto, pdf: null, xml: null });
      }
    }

    // 2. Datos del receptor
    await clic(page, /^\s*siguiente\s*$/i);
    await page.waitForTimeout(1500);
    await evidencia(page, 'receptor');

    // El receptor es el CLIENTE. Lo que el portal ya trae (p. ej. el RFC del pago) no se sobrescribe.
    const r = p.receptor || {};
    const ETQ_NOMBRE = /nombre\s*\/?\s*raz[oó]n\s+social/i;
    const ETQ_RFC = /^\s*\*?\s*r\.?\s*f\.?\s*c\.?\s*:?\s*$/i;
    const ETQ_USO = /uso\s+(de\s+)?cfdi/i;
    const ETQ_REGIMEN = /r[eé]gimen\s+fiscal/i;
    const opc = { soloSiVacio: true };

    // 1. El RFC que trae el portal debe ser el del cliente
    const normRfc = v => String(v || '').toUpperCase().replace(/[^A-Z0-9&Ñ]/g, '');
    const rfcPortal = normRfc(await valorDeCampo(page, ETQ_RFC));
    if (rfcPortal && r.rfc && rfcPortal !== normRfc(r.rfc)) {
      await evidencia(page, 'rfc_distinto');
      return terminar({
        estado: 'revisar',
        mensaje: 'El RFC que muestra el portal (' + rfcPortal + ') no es el del cliente (' + normRfc(r.rfc) + '). No se timbró; revísalo a mano.',
        pdf: null,
        xml: null
      });
    }

    // 2. Llenar solo lo que esté vacío
    await llenar(page, ETQ_NOMBRE, r.nombre, opc);
    await llenar(page, ETQ_RFC, r.rfc, opc);
    await llenar(page, ETQ_USO, r.uso_cfdi, { ...opc, opcional: true });
    await llenar(page, ETQ_REGIMEN, r.regimen, { ...opc, opcional: true });
    await llenar(page, /c[oó]digo\s+postal/i, r.codigo_postal, { ...opc, opcional: true });
    await page.waitForLoadState('networkidle').catch(() => {}); // el CP suele llenar estado/municipio
    await llenar(page, /correo\s+electr[oó]nico/i, r.correo, { ...opc, opcional: true });
    await llenar(page, /m[eé]todo\s+de\s+pago/i, r.metodo_pago, { ...opc, opcional: true });
    await evidencia(page, 'receptor_lleno');

    // 3. Nunca timbrar con datos incompletos
    const faltan = [];
    if (!(await valorDeCampo(page, ETQ_NOMBRE)).trim()) faltan.push('Nombre/Razón social');
    if (!normRfc(await valorDeCampo(page, ETQ_RFC))) faltan.push('RFC');
    if (!(await valorDeCampo(page, ETQ_USO)).trim()) faltan.push('Uso CFDI');
    if (!(await valorDeCampo(page, ETQ_REGIMEN)).trim()) faltan.push('Régimen fiscal');

    if (faltan.length > 0) {
      return terminar({
        estado: 'sin_datos_cliente',
        mensaje: 'Faltan datos fiscales del cliente: ' + faltan.join(', ') + '. Captúralos en el cliente y vuelve a correr el comando.',
        pdf: null,
        xml: null
      });
    }

    // 3. Timbrar
    const baseReceptor = new Set(await mensajesVisibles(page));
    const lineasReceptor = new Set(await lineasVisibles(page));
    await clic(page, /crear\s+factura/i);

    const listo = page.getByText(RE_DESCARGA_LISTA);
    const timbrado = await esperarResultado(page, listo, baseReceptor, 120000, lineasReceptor);
    await evidencia(page, 'despues_crear');

    if (timbrado.tipo === 'exito') {
      const archivos = await descargarAmbos(page, context);
      await evidencia(page, 'descarga');
      if (!archivos.pdf && !archivos.xml) {
        return terminar({ estado: 'error', mensaje: 'Se timbró pero no se pudieron descargar los archivos. Descárgala a mano del portal. ' + await diagnosticoEnlaces(page), pdf: null, xml: null });
      }
      return terminar({ estado: 'timbrada', mensaje: 'Factura timbrada y descargada.', ...archivos });
    }

    if (timbrado.tipo === 'mensaje') {
      if (RE_YA_TIMBRADA.test(timbrado.texto)) {
        return terminar({ estado: 'ya_timbrada', mensaje: timbrado.texto, pdf: null, xml: null });
      }
      if (RE_NO_DISPONIBLE.test(timbrado.texto)) {
        return terminar({ estado: 'no_disponible', mensaje: timbrado.texto, pdf: null, xml: null });
      }
      return terminar({ estado: 'error', mensaje: 'Mensaje del portal al timbrar: ' + timbrado.texto, pdf: null, xml: null });
    }

    return terminar({ estado: 'error', mensaje: 'El portal no confirmó el timbrado en 2 minutos. Revisa el portal antes de reintentar.', pdf: null, xml: null });
  } catch (e) {
    await evidencia(page, 'error').catch(() => {});
    return terminar({ estado: 'error', mensaje: String(e && e.message ? e.message : e), pdf: null, xml: null });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
}

main();
