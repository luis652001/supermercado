'use strict';

// ============================================================ Utilidades

const $ = (selector) => document.querySelector(selector);
const quetzales = (n) => `Q${Number(n).toFixed(2)}`;

/** Crea elementos sin usar innerHTML: todo texto pasa por textContent (evita XSS). */
function el(tag, atributos = {}, ...hijos) {
    const nodo = document.createElement(tag);
    for (const [clave, valor] of Object.entries(atributos)) {
        if (valor === null || valor === undefined || valor === false) continue;
        if (clave.startsWith('on')) nodo.addEventListener(clave.slice(2), valor);
        else if (clave === 'class') nodo.className = valor;
        else if (clave in nodo && typeof valor !== 'string') nodo[clave] = valor;
        else nodo.setAttribute(clave, valor);
    }
    for (const hijo of hijos.flat()) {
        if (hijo === null || hijo === undefined || hijo === false) continue;
        nodo.append(hijo instanceof Node ? hijo : document.createTextNode(String(hijo)));
    }
    return nodo;
}

function montar(nodo, ...hijos) {
    nodo.replaceChildren(...hijos.flat().filter((hijo) => hijo instanceof Node));
}

let temporizadorAviso;
function aviso(mensaje, esError = false) {
    const caja = $('#aviso');
    caja.textContent = mensaje;
    caja.className = esError ? 'aviso error' : 'aviso';
    caja.hidden = false;
    clearTimeout(temporizadorAviso);
    temporizadorAviso = setTimeout(() => { caja.hidden = true; }, 4500);
}

const sesion = {
    get token() { return sessionStorage.getItem('token'); },
    get usuario() { return JSON.parse(sessionStorage.getItem('usuario') || 'null'); },
    get esAdmin() { return this.usuario?.rol === 'admin'; },
    get esStaff() { return this.puede('panel.acceso'); },
    puede(permiso) {
        const lista = this.usuario?.permisos;
        if (Array.isArray(lista)) return lista.includes(permiso);
        // Compatibilidad con sesiones viejas sin permisos en sessionStorage
        return this.usuario?.rol === 'admin';
    },
    guardar(token, usuario) {
        sessionStorage.setItem('token', token);
        sessionStorage.setItem('usuario', JSON.stringify(usuario));
    },
    limpiar() { sessionStorage.clear(); },
};

async function api(metodo, ruta, cuerpo) {
    const headers = { 'Content-Type': 'application/json' };
    if (sesion.token) headers.Authorization = `Bearer ${sesion.token}`;
    let respuesta;
    try {
        respuesta = await fetch(ruta, { method: metodo, headers, body: cuerpo ? JSON.stringify(cuerpo) : undefined });
    } catch {
        return { ok: false, status: 0, datos: { error: 'No hay conexión con el servidor' } };
    }
    let datos = {};
    try { datos = await respuesta.json(); } catch {
        datos = { error: respuesta.status === 429
            ? 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.'
            : `Error HTTP ${respuesta.status}` };
    }

    if (respuesta.status === 401 && sesion.token) {
        sesion.limpiar();
        refrescarSesion();
        aviso('Tu sesión expiró o fue revocada. Ingresa de nuevo.', true);
    }
    if (respuesta.status === 429) aviso('Demasiadas solicitudes. Espera unos segundos.', true);
    return { ok: respuesta.ok, status: respuesta.status, datos };
}

// ============================================================ Navegación

const estado = { categoria: null, busqueda: '', categorias: [], accesoAdminRegistrado: false, adminTab: 'admin-dashboard', productoId: null, productorId: null };

const ADMIN_TABS = {
    admin: 'admin-dashboard',
    'admin-dashboard': 'admin-dashboard',
    'admin-productos': 'admin-productos',
    'admin-inventario': 'admin-inventario',
    'admin-clasificacion': 'admin-clasificacion',
    'admin-fefo': 'admin-fefo',
    'admin-pedidos': 'admin-pedidos',
    'admin-pagos': 'admin-pagos',
    'admin-capital': 'admin-capital',
    'admin-reportes': 'admin-reportes',
    'admin-usuarios': 'admin-usuarios',
    'admin-roles': 'admin-roles',
    'admin-clientes': 'admin-clientes',
    'admin-bitacora': 'admin-bitacora',
};

const TITULOS_ADMIN = {
    'admin-dashboard': 'Resumen',
    'admin-productos': 'Productos',
    'admin-inventario': 'Inventario',
    'admin-clasificacion': 'Categorías y marcas',
    'admin-fefo': 'Frescura FEFO',
    'admin-pedidos': 'Pedidos',
    'admin-pagos': 'Pagos',
    'admin-capital': 'Capital',
    'admin-reportes': 'Reportería',
    'admin-usuarios': 'Usuarios',
    'admin-roles': 'Roles y permisos',
    'admin-clientes': 'Clientes',
    'admin-bitacora': 'Bitácora',
};

const cargadores = {
    catalogo: cargarCatalogo,
    producto: cargarProducto,
    productores: cargarProductores,
    carrito: cargarCarrito,
    envases: cargarEnvases,
    pedidos: cargarMisPedidos,
    admin: abrirAdmin,
    cuenta: cargarCuenta,
};

function mostrarVista() {
    let hash = location.hash.slice(1) || 'catalogo';
    let vista = hash;
    let adminTab = null;

    if (ADMIN_TABS[hash]) {
        vista = 'admin';
        adminTab = ADMIN_TABS[hash];
        estado.adminTab = adminTab;
    }
    estado.productorId = null;
    if (/^producto-\d+$/.test(hash)) {
        vista = 'producto';
        estado.productoId = Number(hash.slice('producto-'.length));
    }
    if (/^productor-\d+$/.test(hash)) {
        vista = 'productores';
        estado.productorId = Number(hash.slice('productor-'.length));
    }

    if (!cargadores[vista]) vista = 'catalogo';
    if (vista === 'pedidos' && !sesion.token) vista = 'cuenta';
    if (vista === 'admin' && !sesion.esStaff) vista = sesion.token ? 'catalogo' : 'cuenta';
    if (vista !== 'admin' && vista !== 'cuenta' && !puedeVerNav(vista)) {
        vista = puedeVerNav('catalogo') ? 'catalogo' : 'cuenta';
        const actual = location.hash.slice(1) || 'catalogo';
        if (actual !== vista) {
            location.hash = vista;
            return;
        }
    }

    document.querySelectorAll('[data-vista]').forEach((s) => { s.hidden = s.dataset.vista !== vista; });
    document.querySelectorAll('[data-nav]').forEach((a) => {
        const activo = vista === 'admin'
            ? a.dataset.nav === estado.adminTab
            : a.dataset.nav === vista;
        a.classList.toggle('activo', activo);
    });
    sincronizarMenuLateral(vista);
    cargadores[vista]();
}

/** En login/registro ocultamos Catálogo/Carrito: el foco es la cuenta. */
function sincronizarMenuLateral(vista) {
    const enAuth = vista === 'cuenta' && !sesion.token;
    $('#sidebar')?.classList.toggle('modo-auth', enAuth);
    document.querySelectorAll('[data-menu="tienda"]').forEach((n) => {
        if (enAuth) {
            n.hidden = true;
            return;
        }
        if (!puedeVerNav(n.dataset.nav)) {
            n.hidden = true;
            return;
        }
        if (n.matches('[data-requiere="sesion"]')) {
            n.hidden = !sesion.token;
        } else {
            n.hidden = false;
        }
    });
    document.querySelectorAll('.nav-inferior a[data-nav]').forEach((n) => {
        if (!puedeVerNav(n.dataset.nav)) {
            n.hidden = true;
            return;
        }
        n.hidden = n.matches('[data-requiere="sesion"]') ? !sesion.token : false;
    });
    const cajaSesion = document.querySelector('.sesion');
    if (cajaSesion) cajaSesion.hidden = enAuth;
}

function refrescarSesion() {
    const usuario = sesion.usuario;
    const etiquetaCuenta = $('#btn-cuenta')?.querySelector('span:last-child');
    if (etiquetaCuenta) etiquetaCuenta.textContent = usuario ? `Hola, ${usuario.nombre.split(' ')[0]}` : 'Ingresar';
    $('#btn-logout').hidden = !usuario;
    document.querySelectorAll('[data-requiere="sesion"]').forEach((n) => { n.hidden = !usuario; });
    document.querySelectorAll('[data-requiere="admin"]').forEach((n) => { n.hidden = !sesion.esStaff; });
    document.querySelectorAll('.menu a[data-admin-tab]').forEach((a) => {
        const alguno = (a.dataset.permisoAlguno || '').split(',').map((p) => p.trim()).filter(Boolean);
        const permitido = alguno.length
            ? alguno.some((p) => sesion.puede(p))
            : (!a.dataset.permiso || sesion.puede(a.dataset.permiso));
        a.hidden = !sesion.esStaff || !permitido || !puedeVerNav(a.dataset.nav);
    });
    const labelGestion = document.querySelector('.menu-label[data-requiere="admin"]');
    if (labelGestion) {
        const visibles = [...document.querySelectorAll('.menu a[data-admin-tab]')].some((a) => !a.hidden);
        labelGestion.hidden = !visibles;
    }
    const cajaRol = $('#menu-rol');
    if (cajaRol) {
        const nombres = { admin: 'Administrador', operador: 'Operador de bodega', contador: 'Contador' };
        cajaRol.hidden = !sesion.esStaff;
        cajaRol.textContent = sesion.esStaff ? `Rol: ${nombres[sesion.usuario?.rol] || sesion.usuario?.rol || ''}` : '';
    }
    if (!usuario) $('#contador-carrito').textContent = '0';
    const hash = location.hash.slice(1) || 'catalogo';
    const vista = ADMIN_TABS[hash] ? 'admin' : (/^producto-\d+$/.test(hash) ? 'producto' : (cargadores[hash] ? hash : 'catalogo'));
    sincronizarMenuLateral(vista === 'cuenta' || hash === 'cuenta' ? 'cuenta' : vista);
}

// ============================================================ Catálogo

async function cargarCatalogo() {
    if (!estado.categorias.length) {
        const { ok, datos } = await api('GET', '/api/catalogo/categorias');
        if (ok) estado.categorias = datos.categorias;
    }
    pintarCategorias();
    await cargarProductos();
}

function pintarCategorias() {
    const chip = (id, texto) => el('button', {
        type: 'button',
        class: `chip${estado.categoria === id ? ' activo' : ''}`,
        onclick: () => { estado.categoria = id; pintarCategorias(); cargarProductos(); },
    }, texto);
    $('#categorias').replaceChildren(
        chip(null, '🛒 Todo'),
        ...estado.categorias.map((c) => chip(c.id, `${c.icono} ${c.nombre} (${c.productos})`)),
    );
}

async function cargarProductos() {
    const params = new URLSearchParams({ por_pagina: '48' });
    if (estado.categoria) params.set('categoria', estado.categoria);
    if (estado.busqueda) params.set('q', estado.busqueda);

    const { ok, datos } = await api('GET', `/api/catalogo/productos?${params}`);
    if (!ok) { aviso(datos.error || 'No se pudo cargar el catálogo', true); return; }

    $('#info-catalogo').textContent = sesion.esStaff
        ? `${datos.total} productos · lectura desde MariaDB ${datos.fuente_bd} · caché Redis: ${datos.cache}`
        : `${datos.total} productos disponibles`;
    $('#productos').replaceChildren(...(datos.productos.length
        ? datos.productos.map(tarjetaProducto)
        : [el('p', { class: 'vacio' }, 'No encontramos productos con ese criterio.')]));
}

function tarjetaProducto(p) {
    const cantidad = el('input', { type: 'number', min: '1', max: String(Math.min(p.stock, 50)), value: '1', 'aria-label': 'Cantidad' });
    return el('article', { class: 'producto' },
        el('div', { class: 'imagen' },
            p.imagen_url ? el('img', { src: p.imagen_url, alt: p.nombre, loading: 'lazy' }) : p.icono,
            p.destacado ? el('span', { class: 'etiqueta' }, 'OFERTA') : null,
            p.frescura ? el('span', { class: 'etiqueta', style: 'left:auto;right:.65rem;background:#176b45' }, 'FRESCO') : null),
        el('div', { class: 'cuerpo' },
            el('div', { class: 'nombre' }, p.nombre),
            el('div', { class: 'chips-producto' },
                p.productor ? el('span', { class: 'mini-chip' }, p.productor) : null,
                p.presentaciones?.length ? el('span', { class: 'mini-chip' }, p.presentaciones.length === 1 ? '1 presentación' : `${p.presentaciones.length} presentaciones`) : null,
                p.retornable ? el('span', { class: 'mini-chip' }, 'Retornable') : null),
            el('div', { class: 'desc' }, p.descripcion || ''),
            el('div', { class: 'precio' }, quetzales(p.precio), ' ', el('small', {}, `/ ${p.unidad}`)),
            el('div', { class: `stock${p.stock <= 10 ? ' bajo' : ''}` }, p.stock > 0 ? `${p.stock} disponibles` : 'Agotado'),
            el('div', { class: 'agregar' },
                cantidad,
                el('button', {
                    type: 'button',
                    disabled: p.stock === 0,
                    onclick: () => agregarAlCarrito(p, Number(cantidad.value), 0),
                }, 'Agregar')),
            el('button', {
                type: 'button',
                class: 'ver-ficha',
                onclick: () => { location.hash = `producto-${p.id}`; },
            }, 'Ver ficha')));
}

async function agregarAlCarrito(producto, cantidad, presentacionId = 0) {
    if (!sesion.token) {
        aviso('Ingresa a tu cuenta para agregar productos al carrito.');
        location.hash = 'cuenta';
        return;
    }
    const carritoActual = await api('GET', '/api/pedidos/carrito');
    const previo = carritoActual.datos.items?.find((i) => i.producto_id === producto.id && (i.presentacion_id || 0) === presentacionId)?.cantidad || 0;
    const { ok, datos } = await api('PUT', '/api/pedidos/carrito/items', {
        producto_id: producto.id,
        presentacion_id: presentacionId,
        cantidad: previo + cantidad,
    });
    if (!ok) { aviso(datos.error, true); return; }
    actualizarContador(datos);
    aviso(`${producto.nombre} agregado al carrito`);
}

// ============================================================ Carrito y checkout

function actualizarContador(carrito) {
    $('#contador-carrito').textContent = String((carrito.items || []).reduce((s, i) => s + i.cantidad, 0));
}

function pintarMetaEnvio(total) {
    const caja = $('#meta-envio');
    if (!caja) return;
    const meta = 200;
    const avance = Math.max(0, Math.min(100, (Number(total) / meta) * 100));
    const falta = Math.max(0, meta - Number(total));
    caja.replaceChildren(
        el('strong', {}, 'Envío refrigerado'),
        el('p', {}, falta > 0
            ? `Te faltan ${quetzales(falta)} para la entrega a 4°C. Meta ${quetzales(meta)}.`
            : 'Tu canasta ya alcanza la entrega refrigerada.'),
        el('div', { class: 'pista' }, el('span', { style: `width:${avance}%` })),
    );
}

async function cargarClima() {
    const caja = $('#clima-entrega');
    if (!caja) return;
    const { ok, datos } = await api('GET', '/api/notificaciones/clima');
    if (!ok || !datos.disponible) {
        caja.textContent = datos.mensaje || 'Clima no disponible (opcional).';
        return;
    }
    caja.textContent = `Clima en ${datos.ciudad}: ${datos.descripcion}, ${datos.temp_c}°C (OpenWeather).`;
}

async function cargarCarrito() {
    if (!sesion.token) {
        $('#lineas-carrito').replaceChildren(el('p', { class: 'vacio' }, 'Ingresa a tu cuenta para ver tu carrito.'));
        return;
    }
    const { ok, datos } = await api('GET', '/api/pedidos/carrito');
    if (!ok) { aviso(datos.error, true); return; }
    pintarCarrito(datos);
    cargarClima();

    const direccion = await api('GET', '/api/pedidos/direccion');
    const form = $('#form-checkout');
    if (direccion.ok && direccion.datos.direccion && !form.direccion.value) {
        const guardada = direccion.datos.direccion;
        for (const [campo, valor] of Object.entries(guardada)) {
            if (campo === 'lat' || campo === 'lng') continue;
            if (form[campo] && valor) form[campo].value = valor;
        }
        marcarPuntoGps(form, $('#estado-gps'), guardada.lat, guardada.lng);
    }
}

function pintarCarrito(carrito) {
    actualizarContador(carrito);
    pintarMetaEnvio(carrito.total || 0);
    $('#total-carrito').textContent = quetzales(carrito.total);
    if (!carrito.items.length) {
        $('#lineas-carrito').replaceChildren(el('p', { class: 'vacio' }, 'Tu carrito está vacío. ¡Visita el catálogo!'));
        return;
    }
    const saldoCaja = $('#saldo-envases');
    if (saldoCaja) saldoCaja.textContent = `Saldo de envases disponible: ${quetzales(carrito.saldo_envases || 0)}`;
    const cambiar = async (item, nuevaCantidad) => {
        const { ok, datos } = await api('PUT', '/api/pedidos/carrito/items', {
            producto_id: item.producto_id,
            presentacion_id: item.presentacion_id || 0,
            cantidad: nuevaCantidad,
        });
        if (!ok) { aviso(datos.error, true); return; }
        pintarCarrito(datos);
    };
    $('#lineas-carrito').replaceChildren(...carrito.items.map((item) => el('div', { class: 'linea' },
        el('div', { class: 'icono' }, item.imagen_url ? el('img', { src: item.imagen_url, alt: '' }) : item.icono),
        el('div', {},
            el('div', {}, el('strong', {}, item.presentacion ? `${item.nombre} · ${item.presentacion}` : item.nombre)),
            el('small', {}, `${quetzales(item.precio)} / ${item.presentacion || item.unidad}`),
            item.disponible ? null : el('div', { class: 'no-disponible' }, `Solo quedan ${item.stock} disponibles`)),
        el('div', { class: 'cantidad' },
            el('button', { type: 'button', class: 'secundario', onclick: () => cambiar(item, item.cantidad - 1) }, '−'),
            el('span', {}, item.cantidad),
            el('button', { type: 'button', class: 'secundario', onclick: () => cambiar(item, item.cantidad + 1) }, '+')),
        el('strong', {}, quetzales(item.subtotal)))));
}

$('#btn-vaciar').addEventListener('click', async () => {
    const { ok, datos } = await api('DELETE', '/api/pedidos/carrito');
    if (ok) pintarCarrito(datos);
});

$('#form-checkout').addEventListener('submit', async (e) => {
    e.preventDefault();
    const boton = e.target.querySelector('button[type="submit"]');
    boton.disabled = true;
    const datosForm = Object.fromEntries(new FormData(e.target));
    const forma = datosForm.forma_pago;
    delete datosForm.forma_pago;
    const { ok, datos } = await api('POST', '/api/pedidos/', datosForm);
    boton.disabled = false;
    if (!ok) {
        const faltantes = (datos.faltantes || datos.no_disponibles || []).map((f) => f.nombre).filter(Boolean);
        aviso(`${datos.error}${faltantes.length ? `: ${faltantes.join(', ')}` : ''}`, true);
        return;
    }
    $('#contador-carrito').textContent = '0';
    sessionStorage.setItem('pedidoSeleccionado', datos.pedido.uuid);
    sessionStorage.setItem('pedidoRecienCreado', datos.pedido.uuid);
    if (forma === 'efectivo') {
        const pago = await api('POST', '/api/pagos/contra-entrega', { pedido_uuid: datos.pedido.uuid });
        aviso(pago.ok ? 'Pedido confirmado. Lo pagas en efectivo cuando llegue a tu puerta.' : (pago.datos.error || 'El pedido quedó creado. Elige el pago en el detalle.'), !pago.ok);
    } else {
        aviso(`Pedido confirmado. Total ${quetzales(datos.pedido.total)}. En el detalle puedes pagar con tarjeta.`);
    }
    location.hash = 'pedidos';
});

// ============================================================ Mis pedidos

const etiquetaEstado = (estadoPedido) => el('span', { class: `estado estado-${estadoPedido}` }, estadoPedido.replace('_', ' '));

async function cargarMisPedidos() {
    const { ok, datos } = await api('GET', '/api/pedidos/');
    if (!ok) { aviso(datos.error, true); return; }
    $('#lista-pedidos').replaceChildren(...(datos.pedidos.length
        ? datos.pedidos.map((p) => el('div', { class: 'pedido-item', onclick: () => verPedido(p.uuid) },
            el('div', {},
                el('div', {}, el('strong', {}, new Date(p.fecha.replace(' ', 'T')).toLocaleString('es-GT'))),
                el('div', { class: 'uuid' }, p.uuid)),
            el('div', {}, `${p.productos} prod. · ${quetzales(p.total)}`),
            etiquetaEstado(p.estado)))
        : [el('p', { class: 'vacio' }, 'Aún no tienes pedidos.')]));

    const seleccionado = sessionStorage.getItem('pedidoSeleccionado');
    if (seleccionado) { sessionStorage.removeItem('pedidoSeleccionado'); verPedido(seleccionado); }
}

async function pagarPedido(uuid, opciones = {}) {
    const cuerpo = { pedido_uuid: uuid, ...opciones };
    const { ok, datos } = await api('POST', '/api/pagos/checkout', cuerpo);
    if (!ok) { aviso(datos.error, true); return; }
    if (datos.modo === 'regalo') {
        aviso(datos.mensaje || 'La tarjeta de regalo cubrió el pedido.');
        verPedido(uuid);
        cargarMisPedidos();
        return;
    }
    if (datos.modo === 'stripe' && datos.checkout_url) {
        window.location.href = datos.checkout_url;
        return;
    }
    const demo = await api('POST', '/api/pagos/demo-confirmar', cuerpo);
    if (!demo.ok) { aviso(demo.datos.error, true); return; }
    aviso(demo.datos.mensaje || 'Pago de prueba confirmado.');
    verPedido(uuid);
    cargarMisPedidos();
}

async function pagarEnEfectivo(uuid, opciones = {}) {
    const { ok, datos } = await api('POST', '/api/pagos/contra-entrega', { pedido_uuid: uuid, ...opciones });
    if (!ok) { aviso(datos.error, true); return; }
    aviso(datos.mensaje || 'El pedido se cobra en efectivo al entregar.');
    verPedido(uuid);
    cargarMisPedidos();
}

async function cambiarPagoEnLinea(uuid) {
    const { ok, datos } = await api('POST', '/api/pagos/contra-entrega/anular', { pedido_uuid: uuid });
    if (!ok) { aviso(datos.error, true); return; }
    aviso(datos.mensaje || 'Ya puedes pagar en línea.');
    verPedido(uuid);
}

async function cancelarPedidoCliente(uuid) {
    if (!confirm('¿Cancelar este pedido? Solo se puede antes de pagar.')) return;
    const pago = await api('GET', `/api/pagos/pedido/${uuid}`);
    if (pago.ok && pago.datos.pago.proveedor === 'efectivo' && pago.datos.pago.estado === 'iniciado') {
        const anula = await api('POST', '/api/pagos/contra-entrega/anular', { pedido_uuid: uuid });
        if (!anula.ok) { aviso(anula.datos.error, true); return; }
    }
    const { ok, datos } = await api('POST', `/api/pedidos/${uuid}/cancelar`);
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Pedido cancelado. El stock fue repuesto.');
    verPedido(uuid);
    cargarMisPedidos();
}

const PASOS_ENTREGA = [
    ['pendiente', 'Recibido'],
    ['pagado', 'Pagado'],
    ['preparando', 'En cámara'],
    ['en_camino', 'En ruta'],
    ['entregado', 'En puerta'],
];

function lineaTiempo(estadoPedido, pago) {
    if (estadoPedido === 'cancelado') {
        return el('p', { class: 'nota-seguridad' }, 'Este pedido fue cancelado. El stock volvió al catálogo.');
    }
    const efectivoPendiente = pago?.proveedor === 'efectivo' && pago.estado === 'iniciado';
    const indice = PASOS_ENTREGA.findIndex(([clave]) => clave === estadoPedido);
    return el('div', { class: 'pasos-pedido' }, ...PASOS_ENTREGA.map(([clave, texto], i) => {
        const etiqueta = clave === 'pagado' && pago?.proveedor === 'efectivo' ? 'Efectivo' : texto;
        const hecho = i < indice && !(efectivoPendiente && clave === 'pagado');
        return el('span', { class: `paso${hecho ? ' hecho' : ''}${i === indice ? ' actual' : ''}` }, etiqueta);
    }));
}

function mapaEntrega(p) {
    const nombre = p.repartidor_nombre || 'Sin asignar';
    const repartidorEnMapa = p.lat != null && p.lng != null && (p.estado === 'en_camino' || p.estado === 'entregado');
    let src;
    let nota;
    let titulo;
    if (repartidorEnMapa) {
        src = `https://maps.google.com/maps?q=${p.lat},${p.lng}&z=15&output=embed`;
        nota = 'El punto es el GPS del repartidor, tomado desde su teléfono.';
        titulo = `Repartidor ${nombre}`;
    } else if (p.destino_lat != null && p.destino_lng != null) {
        src = `https://maps.google.com/maps?q=${p.destino_lat},${p.destino_lng}&z=16&output=embed`;
        nota = 'Este es el punto de tu entrega, el que marcó tu GPS.';
        titulo = 'Tu entrega';
    } else {
        const consulta = encodeURIComponent(`${p.direccion_entrega}, ${p.municipio}, Guatemala`);
        src = `https://maps.google.com/maps?q=${consulta}&z=16&output=embed`;
        nota = 'El mapa busca la dirección que escribiste. Con el GPS el punto queda en tu casa.';
        titulo = 'Tu entrega';
    }
    return el('div', {},
        el('div', { class: 'repartidor' },
            el('div', { class: 'avatar' }, nombre.slice(0, 1).toUpperCase()),
            el('div', {},
                el('strong', {}, nombre),
                el('div', { class: 'stock' }, p.repartidor_nombre ? 'Repartidor en ruta' : 'La bodega prepara el pedido'))),
        el('p', { class: 'nota-seguridad' }, nota),
        el('iframe', { class: 'mapa', title: titulo, loading: 'lazy', src }));
}

function bloqueChat(p) {
    const campo = el('input', { type: 'text', maxlength: '300', placeholder: 'Escribe al equipo de entrega', 'aria-label': 'Mensaje' });
    const enviar = async () => {
        const texto = campo.value.trim();
        if (!texto) return;
        const { ok, datos } = await api('POST', `/api/pedidos/${p.uuid}/mensajes`, { texto });
        if (!ok) { aviso(datos.error, true); return; }
        verPedido(p.uuid);
    };
    return el('div', {},
        el('h3', {}, 'Chat del pedido'),
        el('div', { class: 'chat' }, ...(p.mensajes || []).map((m) => el('div', { class: `burbuja${m.autor === 'equipo' ? ' equipo' : ''}` },
            m.texto,
            el('small', {}, m.autor === 'equipo' ? 'Equipo Mercado Verde' : 'Tú')))),
        p.estado === 'cancelado' ? null : el('div', { class: 'fila-chat' },
            campo,
            el('button', { type: 'button', onclick: enviar }, 'Enviar')));
}

function bloqueCalificacion(p) {
    if (p.estado !== 'entregado') return null;
    if (p.calificacion) {
        return el('p', {}, `Calificación: ${p.calificacion} de 5`, p.propina ? ` · propina registrada ${quetzales(p.propina)} (no se cobra a la tarjeta)` : '');
    }
    let estrellas = 5;
    const propina = el('input', { type: 'number', min: '0', max: '200', step: '1', value: '0', 'aria-label': 'Propina' });
    const comentario = el('input', { type: 'text', maxlength: '300', placeholder: '¿Cómo llegó el pedido?', 'aria-label': 'Comentario' });
    return el('div', {},
        el('h3', {}, 'Calificar y dejar propina'),
        el('p', {}, 'La propina queda registrada para el repartidor. No se envía a Stripe ni se guarda tarjeta.'),
        el('div', { class: 'estrellas' }, ...[1, 2, 3, 4, 5].map((n) => el('button', {
            type: 'button',
            class: `boton-texto${n <= estrellas ? ' activo' : ''}`,
            onclick: (ev) => { estrellas = n; ev.currentTarget.parentElement.querySelectorAll('button').forEach((b, i) => { b.classList.toggle('activo', i < n); }); },
        }, '★'))),
        propina,
        comentario,
        el('button', {
            type: 'button',
            onclick: async () => {
                const { ok, datos } = await api('POST', `/api/pedidos/${p.uuid}/calificar`, {
                    estrellas,
                    propina: Number(propina.value || 0),
                    comentario: comentario.value,
                });
                if (!ok) { aviso(datos.error, true); return; }
                aviso('Gracias por calificar.');
                verPedido(p.uuid);
            },
        }, 'Enviar calificación'));
}

async function verPedido(uuid) {
    const { ok, datos } = await api('GET', `/api/pedidos/${uuid}`);
    if (!ok) { aviso(datos.error, true); return; }
    const p = datos.pedido;
    const pago = await api('GET', `/api/pagos/pedido/${uuid}`);
    const acciones = [];
    const pagoDatos = pago.ok ? pago.datos.pago : null;
    const efectivoPendiente = pagoDatos?.proveedor === 'efectivo' && pagoDatos.estado === 'iniciado';
    if (p.estado === 'pendiente' && efectivoPendiente) {
        acciones.push(el('p', { class: 'nota-seguridad' }, `Pagas ${quetzales(pagoDatos.monto)} en efectivo cuando llegue el pedido. El repartidor lo cobra en la puerta.`));
        acciones.push(el('button', { type: 'button', class: 'secundario', onclick: () => cambiarPagoEnLinea(uuid) }, 'Cambiar a pago en línea'));
        acciones.push(el('button', { type: 'button', class: 'peligro', onclick: () => cancelarPedidoCliente(uuid) }, 'Cancelar pedido'));
    } else if (p.estado === 'pendiente') {
        const metodos = await api('GET', '/api/pagos/metodos');
        const lista = metodos.ok ? (metodos.datos.metodos || []) : [];
        const normales = lista.filter((m) => m.tipo === 'normal');
        const regalos = lista.filter((m) => m.tipo === 'regalo' && Number(m.saldo) > 0);
        const selNormal = el('select', { 'aria-label': 'Tarjeta guardada' },
            el('option', { value: '' }, 'Pagar sin tarjeta guardada'),
            ...normales.map((m) => el('option', { value: String(m.id) }, `${etiquetaMarca(m.marca)} •••• ${m.ultimos4}`)));
        const selRegalo = el('select', { 'aria-label': 'Tarjeta de regalo' },
            el('option', { value: '' }, 'No usar tarjeta de regalo'),
            ...regalos.map((m) => el('option', { value: String(m.id) }, `${m.alias} · ${quetzales(m.saldo)}`)));
        const opcionesPago = () => ({
            metodo_id: selNormal.value ? Number(selNormal.value) : undefined,
            tarjeta_regalo_id: selRegalo.value ? Number(selRegalo.value) : undefined,
        });
        if (normales.length || regalos.length) {
            acciones.push(el('p', { class: 'nota-seguridad' }, 'Elige la tarjeta guardada. La normal no envía el número. La de regalo descuenta el saldo en el servidor.'));
            if (normales.length) acciones.push(selNormal);
            if (regalos.length) acciones.push(selRegalo);
        }
        acciones.push(el('h3', {}, 'Cómo quieres pagar'));
        acciones.push(el('button', { type: 'button', onclick: () => pagarEnEfectivo(uuid, opcionesPago()) }, 'Pago contra entrega'));
        acciones.push(el('button', { type: 'button', class: 'secundario', onclick: () => pagarPedido(uuid, opcionesPago()) }, 'Pagar con tarjeta'));
        acciones.push(el('button', { type: 'button', class: 'peligro', onclick: () => cancelarPedidoCliente(uuid) }, 'Cancelar pedido'));
    } else if (efectivoPendiente) {
        acciones.push(el('p', { class: 'nota-seguridad' }, `Lleva ${quetzales(pagoDatos.monto)} en efectivo. Se cobra cuando el pedido llegue.`));
    } else if (pagoDatos?.proveedor === 'efectivo' && pagoDatos.estado === 'completado') {
        acciones.push(el('p', {}, `Efectivo recibido: ${quetzales(pagoDatos.monto)}.`));
    }
    const recienCreado = sessionStorage.getItem('pedidoRecienCreado') === uuid;
    if (recienCreado) sessionStorage.removeItem('pedidoRecienCreado');
    $('#detalle-pedido').replaceChildren(...[
        recienCreado ? el('div', { class: 'exito-compra' },
            el('strong', {}, 'Compra registrada'),
            el('p', {}, `Total ${quetzales(p.total)}. El precio lo confirmó el servidor. Puedes pagar con tarjeta o dejarlo contra entrega.`)) : null,
        el('h2', {}, 'Seguimiento del pedido'),
        el('p', { class: 'uuid' }, p.uuid),
        el('p', {}, etiquetaEstado(p.estado)),
        lineaTiempo(p.estado, pagoDatos),
        acciones.length ? el('div', { class: 'acciones-pedido-cliente' }, ...acciones) : null,
        el('table', {},
            el('thead', {}, el('tr', {}, el('th', {}, 'Producto'), el('th', {}, 'Cant.'), el('th', {}, 'Precio'), el('th', {}, 'Subtotal'))),
            el('tbody', {}, ...p.detalle.map((d) => el('tr', {},
                el('td', {}, d.nombre), el('td', {}, d.cantidad), el('td', {}, quetzales(d.precio_unitario)), el('td', {}, quetzales(d.subtotal)))))),
        p.descuento_envases > 0 ? el('p', {}, `Descuento de envases: ${quetzales(p.descuento_envases)}`) : null,
        pago.ok && Number(pago.datos.pago.descuento_regalo) > 0 ? el('p', {}, `Tarjeta de regalo: −${quetzales(pago.datos.pago.descuento_regalo)}`) : null,
        pago.ok && pago.datos.pago.metodo_etiqueta ? el('p', {}, `Medio: ${pago.datos.pago.metodo_etiqueta}`) : null,
        el('p', { class: 'total' }, 'Total: ', el('strong', {}, quetzales(p.total))),
        el('p', {}, el('strong', {}, 'Entrega: '), `${p.direccion_entrega}${p.zona ? `, ${p.zona}` : ''}, ${p.municipio}`),
        el('p', {}, el('strong', {}, 'Teléfono: '), p.telefono),
        p.pase_envases ? el('div', {},
            el('h3', {}, 'Pase de devolución'),
            el('p', { class: 'pase-grande' }, p.pase_envases),
            el('p', { class: 'nota-seguridad' }, 'Muéstralo al devolver el casco o la canasta. En Envases ese código acredita el depósito. El monto lo calcula el servidor.')) : null,
        mapaEntrega(p),
        bloqueChat(p),
        bloqueCalificacion(p),
    ].filter((hijo) => hijo instanceof Node));
}

async function cargarProducto() {
    const { ok, datos } = await api('GET', `/api/catalogo/productos/${estado.productoId}`);
    if (!ok) { aviso(datos.error || 'Producto no encontrado', true); location.hash = 'catalogo'; return; }
    const p = datos.producto;
    const baseCantidad = el('input', { type: 'number', min: '1', max: String(Math.min(p.stock, 50)), value: '1' });
    const ofertas = (p.presentaciones || []).map((pres) => {
        const cantidad = el('input', { type: 'number', min: '1', max: '20', value: '1' });
        return el('div', { class: 'oferta' },
            el('div', {}, el('strong', {}, pres.nombre), el('div', { class: 'stock' }, `${pres.factor} ${p.unidad} · el servidor cobra este precio`)),
            el('strong', {}, quetzales(pres.precio)),
            el('div', { class: 'agregar' },
                cantidad,
                el('button', {
                    type: 'button',
                    disabled: p.stock < pres.factor,
                    onclick: () => agregarAlCarrito(p, Number(cantidad.value), pres.id),
                }, 'Agregar')));
    });
    $('#detalle-producto').replaceChildren(
        el('article', { class: 'ficha-producto' },
            el('div', { class: 'ficha-foto' },
                p.imagen_url ? el('img', { src: p.imagen_url, alt: p.nombre }) : (p.icono || '🥬')),
            el('div', { class: 'tarjeta' },
                el('p', { class: 'hero-marca' }, p.categoria),
                el('h1', {}, p.nombre),
                el('p', {}, p.descripcion || ''),
                el('p', { class: 'precio-grande' }, quetzales(p.precio), ' ', el('small', {}, `/ ${p.unidad}`)),
                el('div', { class: 'chips-producto' },
                    el('span', { class: 'mini-chip' }, p.stock > 0 ? `${p.stock} disponibles` : 'Agotado'),
                    p.frescura ? el('span', { class: 'mini-chip' }, `Fresco hasta ${p.frescura.vence}`) : null,
                    p.frescura?.temperatura != null ? el('span', { class: 'mini-chip' }, `Cámara ${p.frescura.temperatura}°C`) : null),
                p.productor ? el('button', {
                    type: 'button',
                    class: 'productor-enlace',
                    onclick: async () => {
                        const lista = await api('GET', '/api/catalogo/productores');
                        const hallado = (lista.datos.productores || []).find((pr) => pr.nombre === p.productor);
                        location.hash = hallado ? `productor-${hallado.id}` : 'productores';
                    },
                }, `${p.productor}${p.productor_region ? ` · ${p.productor_region}` : ''}`) : null,
                p.productor_impacto ? el('p', { class: 'nota-seguridad' }, p.productor_impacto) : null,
                p.retornable ? el('p', { class: 'nota-seguridad' }, `Envase retornable. Depósito ${quetzales(p.deposito_envase)}. Se acredita al devolverlo con el pase del pedido.`) : null,
                el('div', { class: 'agregar' },
                    baseCantidad,
                    el('button', { type: 'button', disabled: p.stock === 0, onclick: () => agregarAlCarrito(p, Number(baseCantidad.value), 0) }, 'Agregar unidad')),
                ofertas.length ? el('h2', {}, 'Presentaciones por volumen') : null,
                ofertas.length ? el('div', { class: 'ofertas' }, ...ofertas) : null)));
}

function tarjetaProductor(pr) {
    return el('article', { class: 'producto' },
        el('div', { class: 'cuerpo' },
            el('p', { class: 'hero-marca' }, pr.region),
            el('div', { class: 'nombre' }, pr.nombre),
            el('div', { class: 'desc' }, pr.descripcion),
            el('p', { class: 'nota-seguridad' }, pr.impacto || 'Compra directa a productores de Guatemala.'),
            el('div', { class: 'stock' }, `${pr.productos} productos en la cosecha de hoy`),
            el('button', { type: 'button', onclick: () => { location.hash = `productor-${pr.id}`; } }, 'Ver origen')));
}

async function cargarProductores() {
    const { ok, datos } = await api('GET', '/api/catalogo/productores');
    if (!ok) { aviso(datos.error || 'No se pudieron cargar los productores', true); return; }
    const lista = datos.productores || [];
    const elegido = estado.productorId ? lista.find((pr) => pr.id === estado.productorId) : null;
    if (!elegido) {
        $('#lista-productores').replaceChildren(...(lista.length
            ? lista.map(tarjetaProductor)
            : [el('p', { class: 'vacio' }, 'Aún no hay productores publicados.')]));
        return;
    }
    const catalogo = await api('GET', '/api/catalogo/productos?por_pagina=48');
    const cosecha = (catalogo.datos.productos || []).filter((p) => p.productor === elegido.nombre);
    $('#lista-productores').replaceChildren(
        el('p', {}, el('a', { href: '#productores' }, '← Todos los productores')),
        el('article', { class: 'tarjeta' },
            el('p', { class: 'hero-marca' }, elegido.region),
            el('h2', {}, elegido.nombre),
            el('p', {}, elegido.descripcion),
            el('div', { class: 'impacto-grid' },
                el('div', { class: 'impacto-item' }, el('strong', {}, String(elegido.productos)), el('span', {}, 'Productos activos')),
                el('div', { class: 'impacto-item' }, el('strong', {}, elegido.region), el('span', {}, 'Región de cosecha'))),
            el('p', { class: 'nota-seguridad' }, elegido.impacto || '')),
        el('h2', {}, 'Cosecha activa'),
        ...cosecha.map(tarjetaProducto));
}

async function cargarEnvases() {
    if (!sesion.token) { location.hash = 'cuenta'; return; }
    const { ok, datos } = await api('GET', '/api/pedidos/envases');
    if (!ok) { aviso(datos.error, true); return; }
    $('#envases-saldo').textContent = quetzales(datos.saldo || 0);
    const filas = datos.movimientos || [];
    const devueltos = filas.filter((m) => m.tipo === 'acreditacion').reduce((s, m) => s + (m.envases || 0), 0);
    const resumen = $('#envases-resumen');
    if (resumen) {
        resumen.replaceChildren(
            el('div', { class: 'impacto-item' }, el('strong', {}, quetzales(datos.saldo || 0)), el('span', {}, 'Saldo a favor')),
            el('div', { class: 'impacto-item' }, el('strong', {}, String(devueltos)), el('span', {}, 'Envases acreditados')),
            el('div', { class: 'impacto-item' }, el('strong', {}, String(filas.length)), el('span', {}, 'Movimientos')),
        );
    }
    $('#envases-movimientos').replaceChildren(...(filas.length ? filas.map((m) => el('div', { class: 'pedido-item' },
        el('div', {},
            el('strong', {}, m.tipo === 'acreditacion' ? 'Devolución acreditada' : m.tipo === 'uso' ? 'Usado en un pedido' : m.tipo),
            el('div', { class: 'uuid' }, m.nota || m.pedido_uuid || '')),
        el('div', {}, `${m.tipo === 'uso' ? '−' : '+'}${quetzales(m.monto)}`),
    )) : [el('p', { class: 'vacio' }, 'Aún no hay movimientos. El pase aparece cuando un pedido con envase retornable queda entregado.')]));
}

// ============================================================ Cuenta

function mostrarAuth(modo) {
    const esLogin = modo === 'login';
    document.querySelectorAll('.cuenta-tabs button').forEach((b) => b.classList.toggle('activo', b.dataset.auth === modo));
    $('#form-login').hidden = !esLogin;
    $('#form-registro').hidden = esLogin;
    $('#cuenta-titulo').textContent = esLogin ? 'Bienvenido de nuevo' : 'Crea tu cuenta';
    $('#cuenta-intro').textContent = esLogin
        ? 'Inicia sesión para ver tu carrito y confirmar pedidos.'
        : 'Completa tus datos para empezar a comprar en Mercado Verde.';
}

function cargarCuenta() {
    const usuario = sesion.usuario;
    const perfil = $('#panel-perfil');
    const auth = $('#panel-auth');
    if (!usuario) {
        perfil.hidden = true;
        auth.hidden = false;
        mostrarAuth('login');
        return;
    }
    auth.hidden = true;
    perfil.hidden = false;
    const inicial = (usuario.nombre || '?').trim().charAt(0).toUpperCase();
    $('#perfil-avatar').textContent = inicial;
    $('#perfil-nombre').textContent = usuario.nombre;
    $('#perfil-email').textContent = usuario.email;
    const badge = $('#perfil-rol');
    const nombresRol = { admin: 'Administrador', operador: 'Operador de bodega', contador: 'Contador', cliente: 'Cliente' };
    badge.textContent = nombresRol[usuario.rol] || usuario.rol;
    badge.className = `rol-badge${usuario.rol === 'admin' ? ' admin' : ''}`;
    const perms = usuario.permisos || [];
    $('#perfil-permisos').textContent = perms.length
        ? `Rol ${usuario.rol}. Permisos: ${perms.join(', ')}.`
        : 'Ver catálogo, usar el carrito, confirmar pedidos y consultar tu historial.';
    pintarImpactoCuenta();
    cargarDatosCuenta();
}

async function pintarImpactoCuenta() {
    const caja = $('#impacto-perfil');
    if (!caja || !sesion.token) return;
    const [pedidos, envases] = await Promise.all([
        api('GET', '/api/pedidos/'),
        api('GET', '/api/pedidos/envases'),
    ]);
    const lista = pedidos.ok ? (pedidos.datos.pedidos || []) : [];
    const entregados = lista.filter((p) => p.estado === 'entregado').length;
    const saldo = envases.ok ? envases.datos.saldo || 0 : 0;
    caja.replaceChildren(
        el('div', { class: 'impacto-item' }, el('strong', {}, String(lista.length)), el('span', {}, 'Pedidos')),
        el('div', { class: 'impacto-item' }, el('strong', {}, String(entregados)), el('span', {}, 'Entregas en casa')),
        el('div', { class: 'impacto-item' }, el('strong', {}, quetzales(saldo)), el('span', {}, 'Saldo de envases')),
    );
}

document.querySelectorAll('.cuenta-tabs button').forEach((boton) => {
    boton.addEventListener('click', () => mostrarAuth(boton.dataset.auth));
});

$('#form-login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/auth/login', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error || 'No se pudo iniciar sesión', true); return; }
    sesion.guardar(datos.token, datos.usuario);
    estado.accesoAdminRegistrado = false;
    e.target.reset();
    refrescarSesion();
    aviso(`Bienvenido, ${datos.usuario.nombre}`);
    const carrito = await api('GET', '/api/pedidos/carrito');
    if (carrito.ok) actualizarContador(carrito.datos);
    const staff = Array.isArray(datos.usuario.permisos) && datos.usuario.permisos.includes('panel.acceso');
    location.hash = staff ? 'admin-dashboard' : 'cuenta';
    if (location.hash === '#cuenta') cargarCuenta();
});

$('#form-registro').addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/auth/registro', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    e.target.reset();
    aviso('Cuenta creada. Ya puedes iniciar sesión.');
    mostrarAuth('login');
});

async function cerrarSesion() {
    await api('POST', '/api/auth/logout');
    sesion.limpiar();
    refrescarSesion();
    aviso('Sesión cerrada. Tu token fue revocado.');
    location.hash = 'catalogo';
}

$('#btn-logout').addEventListener('click', cerrarSesion);
$('#btn-logout-perfil').addEventListener('click', cerrarSesion);

function marcarPuntoGps(form, avisoEl, lat, lng) {
    if (!form) return;
    if (lat == null || lng == null || lat === '' || lng === '') {
        if (avisoEl) avisoEl.textContent = 'Todavía sin punto GPS. La dirección escrita igual sirve para el mapa.';
        return;
    }
    form.destino_lat.value = lat;
    form.destino_lng.value = lng;
    if (avisoEl) avisoEl.textContent = 'Ubicación lista. El mapa usará este punto y no hace falta escribir coordenadas.';
}

function pedirGps(alTener) {
    if (!window.isSecureContext) {
        aviso('El GPS solo funciona en http://localhost:8080. Si entraste por la dirección IP de la red, el navegador bloquea la ubicación.', true);
        return;
    }
    if (!navigator.geolocation) {
        aviso('Este navegador no tiene GPS.', true);
        return;
    }
    const leer = (preciso) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => alTener(pos.coords.latitude.toFixed(6), pos.coords.longitude.toFixed(6)),
            (err) => {
                if (preciso && err.code !== 1) {
                    leer(false);
                    return;
                }
                const texto = err.code === 1
                    ? 'El navegador bloqueó la ubicación. En el candado de la barra de direcciones elige Permitir, y en Windows activa Configuración, Privacidad, Ubicación.'
                    : 'Este equipo no entregó una ubicación. Activa la ubicación de Windows o escribe la dirección: el mapa igual la busca.';
                aviso(texto, true);
            },
            { enableHighAccuracy: preciso, timeout: preciso ? 8000 : 20000, maximumAge: 60000 },
        );
    };
    leer(true);
}

const etiquetaMarca = (marca) => ({ visa: 'Visa', mastercard: 'Mastercard', otra: 'Tarjeta' }[marca] || 'Tarjeta');

function pintarTarjetas(lista) {
    const caja = $('#lista-tarjetas');
    if (!caja) return;
    caja.replaceChildren(...(lista.length ? lista.map((t) => el('div', { class: 'pedido-item' },
        el('div', {},
            el('strong', {}, t.tipo === 'regalo' ? `Regalo · ${t.alias}` : `${etiquetaMarca(t.marca)} · ${t.alias}`),
            el('div', { class: 'uuid' }, t.tipo === 'regalo' ? `Saldo ${quetzales(t.saldo)} · ${t.codigo}` : `•••• ${t.ultimos4}`)),
        el('button', {
            type: 'button',
            class: 'secundario',
            onclick: async () => {
                const { ok, datos } = await api('DELETE', `/api/pagos/metodos/${t.id}`);
                if (!ok) { aviso(datos.error, true); return; }
                aviso(datos.mensaje || 'Tarjeta quitada');
                cargarDatosCuenta();
            },
        }, 'Quitar'),
    )) : [el('p', { class: 'vacio' }, 'Aún no tienes tarjetas guardadas.')]));
}

async function cargarDatosCuenta() {
    if (!sesion.token) return;
    const [perfil, direccion, tarjetas] = await Promise.all([
        api('GET', '/api/auth/me'),
        api('GET', '/api/pedidos/direccion'),
        api('GET', '/api/pagos/metodos'),
    ]);
    const formPerfil = $('#form-perfil');
    if (perfil.ok && formPerfil) {
        formPerfil.nombre.value = perfil.datos.usuario.nombre || '';
        formPerfil.telefono.value = perfil.datos.usuario.telefono || '';
        if (perfil.datos.usuario.nombre && sesion.usuario) {
            sesion.guardar(sesion.token, { ...sesion.usuario, nombre: perfil.datos.usuario.nombre, telefono: perfil.datos.usuario.telefono || null });
            refrescarSesion();
        }
    }
    const formLugar = $('#form-ubicacion');
    if (direccion.ok && formLugar && direccion.datos.direccion) {
        const guardada = direccion.datos.direccion;
        for (const campo of ['direccion', 'zona', 'municipio', 'telefono', 'referencias']) {
            if (formLugar[campo] && guardada[campo]) formLugar[campo].value = guardada[campo];
        }
        marcarPuntoGps(formLugar, $('#estado-gps-perfil'), guardada.lat, guardada.lng);
    }
    pintarTarjetas(tarjetas.ok ? (tarjetas.datos.metodos || []) : []);
}

$('#btn-gps')?.addEventListener('click', () => {
    pedirGps((lat, lng) => marcarPuntoGps($('#form-checkout'), $('#estado-gps'), lat, lng));
});

$('#btn-gps-perfil')?.addEventListener('click', () => {
    pedirGps((lat, lng) => marcarPuntoGps($('#form-ubicacion'), $('#estado-gps-perfil'), lat, lng));
});

$('#form-perfil')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const datosForm = Object.fromEntries(new FormData(e.target));
    const { ok, datos } = await api('PUT', '/api/auth/me', datosForm);
    if (!ok) { aviso(datos.error, true); return; }
    sesion.guardar(datos.token, datos.usuario);
    e.target.password_actual.value = '';
    e.target.password_nueva.value = '';
    refrescarSesion();
    cargarCuenta();
    aviso('Datos actualizados');
});

$('#form-ubicacion')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('PUT', '/api/pedidos/direccion', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Ubicación guardada');
});

$('#form-tarjeta-normal')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/pagos/metodos', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    e.target.reset();
    aviso(datos.mensaje || 'Tarjeta guardada');
    cargarDatosCuenta();
});

$('#form-tarjeta-regalo')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/pagos/metodos/canjear', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    e.target.reset();
    aviso(datos.mensaje || 'Tarjeta de regalo lista');
    cargarDatosCuenta();
});

// ============================================================ Administración

const graficos = { pedidos: null, seguridad: null, reporteDias: null };
let productosAdmin = [];
let pedidoAdminSeleccionado = null;
let transicionesAdmin = {};

function sincronizarMenuAdmin() {
    const enlaces = [...document.querySelectorAll('.menu a[data-admin-tab]')];
    enlaces.forEach((a) => {
        const permiso = a.dataset.permiso;
        a.hidden = !sesion.esStaff || (permiso ? !sesion.puede(permiso) : false) || !puedeVerNav(a.dataset.nav);
    });
    const label = document.querySelector('.menu-label[data-requiere="admin"]');
    if (label) label.hidden = !sesion.esStaff || enlaces.every((a) => a.hidden);

    let tab = estado.adminTab;
    const enlaceTab = enlaces.find((a) => a.dataset.adminTab === tab && !a.hidden);
    if (!enlaceTab) {
        const primero = enlaces.find((a) => !a.hidden);
        tab = primero?.dataset.adminTab || 'admin-dashboard';
        estado.adminTab = tab;
        if (location.hash.slice(1) !== tab && ADMIN_TABS[location.hash.slice(1)]) {
            location.hash = tab;
            return null;
        }
    }

    document.querySelectorAll('.tab-panel').forEach((p) => { p.hidden = p.id !== tab; });
    const titulo = $('#titulo-admin');
    if (titulo) titulo.textContent = TITULOS_ADMIN[tab] || 'Panel';
    return tab;
}

async function abrirAdmin() {
    if (!estado.accesoAdminRegistrado) {
        const { ok, datos } = await api('GET', '/api/auth/admin/acceso');
        if (!ok) { location.hash = 'catalogo'; return; }
        if (datos.permisos && sesion.usuario) {
            sesion.guardar(sesion.token, { ...sesion.usuario, permisos: datos.permisos, rol: datos.rol || sesion.usuario.rol });
            refrescarSesion();
        }
        estado.accesoAdminRegistrado = true;
    }
    const tabActiva = sincronizarMenuAdmin();
    if (!tabActiva) return;
    ({
        'admin-dashboard': cargarDashboard,
        'admin-productos': cargarAdminProductos,
        'admin-inventario': cargarInventario,
        'admin-clasificacion': cargarClasificacion,
        'admin-fefo': cargarFefo,
        'admin-pedidos': cargarAdminPedidos,
        'admin-pagos': cargarAdminPagos,
        'admin-capital': cargarCapital,
        'admin-reportes': cargarReportes,
        'admin-usuarios': cargarAdminUsuarios,
        'admin-roles': cargarAdminRoles,
        'admin-clientes': cargarAdminClientes,
        'admin-bitacora': cargarBitacora,
    })[tabActiva]?.();
}

function destruirGrafico(clave) {
    if (graficos[clave]) { graficos[clave].destroy(); graficos[clave] = null; }
}

function prepararGrafico(canvasId, clave, permitido, mensaje) {
    const canvas = $(canvasId);
    const tarjeta = canvas.closest('.tarjeta');
    let nota = tarjeta.querySelector('.nota-rol');
    if (!nota) {
        nota = el('p', { class: 'nota-rol' });
        tarjeta.append(nota);
    }
    if (!permitido) {
        destruirGrafico(clave);
        canvas.hidden = true;
        nota.hidden = false;
        nota.textContent = mensaje;
        return false;
    }
    canvas.hidden = false;
    nota.hidden = true;
    return true;
}

async function cargarDashboard() {
    const puedeProductos = sesion.puede('catalogo.gestionar');
    const puedePedidos = sesion.puede('pedidos.gestionar');
    const puedeBitacora = sesion.puede('bitacora.ver');
    const puedePagos = sesion.puede('pagos.ver');
    const [productos, pedidos, bitacora, pagos] = await Promise.all([
        puedeProductos ? api('GET', '/api/catalogo/admin/productos') : null,
        puedePedidos ? api('GET', '/api/pedidos/admin/pedidos') : null,
        puedeBitacora ? api('GET', '/api/auditoria/eventos/resumen') : null,
        puedePagos ? api('GET', '/api/pagos/admin/pagos') : null,
    ]);
    if ((productos && !productos.ok) || (pedidos && !pedidos.ok) || (bitacora && !bitacora.ok) || (pagos && !pagos.ok)) {
        aviso('Una parte del resumen no está disponible para este rol', true);
    }

    const listaProductos = productos?.ok ? productos.datos.productos || [] : [];
    const listaPedidos = pedidos?.ok ? pedidos.datos.pedidos || [] : [];
    const listaPagos = pagos?.ok ? pagos.datos.pagos || [] : [];
    const pendientes = listaPedidos.filter((p) => p.estado === 'pendiente' || p.estado === 'preparando').length;
    const ventas = listaPedidos
        .filter((p) => p.estado !== 'cancelado')
        .reduce((s, p) => s + Number(p.total), 0);
    const cobrado = listaPagos
        .filter((p) => p.estado === 'completado')
        .reduce((s, p) => s + Number(p.monto), 0);
    const fraudes = bitacora?.ok
        ? (bitacora.datos.por_accion || []).filter((f) => f.resultado === 'FRAUDE').reduce((s, f) => s + Number(f.total), 0)
        : 0;
    const kpis = [];
    if (productos?.ok) kpis.push(el('div', { class: 'kpi' }, el('div', { class: 'etiqueta' }, 'Productos'), el('div', { class: 'valor' }, listaProductos.length), el('div', { class: 'nota' }, `${listaProductos.filter((p) => p.activo).length} activos`)));
    if (pedidos?.ok) {
        kpis.push(el('div', { class: 'kpi' }, el('div', { class: 'etiqueta' }, 'Pedidos'), el('div', { class: 'valor' }, listaPedidos.length), el('div', { class: 'nota' }, `${pendientes} por atender`)));
        kpis.push(el('div', { class: 'kpi' }, el('div', { class: 'etiqueta' }, 'Ventas'), el('div', { class: 'valor' }, quetzales(ventas)), el('div', { class: 'nota' }, 'Sin cancelados')));
    }
    if (pagos?.ok) kpis.push(el('div', { class: 'kpi' }, el('div', { class: 'etiqueta' }, 'Pagos'), el('div', { class: 'valor' }, listaPagos.length), el('div', { class: 'nota' }, `${quetzales(cobrado)} cobrados`)));
    if (bitacora?.ok) kpis.push(el('div', { class: 'kpi' }, el('div', { class: 'etiqueta' }, 'Bitácora'), el('div', { class: 'valor' }, bitacora.datos.total_registros), el('div', { class: 'nota' }, `${fraudes} fraude(s) · ${bitacora.datos.tipos_de_evento}/14 tipos`)));
    $('#kpi-admin').replaceChildren(...kpis);

    const porEstado = {};
    listaPedidos.forEach((p) => { porEstado[p.estado] = (porEstado[p.estado] || 0) + 1; });
    if (prepararGrafico('#chart-pedidos', 'pedidos', Boolean(pedidos?.ok), 'Pedidos no entra en este rol. Lo ven el operador y el administrador.')) {
        destruirGrafico('pedidos');
        graficos.pedidos = new Chart($('#chart-pedidos'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(porEstado).map((e) => e.replace('_', ' ')),
                datasets: [{
                    data: Object.values(porEstado),
                    backgroundColor: ['#f0c419', '#5dade2', '#a569bd', '#5dade2', '#27ae60', '#e74c3c'],
                }],
            },
            options: { plugins: { legend: { position: 'bottom' } }, cutout: '58%' },
        });
    }

    if (prepararGrafico('#chart-seguridad', 'seguridad', Boolean(bitacora?.ok), 'La bitácora es solo del administrador.')) {
        const porAccion = {};
        (bitacora.datos.por_accion || []).forEach((f) => {
            porAccion[f.accion] = (porAccion[f.accion] || 0) + Number(f.total);
        });
        const top = Object.entries(porAccion).sort((a, b) => b[1] - a[1]).slice(0, 7);
        destruirGrafico('seguridad');
        graficos.seguridad = new Chart($('#chart-seguridad'), {
            type: 'bar',
            data: {
                labels: top.map(([a]) => a.replaceAll('_', ' ')),
                datasets: [{
                    label: 'Eventos',
                    data: top.map(([, n]) => n),
                    backgroundColor: '#0f6f78',
                    borderRadius: 8,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { maxRotation: 45, minRotation: 25, font: { size: 10 } } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }
}

// ---------- Productos

let productoParaImagen = null;
let productoEditando = null;

async function cargarAdminProductos() {
    const r = await api('GET', '/api/catalogo/categorias');
    if (r.ok) estado.categorias = r.datos.categorias;
    $('#form-producto').categoria_id.replaceChildren(...estado.categorias.map((c) => el('option', { value: String(c.id) }, c.nombre)));
    const origenes = await api('GET', '/api/catalogo/productores');
    const marca = $('#form-producto').productor_id;
    if (marca && origenes.ok) {
        const elegido = marca.value;
        marca.replaceChildren(
            el('option', { value: '' }, 'Elige la marca'),
            ...origenes.datos.productores.map((pr) => el('option', { value: String(pr.id) }, `${pr.nombre} · ${pr.region}`)),
        );
        marca.value = elegido;
    }

    const { ok, datos } = await api('GET', '/api/catalogo/admin/productos');
    if (!ok) { aviso(datos.error, true); return; }
    productosAdmin = datos.productos;
    pintarProductosAdmin();
}

function pintarProductosAdmin() {
    const q = ($('#buscar-producto-admin').value || '').trim().toLowerCase();
    const lista = q ? productosAdmin.filter((p) => p.nombre.toLowerCase().includes(q) || p.categoria.toLowerCase().includes(q) || (p.productor || '').toLowerCase().includes(q)) : productosAdmin;
    $('#tabla-productos').replaceChildren(...(lista.length ? lista.map(filaProducto) : [el('tr', {}, el('td', { colspan: '11', class: 'vacio' }, 'Sin resultados'))]));
}

function filaProducto(p) {
    const fresco = p.frescura;
    const dias = fresco?.vence ? Math.round((new Date(`${fresco.vence}T00:00:00`) - new Date()) / 86400000) : null;
    const detalle = [
        p.conservacion === 'refrigerado' ? 'Refrigerado' : 'Sin refrigerar',
        `Costo ${quetzales(p.precio_compra || 0)} + inyección ${quetzales(p.inyeccion || 0)} + margen ${quetzales(p.margen_utilidad || 0)}`,
        p.presentaciones?.length ? p.presentaciones.map((x) => `${x.nombre} · ${x.factor} ${nombreUnidad(p.unidad)}`).join(', ') : '',
        fresco ? `Lote ${fresco.codigo} · ingresó ${fresco.ingreso || '—'} · vence ${fresco.vence} (${dias} d)` : 'Sin lote',
    ].filter(Boolean);
    return el('tr', {},
        el('td', {}, p.imagen_url ? el('img', { class: 'miniatura', src: p.imagen_url, alt: '' }) : el('span', { class: 'emoji' }, p.icono)),
        el('td', { class: 'celda-producto' },
            el('strong', {}, p.nombre),
            el('div', { class: 'detalle-producto' }, ...detalle.map((linea) => el('span', {}, linea)))),
        el('td', {}, p.categoria),
        el('td', {}, p.productor || 'Sin marca'),
        el('td', {}, quetzales(p.precio)),
        el('td', {}, Number(p.precio_compra) > 0 ? quetzales(p.precio_compra) : 'Sin costo'),
        el('td', {}, quetzales(p.inyeccion || 0)),
        el('td', {}, String(p.stock)),
        el('td', {}, p.destacado ? 'Sí' : 'No'),
        el('td', {}, el('span', { class: p.activo ? 'badge-activo' : 'badge-inactivo' }, p.activo ? 'Activo' : 'Inactivo')),
        el('td', { class: 'celda-acciones' }, el('div', { class: 'acciones-producto' },
            botonIcono('edit', 'Editar', '', () => abrirEditarProducto(p)),
            botonIcono('image', 'Imagen', 'secundario', () => { productoParaImagen = p; $('#input-imagen').click(); }),
            botonIcono(p.activo ? 'visibility_off' : 'visibility', p.activo ? 'Desactivar' : 'Activar', 'secundario', () => cambiarActivoProducto(p)),
            p.activo ? botonIcono('delete', 'Eliminar', 'peligro', () => eliminarProducto(p)) : null)));
}

function botonIcono(icono, etiqueta, clase, onclick) {
    return el('button', {
        type: 'button',
        class: `icono-accion${clase ? ` ${clase}` : ''}`,
        title: etiqueta,
        'aria-label': etiqueta,
        onclick,
    }, el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, icono));
}

function camposExtraProducto(esNuevo) {
    ['bloque-categoria-nueva', 'bloque-marca-nueva', 'bloque-lote-ayuda', 'bloque-lote']
        .forEach((id) => { const nodo = document.getElementById(id); if (nodo) nodo.hidden = !esNuevo; });
    const activo = document.getElementById('campo-producto-activo');
    const destacado = document.getElementById('campo-producto-destacado');
    if (activo) activo.hidden = esNuevo;
    if (destacado) destacado.hidden = esNuevo;
}

function ventaBaseProducto() {
    const costo = Number($('#precio-costo')?.value || 0);
    const inyeccion = Number($('#precio-inyeccion')?.value || 0);
    const margen = Number($('#precio-margen')?.value || 0);
    return Math.round((costo + inyeccion + margen) * 100) / 100;
}

function actualizarPreciosProducto() {
    const venta = ventaBaseProducto();
    const campo = $('#precio-venta');
    if (campo) campo.value = venta > 0 ? venta.toFixed(2) : '';
    document.querySelectorAll('#lista-presentaciones .presentacion-fila').forEach((fila) => {
        const factor = Number(fila.querySelector('[data-campo=factor]')?.value || 0);
        const destino = fila.querySelector('[data-campo=venta]');
        if (destino) destino.value = venta > 0 && factor >= 1 ? (venta * factor).toFixed(2) : '';
    });
}

const NOMBRES_UNIDAD = {
    lb: 'libras', unidad: 'unidades', kg: 'kilogramos', docena: 'docenas',
    paquete: 'paquetes', litro: 'litros', galon: 'galones',
};

function nombreUnidad(codigo) {
    return NOMBRES_UNIDAD[codigo] || 'unidades';
}

function unidadBaseProducto() {
    return nombreUnidad(document.querySelector('#form-producto [name=unidad]')?.value || 'unidad');
}

function refrescarUnidadesPresentacion() {
    const texto = unidadBaseProducto();
    document.querySelectorAll('#lista-presentaciones .unidad-base').forEach((nodo) => { nodo.textContent = texto; });
}

function agregarFilaPresentacion(datos = {}) {
    const lista = $('#lista-presentaciones');
    if (!lista) return;
    const nombre = el('input', { 'data-campo': 'nombre', value: datos.nombre || '', maxlength: '80', placeholder: 'Red, docena, bolsa…' });
    const factor = el('input', { 'data-campo': 'factor', type: 'number', min: '1', step: '1', value: datos.factor ? String(datos.factor) : '1', placeholder: '5' });
    const venta = el('input', { 'data-campo': 'venta', readonly: true, placeholder: 'Se calcula' });
    factor.addEventListener('input', actualizarPreciosProducto);
    const fila = el('div', { class: 'presentacion-fila', 'data-id': datos.id ? String(datos.id) : '' },
        el('label', {}, 'Nombre', nombre),
        el('label', {}, 'Unidades de medida', factor, el('small', { class: 'unidad-base-nota' }, 'de ', el('span', { class: 'unidad-base' }, unidadBaseProducto()))),
        el('label', {}, 'Venta (Q)', venta),
        el('button', {
            type: 'button',
            class: 'icono-accion peligro',
            title: 'Quitar presentación',
            'aria-label': 'Quitar presentación',
            onclick: () => { fila.remove(); actualizarPreciosProducto(); },
        }, el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, 'delete')));
    lista.append(fila);
    actualizarPreciosProducto();
}

function pintarPresentaciones(lista) {
    const caja = $('#lista-presentaciones');
    if (caja) caja.replaceChildren();
    (lista || []).forEach((p) => agregarFilaPresentacion(p));
}

function leerPresentaciones() {
    return [...document.querySelectorAll('#lista-presentaciones .presentacion-fila')].map((fila) => {
        const item = {
            nombre: fila.querySelector('[data-campo=nombre]')?.value.trim() || '',
            factor: Number(fila.querySelector('[data-campo=factor]')?.value || 0),
        };
        if (fila.dataset.id) item.id = Number(fila.dataset.id);
        return item;
    }).filter((p) => p.nombre);
}

function desglosePrecioForm(form) {
    return {
        precio_compra: Number(form.precio_compra?.value || 0),
        inyeccion: Number(form.inyeccion?.value || 0),
        margen_utilidad: Number(form.margen_utilidad?.value || 0),
        precio: ventaBaseProducto(),
    };
}

function abrirNuevoProducto() {
    productoEditando = null;
    const form = $('#form-producto');
    form.reset();
    $('#titulo-modal-producto').textContent = 'Nuevo producto';
    $('#guardar-producto').textContent = 'Guardar';
    camposExtraProducto(true);
    pintarPresentaciones([]);
    actualizarPreciosProducto();
    const ingreso = form.lote_ingreso;
    if (ingreso && !ingreso.value) ingreso.value = new Date().toISOString().slice(0, 10);
    $('#modal-producto')?.showModal();
}

function abrirEditarProducto(p) {
    productoEditando = p;
    const form = $('#form-producto');
    form.reset();
    form.nombre.value = p.nombre || '';
    form.categoria_id.value = String(p.categoria_id || '');
    form.precio_compra.value = Number(p.precio_compra || 0).toFixed(2);
    form.inyeccion.value = Number(p.inyeccion || 0).toFixed(2);
    const margen = p.margen_utilidad != null
        ? Number(p.margen_utilidad)
        : Math.max(Number(p.precio || 0) - Number(p.precio_compra || 0), 0);
    form.margen_utilidad.value = margen.toFixed(2);
    actualizarPreciosProducto();
    form.stock.value = String(p.stock ?? 0);
    form.conservacion.value = p.conservacion || 'ambiente';
    form.unidad.value = p.unidad || 'unidad';
    form.productor_id.value = p.productor_id ? String(p.productor_id) : '';
    form.descripcion.value = p.descripcion || '';
    form.activo.checked = Boolean(p.activo);
    form.destacado.checked = Boolean(p.destacado);
    $('#titulo-modal-producto').textContent = 'Editar producto';
    $('#guardar-producto').textContent = 'Guardar cambios';
    camposExtraProducto(false);
    pintarPresentaciones(p.presentaciones || []);
    $('#modal-producto')?.showModal();
}

async function cambiarActivoProducto(p) {
    const { ok, datos } = await api('PUT', `/api/catalogo/admin/productos/${p.id}`, { activo: !p.activo });
    if (!ok) { aviso(datos.error, true); return; }
    aviso(p.activo ? `${p.nombre} quedó inactivo y ya no se vende` : `${p.nombre} quedó activo en la tienda`);
    cargarAdminProductos();
}

async function eliminarProducto(p) {
    if (!window.confirm(`¿Eliminar "${p.nombre}" de la tienda? Deja de venderse. El historial de pedidos se conserva y puedes activarlo otra vez.`)) return;
    const { ok, datos } = await api('DELETE', `/api/catalogo/admin/productos/${p.id}`);
    if (!ok) { aviso(datos.error, true); return; }
    aviso(datos.mensaje || `${p.nombre} salió de la tienda`);
    cargarAdminProductos();
}

$('#buscar-producto-admin').addEventListener('input', pintarProductosAdmin);

const inventarioEstado = { productos: [], filtro: 'todos' };
const FILTROS_INVENTARIO = [
    ['todos', 'Todos'],
    ['bajo', 'Bajo stock'],
    ['agotado', 'Agotados'],
    ['frio', 'Refrigerados'],
    ['seco', 'Sin refrigerar'],
    ['vence', 'Por vencer'],
];

function diasHastaFecha(fecha) {
    if (!fecha) return null;
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    return Math.round((new Date(`${fecha}T00:00:00`) - hoy) / 86400000);
}

function clasificarInventario(p) {
    const dias = diasHastaFecha(p.frescura?.vence);
    if (!p.activo) return { clave: 'inactivo', texto: 'Inactivo', clase: 'badge-inactivo' };
    if (Number(p.stock) <= 0) return { clave: 'agotado', texto: 'Agotado', clase: 'badge-inactivo' };
    if (dias !== null && dias < 0) return { clave: 'vence', texto: 'Vencido', clase: 'badge-inactivo' };
    if (dias !== null && dias <= 7) return { clave: 'vence', texto: 'Por vencer', clase: 'badge-alerta' };
    if (Number(p.stock) <= 10) return { clave: 'bajo', texto: 'Bajo', clase: 'badge-alerta' };
    return { clave: 'ok', texto: 'En bodega', clase: 'badge-activo' };
}

function pasaFiltroInventario(p, filtro) {
    const estado = clasificarInventario(p);
    if (filtro === 'bajo') return p.activo && Number(p.stock) > 0 && Number(p.stock) <= 10;
    if (filtro === 'agotado') return p.activo && Number(p.stock) <= 0;
    if (filtro === 'frio') return p.conservacion === 'refrigerado';
    if (filtro === 'seco') return p.conservacion !== 'refrigerado';
    if (filtro === 'vence') return estado.clave === 'vence';
    return true;
}

function metroStock(stock) {
    const cantidad = Math.max(0, Number(stock) || 0);
    const pct = Math.max(cantidad === 0 ? 6 : 8, Math.min(100, (cantidad / 40) * 100));
    const clase = cantidad <= 0 ? 'agotado' : cantidad <= 10 ? 'bajo' : 'ok';
    return el('div', { class: 'metro-stock' },
        el('div', { class: `metro-stock-pista ${clase}` }, el('i', { style: `width:${pct}%` })),
        el('small', {}, String(cantidad)));
}

async function cargarInventario() {
    const [productos, lotes] = await Promise.all([
        api('GET', '/api/catalogo/admin/productos'),
        api('GET', '/api/catalogo/admin/lotes'),
    ]);
    if (!productos.ok) { aviso(productos.datos.error || 'No se pudo cargar el inventario', true); return; }
    const enLote = {};
    (lotes.ok ? lotes.datos.lotes : []).forEach((lote) => {
        if (!['recibido', 'en_camara', 'listo'].includes(lote.estado)) return;
        enLote[lote.producto_id] = (enLote[lote.producto_id] || 0) + Number(lote.cantidad || 0);
    });
    inventarioEstado.productos = (productos.datos.productos || []).map((p) => ({ ...p, unidades_lote: enLote[p.id] || 0 }));
    pintarInventario();
}

function pintarInventario() {
    const q = ($('#buscar-inventario')?.value || '').trim().toLowerCase();
    const lista = inventarioEstado.productos.filter((p) => {
        const texto = `${p.nombre} ${p.categoria || ''} ${p.productor || ''}`.toLowerCase();
        return (!q || texto.includes(q)) && pasaFiltroInventario(p, inventarioEstado.filtro);
    });
    const activos = inventarioEstado.productos.filter((p) => p.activo);
    const unidades = activos.reduce((s, p) => s + Number(p.stock || 0), 0);
    const valor = activos.reduce((s, p) => s + Number(p.stock || 0) * Number(p.precio_compra || 0), 0);
    const bajos = activos.filter((p) => Number(p.stock) > 0 && Number(p.stock) <= 10).length;
    const agotados = activos.filter((p) => Number(p.stock) <= 0).length;
    const porVencer = inventarioEstado.productos.filter((p) => clasificarInventario(p).clave === 'vence').length;
    $('#inventario-cifras')?.replaceChildren(
        el('article', {}, el('span', {}, 'Productos activos'), el('strong', {}, String(activos.length))),
        el('article', {}, el('span', {}, 'Unidades en bodega'), el('strong', {}, String(unidades))),
        el('article', { class: bajos ? 'alerta' : '' }, el('span', {}, 'Bajo stock'), el('strong', {}, String(bajos))),
        el('article', { class: agotados ? 'peligro' : '' }, el('span', {}, 'Agotados'), el('strong', {}, String(agotados))),
        el('article', { class: porVencer ? 'alerta' : '' }, el('span', {}, 'Por vencer o vencidos'), el('strong', {}, String(porVencer))),
        el('article', {}, el('span', {}, 'Valor a costo'), el('strong', {}, quetzales(valor))),
    );
    $('#filtros-inventario')?.replaceChildren(...FILTROS_INVENTARIO.map(([clave, texto]) => el('button', {
        type: 'button',
        class: inventarioEstado.filtro === clave ? 'activo' : '',
        onclick: () => { inventarioEstado.filtro = clave; pintarInventario(); },
    }, texto)));
    $('#tabla-inventario')?.replaceChildren(...(lista.length ? lista.map((p) => {
        const estado = clasificarInventario(p);
        const dias = diasHastaFecha(p.frescura?.vence);
        const lote = p.frescura
            ? `${p.frescura.codigo} · vence ${p.frescura.vence}${dias === null ? '' : ` (${dias} d)`}${p.unidades_lote ? ` · ${p.unidades_lote} en lotes` : ''}`
            : 'Sin lote';
        return el('tr', {},
            el('td', { class: 'celda-producto' }, el('strong', {}, p.nombre), el('div', { class: 'detalle-producto' }, el('span', {}, p.categoria || 'Sin categoría'))),
            el('td', {}, p.conservacion === 'refrigerado' ? 'Refrigerado' : 'Sin refrigerar'),
            el('td', {}, metroStock(p.stock)),
            el('td', {}, el('div', {}, quetzales(p.inyeccion || 0), el('div', { class: 'detalle-producto' }, el('span', {}, `en bodega ${quetzales(Number(p.stock || 0) * Number(p.inyeccion || 0))}`)))),
            el('td', {}, lote),
            el('td', {}, el('span', { class: estado.clase }, estado.texto)),
            el('td', {}, Number(p.precio_compra) > 0 ? quetzales(Number(p.stock || 0) * Number(p.precio_compra)) : 'Sin costo'));
    }) : [el('tr', {}, el('td', { colspan: '7', class: 'vacio' }, 'No hay productos con ese filtro'))]));
}

$('#buscar-inventario')?.addEventListener('input', pintarInventario);

async function cargarClasificacion() {
    const { ok, datos } = await api('GET', '/api/catalogo/admin/clasificacion');
    if (!ok) { aviso(datos.error || 'No se pudo cargar categorías y marcas', true); return; }
    $('#tabla-categorias').replaceChildren(...(datos.categorias.length ? datos.categorias.map((c) => el('tr', {},
        el('td', {}, `${c.icono || ''} ${c.nombre}`.trim()),
        el('td', {}, c.productos),
    )) : [el('tr', {}, el('td', { colspan: '2', class: 'vacio' }, 'Aún no hay categorías'))]));
    $('#tabla-marcas').replaceChildren(...(datos.marcas.length ? datos.marcas.map((m) => el('tr', {},
        el('td', {}, m.nombre),
        el('td', {}, m.region),
        el('td', {}, m.productos),
    )) : [el('tr', {}, el('td', { colspan: '3', class: 'vacio' }, 'Aún no hay marcas'))]));
}

function abrirDialogo(id) {
    document.getElementById(id)?.showModal();
}

function cerrarDialogo(id) {
    const modal = document.getElementById(id);
    if (modal?.open) modal.close();
}

$('#btn-nueva-categoria')?.addEventListener('click', () => {
    $('#form-categoria')?.reset();
    abrirDialogo('modal-categoria');
});
$('#btn-nueva-marca')?.addEventListener('click', () => {
    $('#form-marca')?.reset();
    abrirDialogo('modal-marca');
});
['cerrar-modal-categoria', 'cancelar-modal-categoria'].forEach((id) => {
    document.getElementById(id)?.addEventListener('click', () => cerrarDialogo('modal-categoria'));
});
['cerrar-modal-marca', 'cancelar-modal-marca'].forEach((id) => {
    document.getElementById(id)?.addEventListener('click', () => cerrarDialogo('modal-marca'));
});
$('#modal-categoria')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) cerrarDialogo('modal-categoria'); });
$('#modal-marca')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) cerrarDialogo('modal-marca'); });

$('#form-categoria')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/catalogo/admin/categorias', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Categoría lista. Ya puedes amarrarla a un producto.');
    e.target.reset();
    cerrarDialogo('modal-categoria');
    estado.categorias = [];
    cargarClasificacion();
});

$('#form-marca')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/catalogo/admin/marcas', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Marca lista. Ya puedes amarrarla a un producto.');
    e.target.reset();
    cerrarDialogo('modal-marca');
    cargarClasificacion();
});

function cerrarModalProducto() {
    const modal = $('#modal-producto');
    if (modal?.open) modal.close();
}
$('#btn-nuevo-producto').addEventListener('click', abrirNuevoProducto);
['precio-costo', 'precio-inyeccion', 'precio-margen'].forEach((id) => {
    document.getElementById(id)?.addEventListener('input', actualizarPreciosProducto);
});
$('#btn-agregar-presentacion')?.addEventListener('click', () => agregarFilaPresentacion());
document.querySelector('#form-producto [name=unidad]')?.addEventListener('change', refrescarUnidadesPresentacion);
$('#cerrar-modal-producto').addEventListener('click', cerrarModalProducto);
$('#cancelar-modal-producto').addEventListener('click', cerrarModalProducto);
$('#modal-producto').addEventListener('click', (e) => { if (e.target === e.currentTarget) cerrarModalProducto(); });

$('#form-producto').addEventListener('submit', async (e) => {
    e.preventDefault();
    const datosForm = Object.fromEntries(new FormData(e.target));
    if (productoEditando) {
        const precios = desglosePrecioForm(e.target);
        const cuerpo = {
            nombre: datosForm.nombre,
            descripcion: datosForm.descripcion || '',
            ...precios,
            stock: Number(datosForm.stock),
            categoria_id: Number(datosForm.categoria_id),
            unidad: datosForm.unidad,
            conservacion: datosForm.conservacion,
            activo: Boolean(e.target.activo?.checked),
            destacado: Boolean(e.target.destacado?.checked),
            presentaciones: leerPresentaciones(),
        };
        if (datosForm.productor_id) cuerpo.productor_id = Number(datosForm.productor_id);
        const respuesta = await api('PUT', `/api/catalogo/admin/productos/${productoEditando.id}`, cuerpo);
        if (!respuesta.ok) { aviso(respuesta.datos.error, true); return; }
        aviso(`${respuesta.datos.producto.nombre} actualizado`);
        productoEditando = null;
        e.target.reset();
        cerrarModalProducto();
        cargarAdminProductos();
        return;
    }
    Object.assign(datosForm, desglosePrecioForm(e.target));
    datosForm.presentaciones = leerPresentaciones();
    datosForm.stock = Number(datosForm.stock);
    datosForm.categoria_id = Number(datosForm.categoria_id);
    if (datosForm.precio_compra !== '') datosForm.precio_compra = Number(datosForm.precio_compra);
    else delete datosForm.precio_compra;
    if (!datosForm.productor_id) delete datosForm.productor_id;
    if (!datosForm.categoria_nueva) delete datosForm.categoria_nueva;
    if (!datosForm.marca_nombre) delete datosForm.marca_nombre;
    if (!datosForm.presentacion_nombre) {
        delete datosForm.presentacion_nombre;
        delete datosForm.presentacion_factor;
        delete datosForm.presentacion_precio;
    } else {
        datosForm.presentacion_factor = Number(datosForm.presentacion_factor);
        datosForm.presentacion_precio = Number(datosForm.presentacion_precio);
    }
    if (!datosForm.lote_codigo && !datosForm.lote_vence) {
        delete datosForm.lote_codigo;
        delete datosForm.lote_ingreso;
        delete datosForm.lote_vence;
    }
    const { ok, datos } = await api('POST', '/api/catalogo/admin/productos', datosForm);
    if (!ok) { aviso(datos.error, true); return; }
    aviso(`Producto "${datos.producto.nombre}" creado`);
    e.target.reset();
    cerrarModalProducto();
    estado.categorias = [];
    cargarAdminProductos();
});

/** Subida firmada: el servidor firma, el navegador sube directo a Cloudinary (el API secret nunca sale del servidor). */
$('#input-imagen').addEventListener('change', async (e) => {
    const archivo = e.target.files[0];
    e.target.value = '';
    if (!archivo || !productoParaImagen) return;
    if (archivo.size > 2 * 1024 * 1024) { aviso('La imagen no puede pesar más de 2 MB', true); return; }

    const firma = await api('POST', '/api/catalogo/admin/cloudinary/firma');
    if (!firma.ok) { aviso(firma.datos.error, true); return; }

    const form = new FormData();
    form.append('file', archivo);
    form.append('api_key', firma.datos.api_key);
    form.append('timestamp', firma.datos.timestamp);
    form.append('folder', firma.datos.folder);
    form.append('signature', firma.datos.signature);

    aviso('Subiendo imagen a Cloudinary…');
    const subida = await fetch(`https://api.cloudinary.com/v1_1/${encodeURIComponent(firma.datos.cloud_name)}/image/upload`, { method: 'POST', body: form });
    const resultado = await subida.json();
    if (!subida.ok) { aviso(resultado.error?.message || 'Cloudinary rechazó la imagen', true); return; }

    const urlOptimizada = resultado.secure_url.replace('/image/upload/', '/image/upload/c_fill,w_400,h_400,q_auto,f_auto/');
    const { ok, datos } = await api('PUT', `/api/catalogo/admin/productos/${productoParaImagen.id}`, { imagen_url: urlOptimizada });
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Imagen actualizada');
    cargarAdminProductos();
});

// ---------- Pedidos

const FLUJO_PEDIDO = ['pendiente', 'pagado', 'preparando', 'en_camino', 'entregado'];
const ETIQUETA_ACCION = {
    pagado: 'Marcar como pagado',
    preparando: 'Aprobar y preparar',
    en_camino: 'Enviar a camino',
    entregado: 'Marcar entregado',
    cancelado: 'Cancelar pedido',
};

function cerrarDetallePedidoAdmin() {
    pedidoAdminSeleccionado = null;
    $('#panel-detalle-pedido').hidden = true;
    $('#lista-pedidos-admin').hidden = false;
}

async function cambiarEstadoPedido(uuid, estado) {
    const r = await api('PUT', `/api/pedidos/admin/pedidos/${uuid}/estado`, { estado });
    if (!r.ok) { aviso(r.datos.error, true); return false; }
    aviso(`Pedido actualizado a "${estado.replace('_', ' ')}"`);
    return true;
}

async function cargarAdminPedidos() {
    const filtro = $('#filtro-estado').value;
    const { ok, datos } = await api('GET', `/api/pedidos/admin/pedidos${filtro ? `?estado=${encodeURIComponent(filtro)}` : ''}`);
    if (!ok) { aviso(datos.error, true); return; }
    transicionesAdmin = datos.transiciones || {};

    if (pedidoAdminSeleccionado) {
        await verPedidoAdmin(pedidoAdminSeleccionado);
        return;
    }

    $('#lista-pedidos-admin').hidden = false;
    $('#panel-detalle-pedido').hidden = true;
    $('#tabla-pedidos').replaceChildren(...(datos.pedidos.length ? datos.pedidos.map((p) => el('tr', {},
        el('td', {}, new Date(p.fecha.replace(' ', 'T')).toLocaleString('es-GT')),
        el('td', {}, el('div', {}, p.cliente), el('small', {}, p.email)),
        el('td', {}, p.municipio || '—'),
        el('td', {}, quetzales(p.total)),
        el('td', {}, etiquetaEstado(p.estado)),
        el('td', {}, el('button', {
            type: 'button', class: 'mini',
            onclick: () => verPedidoAdmin(p.uuid),
        }, 'Gestionar')),
    )) : [el('tr', {}, el('td', { colspan: '6', class: 'vacio' }, 'No hay pedidos'))]));
}

async function verPedidoAdmin(uuid) {
    pedidoAdminSeleccionado = uuid;
    const { ok, datos } = await api('GET', `/api/pedidos/${uuid}`);
    if (!ok) { aviso(datos.error, true); return; }
    const p = datos.pedido;
    const permitidos = p.transiciones_permitidas || transicionesAdmin[p.estado] || [];

    $('#lista-pedidos-admin').hidden = true;
    $('#panel-detalle-pedido').hidden = false;

    const pasos = el('div', { class: 'pasos-pedido' },
        ...FLUJO_PEDIDO.filter((e) => e !== 'pagado').map((estado) => el('span', {
            class: `paso${p.estado === estado ? ' actual' : ''}${FLUJO_PEDIDO.indexOf(p.estado) > FLUJO_PEDIDO.indexOf(estado) ? ' hecho' : ''}`,
        }, estado.replace('_', ' '))));

    const botonesEstado = permitidos.length
        ? permitidos.map((estado) => el('button', {
            type: 'button',
            class: estado === 'cancelado' ? 'peligro' : '',
            onclick: async () => {
                if (estado === 'cancelado' && !confirm('¿Cancelar este pedido? Se repondrá el stock.')) return;
                if (await cambiarEstadoPedido(uuid, estado)) verPedidoAdmin(uuid);
            },
        }, ETIQUETA_ACCION[estado] || estado.replace('_', ' ')))
        : [el('p', { class: 'vacio' }, p.estado === 'entregado'
            ? 'Este pedido ya fue entregado. No hay más cambios.'
            : 'Este pedido está cancelado. No hay más cambios.')];
    const acciones = el('div', { class: 'acciones-estado' }, ...botonesEstado);

    montar($('#detalle-pedido-admin'),
        el('div', { class: 'barra' },
            el('h2', {}, 'Gestionar pedido'),
            el('button', { type: 'button', class: 'secundario', onclick: cerrarDetallePedidoAdmin }, 'Volver a la lista')),
        el('p', { class: 'uuid' }, p.uuid),
        el('p', {}, 'Estado actual: ', etiquetaEstado(p.estado)),
        pasos,
        el('h3', { class: 'subtitulo-admin' }, 'Cambiar estado'),
        acciones,
        el('hr', { class: 'separador-suave' }),
        el('p', {}, el('strong', {}, 'Cliente: '), `${p.cliente} · ${p.email}`),
        el('p', {}, el('strong', {}, 'Entrega: '), `${p.direccion_entrega}${p.zona ? `, ${p.zona}` : ''}, ${p.municipio}`),
        el('p', {}, el('strong', {}, 'Teléfono: '), p.telefono),
        p.referencias ? el('p', {}, el('strong', {}, 'Referencias: '), p.referencias) : null,
        p.notas ? el('p', {}, el('strong', {}, 'Notas: '), p.notas) : null,
        el('h3', { class: 'subtitulo-admin' }, 'Productos'),
        el('div', { class: 'tabla-scroll' }, el('table', {},
            el('thead', {}, el('tr', {}, el('th', {}, 'Producto'), el('th', {}, 'Cant.'), el('th', {}, 'Subtotal'))),
            el('tbody', {}, ...p.detalle.map((d) => el('tr', {},
                el('td', {}, d.nombre || '—'), el('td', {}, d.cantidad), el('td', {}, quetzales(d.subtotal))))))),
        el('p', { class: 'total' }, 'Total: ', el('strong', {}, quetzales(p.total))),
        bloqueEfectivoAdmin(p),
        formularioRuta(p));
}

function bloqueEfectivoAdmin(p) {
    const caja = el('div', {});
    api('GET', `/api/pagos/pedido/${p.uuid}`).then(({ ok, datos }) => {
        if (!ok || datos.pago?.proveedor !== 'efectivo') return;
        const pago = datos.pago;
        if (pago.estado === 'completado') {
            caja.replaceChildren(el('p', {}, `Efectivo recibido: ${quetzales(pago.monto)}.`));
            return;
        }
        if (pago.estado !== 'iniciado') return;
        const puedeCobrar = p.estado === 'en_camino' || p.estado === 'entregado';
        caja.replaceChildren(
            el('p', { class: 'nota-seguridad' }, `Pago contra entrega: cobrar ${quetzales(pago.monto)} en efectivo en la puerta.`),
            puedeCobrar ? el('button', {
                type: 'button',
                onclick: async () => {
                    const resp = await api('POST', '/api/pagos/admin/cobrar-efectivo', { pedido_uuid: p.uuid });
                    if (!resp.ok) { aviso(resp.datos.error, true); return; }
                    aviso(resp.datos.mensaje || 'Efectivo registrado');
                    verPedidoAdmin(p.uuid);
                },
            }, 'Registrar efectivo recibido') : el('p', {}, 'El botón para registrar el efectivo aparece cuando el pedido va en ruta o ya llegó.'),
        );
    });
    return caja;
}

function formularioRuta(p) {
    const nombre = el('input', { value: p.repartidor_nombre || '', maxlength: '80', placeholder: 'Nombre del repartidor' });
    const texto = el('input', { maxlength: '300', placeholder: 'Mensaje al cliente' });
    return el('div', {},
        el('h3', {}, 'Ruta y reparto'),
        el('p', {}, p.destino_lat != null
            ? 'El cliente ya marcó su entrega con el GPS. Desde aquí solo tomas el GPS del repartidor, con este teléfono.'
            : 'El cliente no usó GPS. Su mapa muestra la dirección escrita. El GPS del repartidor se toma con este teléfono.'),
        nombre,
        el('button', {
            type: 'button',
            class: 'secundario',
            onclick: async () => {
                const { ok, datos } = await api('PUT', `/api/pedidos/admin/pedidos/${p.uuid}/ruta`, {
                    repartidor_nombre: nombre.value,
                });
                if (!ok) { aviso(datos.error, true); return; }
                aviso('Nombre del repartidor guardado');
                verPedidoAdmin(p.uuid);
            },
        }, 'Guardar nombre'),
        el('button', {
            type: 'button',
            onclick: () => pedirGps(async (lat, lng) => {
                const { ok, datos } = await api('PUT', `/api/pedidos/admin/pedidos/${p.uuid}/ruta`, {
                    repartidor_nombre: nombre.value,
                    lat,
                    lng,
                });
                if (!ok) { aviso(datos.error, true); return; }
                aviso('GPS del repartidor guardado. El cliente lo ve cuando el pedido va en ruta.');
                verPedidoAdmin(p.uuid);
            }),
        }, 'Tomar GPS de este teléfono'),
        el('div', { class: 'chat' }, ...(p.mensajes || []).map((m) => el('div', { class: `burbuja${m.autor === 'equipo' ? ' equipo' : ''}` }, m.texto))),
        el('div', { class: 'fila-chat' },
            texto,
            el('button', {
                type: 'button',
                class: 'secundario',
                onclick: async () => {
                    if (!texto.value.trim()) return;
                    const { ok, datos } = await api('POST', `/api/pedidos/${p.uuid}/mensajes`, { texto: texto.value });
                    if (!ok) { aviso(datos.error, true); return; }
                    verPedidoAdmin(p.uuid);
                },
            }, 'Responder')));
}

function filaLote(l, conTemperatura) {
    const hoy = new Date();
    const vence = new Date(`${l.fecha_vencimiento}T00:00:00`);
    const dias = Math.round((vence - hoy) / 86400000);
    const celdas = [
        el('td', {}, l.fecha_ingreso || '—'),
        el('td', { class: dias <= 3 && l.estado !== 'merma' ? 'lote-pronto' : '' }, `${l.fecha_vencimiento} (${dias} d)`),
        el('td', {}, l.codigo),
        el('td', {}, l.producto),
        el('td', {}, l.cantidad),
    ];
    if (conTemperatura) celdas.push(el('td', {}, l.temperatura ?? '—'));
    celdas.push(
        el('td', {}, l.estado),
        el('td', {}, l.estado === 'merma' ? '' : el('button', {
            type: 'button',
            class: 'peligro mini',
            onclick: async () => {
                if (!confirm(`¿Pasar ${l.codigo} a merma? Se descuenta del stock.`)) return;
                const r = await api('PUT', `/api/catalogo/admin/lotes/${l.id}`, { estado: 'merma' });
                if (!r.ok) { aviso(r.datos.error, true); return; }
                aviso('Lote marcado como merma');
                cargarFefo();
            },
        }, 'Merma')),
    );
    return el('tr', {}, ...celdas);
}

function sincronizarTemperaturaLote() {
    const select = $('#form-lote')?.producto_id;
    const frio = select?.selectedOptions?.[0]?.dataset.conservacion === 'refrigerado';
    const campo = $('#campo-temperatura-lote');
    if (campo) campo.hidden = !frio;
}

function llenarPreciosLote() {
    const opcion = $('#form-lote')?.producto_id?.selectedOptions?.[0];
    const costo = Number(opcion?.dataset.precioCompra || 0);
    const inyeccion = Number(opcion?.dataset.inyeccion || 0);
    const margen = Number(opcion?.dataset.margen || 0);
    const form = $('#form-lote');
    if ($('#compra-anterior')) $('#compra-anterior').value = quetzales(costo);
    if ($('#inyeccion-anterior')) $('#inyeccion-anterior').value = quetzales(inyeccion);
    if ($('#margen-anterior')) $('#margen-anterior').value = quetzales(margen);
    if (form?.precio_compra) form.precio_compra.value = costo.toFixed(2);
    if (form?.inyeccion) form.inyeccion.value = inyeccion.toFixed(2);
    if (form?.margen_utilidad) form.margen_utilidad.value = margen.toFixed(2);
    actualizarVentaLote();
}

function actualizarVentaLote() {
    const form = $('#form-lote');
    const venta = Number(form?.precio_compra?.value || 0) + Number(form?.inyeccion?.value || 0) + Number(form?.margen_utilidad?.value || 0);
    const campo = $('#venta-lote');
    if (campo) campo.value = venta > 0 ? quetzales(venta) : '';
}

async function cargarFefo() {
    const [lotes, productos] = await Promise.all([
        api('GET', '/api/catalogo/admin/lotes'),
        api('GET', '/api/catalogo/admin/productos'),
    ]);
    if (!lotes.ok) { aviso(lotes.datos.error || 'No se pudieron cargar los lotes', true); return; }
    const select = $('#form-lote')?.producto_id;
    if (select && productos.ok) {
        const elegido = select.value;
        select.replaceChildren(...productos.datos.productos.filter((p) => p.activo).map((p) => el('option', {
            value: String(p.id),
            'data-conservacion': p.conservacion || 'ambiente',
            'data-precio': String(p.precio || 0),
            'data-precio-compra': String(p.precio_compra || 0),
            'data-inyeccion': String(p.inyeccion || 0),
            'data-margen': String(p.margen_utilidad || 0),
        }, `${p.nombre} · ${p.conservacion === 'refrigerado' ? 'frío' : 'sin refri'}`)));
        if (elegido) select.value = elegido;
        sincronizarTemperaturaLote();
    }
    const lista = lotes.datos.lotes || [];
    const frios = lista.filter((l) => l.conservacion === 'refrigerado');
    const ambiente = lista.filter((l) => l.conservacion !== 'refrigerado');
    const vacio = (texto, columnas) => [el('tr', {}, el('td', { colspan: String(columnas), class: 'vacio' }, texto))];
    $('#tabla-lotes').replaceChildren(...(frios.length ? frios.map((l) => filaLote(l, true)) : vacio('No hay lotes refrigerados.', 8)));
    $('#tabla-lotes-ambiente').replaceChildren(...(ambiente.length ? ambiente.map((l) => filaLote(l, false)) : vacio('No hay lotes frescos o secos.', 7)));
}

$('#form-lote')?.producto_id?.addEventListener('change', () => {
    sincronizarTemperaturaLote();
    llenarPreciosLote();
});
['precio_compra', 'inyeccion', 'margen_utilidad'].forEach((nombre) => {
    $('#form-lote')?.[nombre]?.addEventListener('input', actualizarVentaLote);
});

$('#filtro-estado').addEventListener('change', () => {
    cerrarDetallePedidoAdmin();
    cargarAdminPedidos();
});

// ---------- Pagos

async function cargarAdminPagos() {
    const filtro = $('#filtro-pago-estado').value;
    const { ok, datos } = await api('GET', `/api/pagos/admin/pagos${filtro ? `?estado=${encodeURIComponent(filtro)}` : ''}`);
    if (!ok) { aviso(datos.error, true); return; }
    $('#tabla-pagos').replaceChildren(...(datos.pagos.length ? datos.pagos.map((p) => el('tr', {},
        el('td', {}, new Date(String(p.created_at).replace(' ', 'T')).toLocaleString('es-GT')),
        el('td', { class: 'uuid' }, p.pedido_uuid),
        el('td', {}, p.id_cliente),
        el('td', {}, quetzales(p.monto)),
        el('td', {}, Number(p.descuento_regalo) > 0 ? quetzales(p.descuento_regalo) : '—'),
        el('td', {}, p.metodo_etiqueta || '—'),
        el('td', {}, el('span', { class: p.estado === 'completado' ? 'EXITO' : '' }, p.estado)),
        el('td', {}, p.proveedor),
    )) : [el('tr', {}, el('td', { colspan: '8', class: 'vacio' }, 'No hay pagos'))]));
}

$('#filtro-pago-estado').addEventListener('change', cargarAdminPagos);

async function cargarCapital() {
    const mesInput = $('#capital-mes');
    if (mesInput && !mesInput.value) {
        const hoy = new Date();
        mesInput.value = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
    }
    const mesValor = mesInput?.value || '';
    const mes = encodeURIComponent(mesValor);
    const [caja, ventas, cobros] = await Promise.all([
        api('GET', `/api/catalogo/admin/capital?mes=${mes}`),
        api('GET', `/api/pedidos/admin/capital?mes=${mes}`),
        api('GET', `/api/pagos/admin/capital?mes=${mes}`),
    ]);
    if (!caja.ok || !ventas.ok || !cobros.ok) {
        aviso(caja.datos?.error || ventas.datos?.error || cobros.datos?.error || 'No se pudo cargar el capital', true);
        return;
    }
    const nombreMes = mesValor
        ? new Date(`${mesValor}-01T00:00:00`).toLocaleDateString('es-GT', { month: 'long', year: 'numeric' })
        : 'este mes';
    const notaMes = $('#capital-mes-activo');
    if (notaMes) {
        notaMes.textContent = `Mostrando ${nombreMes}. El efectivo y la ganancia son de este mes. El capital en productos es la mercancía de hoy, por eso no cambia al buscar otro mes.`;
    }
    const tituloEfectivo = $('#capital-titulo-efectivo');
    const tituloGanancia = $('#capital-titulo-ganancia');
    if (tituloEfectivo) tituloEfectivo.textContent = `Efectivo de ${nombreMes}`;
    if (tituloGanancia) tituloGanancia.textContent = `Ganancias de ${nombreMes}`;
    const pagados = new Set(cobros.datos.uuids || []);
    const porProducto = new Map((caja.datos.productos || []).map((p) => [p.id, p]));
    const gananciaFilas = [];
    let ventaCobrada = 0;
    let costoVendido = 0;
    for (const linea of ventas.datos.lineas || []) {
        if (!pagados.has(linea.uuid)) continue;
        const estimado = linea.costo_unitario == null;
        const costoUnit = estimado
            ? Number(porProducto.get(linea.producto_id)?.precio_compra || 0) * Number(linea.factor || 1)
            : Number(linea.costo_unitario);
        const costoLinea = costoUnit * Number(linea.cantidad);
        const margenLinea = linea.margen_unitario == null ? null : Number(linea.margen_unitario) * Number(linea.cantidad);
        const ganancia = margenLinea == null ? Number(linea.subtotal) - costoLinea : margenLinea;
        ventaCobrada += Number(linea.subtotal);
        costoVendido += costoLinea;
        gananciaFilas.push({ linea, costoLinea, ganancia, estimado });
    }
    const efectivo = Number(caja.datos.inyectado) + Number(cobros.datos.cobrado) - Number(caja.datos.compras) - Number(caja.datos.retiros || 0);
    const gananciaMes = ventaCobrada - costoVendido;
    $('#capital-cifras').replaceChildren(
        el('article', {}, el('span', {}, 'Capital en productos, hoy'), el('strong', {}, quetzales(caja.datos.capital_productos))),
        el('article', {}, el('span', {}, `Efectivo de ${nombreMes}`), el('strong', { class: efectivo < 0 ? 'negativo' : '' }, quetzales(efectivo))),
        el('article', {}, el('span', {}, `Ganancia de ${nombreMes}`), el('strong', { class: gananciaMes < 0 ? 'negativo' : '' }, quetzales(gananciaMes))),
    );
    const ayudaRetiro = $('#capital-retiro-ayuda');
    if (ayudaRetiro) {
        ayudaRetiro.textContent = `Puedes retirar hasta ${quetzales(Math.max(efectivo, 0))} del efectivo de ${nombreMes}. Ese dinero es lo inyectado más lo cobrado a los clientes, menos compras y retiros. El capital en productos es mercancía y se queda en bodega.`;
    }
    $('#tabla-capital-productos').replaceChildren(...(caja.datos.productos.length ? caja.datos.productos.map((p) => {
        const sinCosto = Number(p.precio_compra) <= 0;
        return el('tr', {},
            el('td', {}, p.nombre),
            el('td', {}, p.stock),
            el('td', {}, sinCosto ? 'Sin precio de compra' : quetzales(p.precio_compra)),
            el('td', {}, quetzales(p.inyeccion || 0)),
            el('td', {}, sinCosto ? 'Falta el costo' : quetzales(p.margen)),
            el('td', {}, quetzales(p.precio_venta)),
            el('td', {}, sinCosto ? 'Q0.00' : quetzales(p.capital)),
        );
    }) : [el('tr', {}, el('td', { colspan: '7', class: 'vacio' }, 'No hay productos activos'))]));
    $('#tabla-capital-caja').replaceChildren(...(caja.datos.movimientos.length ? caja.datos.movimientos.map((m) => el('tr', {},
        el('td', {}, new Date(String(m.created_at).replace(' ', 'T')).toLocaleString('es-GT')),
        el('td', {}, m.tipo === 'inyeccion' ? 'Inyección' : (m.tipo === 'retiro' ? 'Retiro' : 'Compra')),
        el('td', {}, m.nota || '—'),
        el('td', { class: m.tipo === 'inyeccion' ? '' : 'negativo' }, `${m.tipo === 'inyeccion' ? '+' : '−'}${quetzales(m.monto)}`),
    )) : [el('tr', {}, el('td', { colspan: '4', class: 'vacio' }, 'Este mes todavía no tiene movimientos de caja'))]));
    $('#tabla-capital-ganancia').replaceChildren(...(gananciaFilas.length ? gananciaFilas.map(({ linea, costoLinea, ganancia, estimado }) => el('tr', {},
        el('td', { class: 'uuid' }, linea.uuid),
        el('td', {}, linea.nombre),
        el('td', {}, quetzales(linea.subtotal)),
        el('td', {}, `${quetzales(costoLinea)}${estimado ? ' estimado' : ''}`),
        el('td', {}, quetzales(ganancia)),
    )) : [el('tr', {}, el('td', { colspan: '5', class: 'vacio' }, 'No hay ventas cobradas en este mes'))]));
}

$('#form-capital-mes')?.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!$('#capital-mes')?.value) {
        aviso('Elige un mes para buscar.', true);
        return;
    }
    cargarCapital();
});
$('#form-capital-inyectar')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const datosForm = Object.fromEntries(new FormData(e.target));
    const { ok, datos } = await api('POST', '/api/catalogo/admin/capital/inyectar', {
        monto: Number(datosForm.monto),
        nota: datosForm.nota,
        mes: $('#capital-mes')?.value,
    });
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Efectivo inyectado a la caja del mes');
    e.target.reset();
    cargarCapital();
});
$('#form-capital-retirar')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const datosForm = Object.fromEntries(new FormData(e.target));
    const { ok, datos } = await api('POST', '/api/catalogo/admin/capital/retirar', {
        monto: Number(datosForm.monto),
        nota: datosForm.nota,
        mes: $('#capital-mes')?.value,
    });
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Efectivo retirado de la caja del mes');
    e.target.reset();
    cargarCapital();
});

const MEDIO_PAGO = { stripe: 'Tarjeta', efectivo: 'Efectivo contra entrega', regalo: 'Tarjeta de regalo' };
const RUTA_PEDIDO = [
    ['pendiente', 'Recibido', 'inbox'],
    ['pagado', 'Pagado', 'payments'],
    ['preparando', 'En bodega', 'inventory_2'],
    ['en_camino', 'En ruta', 'local_shipping'],
    ['entregado', 'Entregado', 'home'],
];

function barraRanking(nombre, detalle, pct) {
    return el('div', { class: 'ranking-fila' },
        el('span', {}, nombre),
        el('small', {}, detalle),
        el('div', { class: 'ranking-pista' }, el('i', { style: `width:${Math.max(4, Math.min(100, pct))}%` })));
}

function pintarGraficoDias(dias) {
    const avisoVacio = $('#reporte-dias-vacio');
    const canvas = $('#chart-reporte-dias');
    destruirGrafico('reporteDias');
    if (!canvas) return;
    if (!dias.length || typeof Chart === 'undefined') {
        canvas.hidden = true;
        if (avisoVacio) avisoVacio.hidden = dias.length > 0;
        return;
    }
    canvas.hidden = false;
    if (avisoVacio) avisoVacio.hidden = true;
    graficos.reporteDias = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: dias.map((d) => String(d.dia).slice(8)),
            datasets: [{
                label: 'Venta',
                data: dias.map((d) => Number(d.total)),
                backgroundColor: '#0f6f78',
                borderRadius: 8,
            }],
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true }, x: { ticks: { maxRotation: 0 } } },
        },
    });
}

function cifraReporte(texto, valor, clase = '') {
    return el('article', { class: clase }, el('span', {}, texto), el('strong', {}, valor));
}

function pintarReporteInventario(productos) {
    const activos = productos.filter((p) => p.activo);
    const unidades = activos.reduce((s, p) => s + Number(p.stock || 0), 0);
    const valor = activos.reduce((s, p) => s + Number(p.stock || 0) * Number(p.precio_compra || 0), 0);
    const bajos = activos.filter((p) => Number(p.stock) > 0 && Number(p.stock) <= 10).length;
    const agotados = activos.filter((p) => Number(p.stock) <= 0).length;
    const porVencer = productos.filter((p) => clasificarInventario(p).clave === 'vence').length;
    const orden = { vence: 0, agotado: 1, bajo: 2, ok: 3, inactivo: 4 };
    const lista = [...productos].sort((a, b) => (orden[clasificarInventario(a).clave] ?? 9) - (orden[clasificarInventario(b).clave] ?? 9));
    $('#reporte-inventario-cifras')?.replaceChildren(
        cifraReporte('Productos activos', String(activos.length)),
        cifraReporte('Unidades', String(unidades)),
        cifraReporte('Bajo stock', String(bajos), bajos ? 'alerta' : ''),
        cifraReporte('Agotados', String(agotados), agotados ? 'peligro' : ''),
        cifraReporte('Por vencer', String(porVencer), porVencer ? 'alerta' : ''),
        cifraReporte('Valor a costo', quetzales(valor)),
    );
    $('#tabla-reporte-inventario')?.replaceChildren(...(lista.length ? lista.map((p) => {
        const estado = clasificarInventario(p);
        const dias = diasHastaFecha(p.frescura?.vence);
        const lote = p.frescura
            ? `${p.frescura.codigo} · ${p.frescura.vence}${dias === null ? '' : ` (${dias} d)`}`
            : 'Sin lote';
        return el('tr', {},
            el('td', {}, el('strong', {}, p.nombre)),
            el('td', {}, metroStock(p.stock)),
            el('td', {}, el('span', { class: estado.clase }, estado.texto)),
            el('td', {}, lote),
            el('td', {}, Number(p.precio_compra) > 0 ? quetzales(Number(p.stock || 0) * Number(p.precio_compra)) : 'Sin costo'));
    }) : [el('tr', {}, el('td', { colspan: '5', class: 'vacio' }, 'No hay productos en bodega'))]));
}

function filaReporteStock(p) {
    const estado = clasificarInventario(p);
    const dias = diasHastaFecha(p.frescura?.vence);
    const lote = p.frescura
        ? `${p.frescura.codigo} · ${p.frescura.vence}${dias === null ? '' : ` (${dias} d)`}`
        : 'Sin lote';
    return el('tr', {},
        el('td', {}, el('strong', {}, p.nombre)),
        el('td', {}, metroStock(p.stock)),
        el('td', {}, el('span', { class: estado.clase }, estado.texto)),
        el('td', {}, lote),
        el('td', {}, Number(p.precio_compra) > 0 ? quetzales(Number(p.stock || 0) * Number(p.precio_compra)) : 'Sin costo'));
}

function pintarReporteCritico(productos) {
    const lista = productos
        .filter((p) => p.activo && Number(p.stock) <= 10)
        .sort((a, b) => Number(a.stock) - Number(b.stock));
    const bajos = lista.filter((p) => Number(p.stock) > 0).length;
    const agotados = lista.filter((p) => Number(p.stock) <= 0).length;
    $('#reporte-critico-cifras')?.replaceChildren(
        cifraReporte('Con poco stock', String(bajos), bajos ? 'alerta' : ''),
        cifraReporte('Agotados', String(agotados), agotados ? 'peligro' : ''),
        cifraReporte('Para reponer', String(lista.length), lista.length ? 'alerta' : ''),
    );
    $('#tabla-reporte-critico')?.replaceChildren(...(lista.length
        ? lista.map(filaReporteStock)
        : [el('tr', {}, el('td', { colspan: '5', class: 'vacio' }, 'No hay productos con stock crítico'))]));
}

function pintarReporteVence(lotes) {
    const vivos = lotes.filter((l) => ['recibido', 'en_camara', 'listo'].includes(l.estado));
    const conDias = vivos.map((l) => ({ ...l, dias: diasHastaFecha(l.fecha_vencimiento) }));
    const vencidos = conDias.filter((l) => l.dias !== null && l.dias < 0);
    const semana = conDias.filter((l) => l.dias !== null && l.dias >= 0 && l.dias <= 7);
    const quincena = conDias.filter((l) => l.dias !== null && l.dias > 7 && l.dias <= 15);
    const frios = [...vencidos, ...semana].filter((l) => l.conservacion === 'refrigerado').length;
    const lista = conDias.filter((l) => l.dias !== null && l.dias <= 15).sort((a, b) => a.dias - b.dias);
    $('#reporte-vence-cifras')?.replaceChildren(
        cifraReporte('Vencidos', String(vencidos.length), vencidos.length ? 'peligro' : ''),
        cifraReporte('Vencen en 7 días', String(semana.length), semana.length ? 'alerta' : ''),
        cifraReporte('Vencen en 15 días', String(quincena.length), quincena.length ? 'alerta' : ''),
        cifraReporte('En frío y urgentes', String(frios), frios ? 'alerta' : ''),
    );
    $('#tabla-reporte-vence')?.replaceChildren(...(lista.length ? lista.map((l) => {
        const texto = l.dias < 0 ? 'Vencido' : l.dias <= 7 ? 'Por vencer' : 'En ventana';
        const clase = l.dias < 0 ? 'badge-inactivo' : 'badge-alerta';
        return el('tr', {},
            el('td', {}, el('strong', {}, l.producto)),
            el('td', {}, l.codigo),
            el('td', {}, l.conservacion === 'refrigerado' ? 'Refrigerado' : 'Sin refrigerar'),
            el('td', {}, String(l.cantidad)),
            el('td', {}, l.fecha_vencimiento),
            el('td', {}, String(l.dias)),
            el('td', {}, el('span', { class: clase }, texto)));
    }) : [el('tr', {}, el('td', { colspan: '7', class: 'vacio' }, 'Ningún lote vence en los próximos 15 días'))]));
}

function pintarReportePagos(medios) {
    const sumaMedio = (proveedor, estado) => medios
        .filter((m) => m.proveedor === proveedor && (!estado || m.estado === estado))
        .reduce((s, m) => s + Number(m.monto), 0);
    const tarjeta = sumaMedio('stripe', 'completado');
    const efectivo = sumaMedio('efectivo', 'completado');
    const regalo = sumaMedio('regalo', 'completado');
    const porCobrar = sumaMedio('efectivo', 'iniciado');
    const cobrado = tarjeta + efectivo + regalo;
    const maxCobro = Math.max(1, cobrado, porCobrar);
    $('#reporte-pagos-cifras')?.replaceChildren(
        cifraReporte('Cobrado', quetzales(cobrado)),
        cifraReporte('Tarjeta', quetzales(tarjeta)),
        cifraReporte('Efectivo recibido', quetzales(efectivo)),
        cifraReporte('Por cobrar en la puerta', quetzales(porCobrar), porCobrar ? 'alerta' : ''),
        cifraReporte('Tarjeta de regalo', quetzales(regalo)),
    );
    $('#cobros-reporte')?.replaceChildren(
        barraRanking('Ya cobrado', quetzales(cobrado), (cobrado / maxCobro) * 100),
        barraRanking('Por cobrar en la puerta', quetzales(porCobrar), (porCobrar / maxCobro) * 100),
    );
    $('#tabla-reporte-medios')?.replaceChildren(...(medios.length ? medios.map((m) => el('tr', {},
        el('td', {}, MEDIO_PAGO[m.proveedor] || m.proveedor),
        el('td', {}, m.estado),
        el('td', {}, m.pagos),
        el('td', {}, quetzales(m.monto)),
    )) : [el('tr', {}, el('td', { colspan: '4', class: 'vacio' }, 'Este mes no tiene cobros'))]));
}

function pintarReporteVentas(datos) {
    const estados = datos.estados || [];
    const porEstado = Object.fromEntries(estados.map((e) => [e.estado, e]));
    const vendido = estados.filter((e) => e.estado !== 'cancelado').reduce((s, e) => s + Number(e.total), 0);
    const pedidosMes = estados.filter((e) => e.estado !== 'cancelado').reduce((s, e) => s + Number(e.pedidos), 0);
    const cancelados = Number(porEstado.cancelado?.pedidos || 0);
    $('#reporte-cifras')?.replaceChildren(
        cifraReporte('Venta del mes', quetzales(vendido)),
        cifraReporte('Pedidos', String(pedidosMes)),
        cifraReporte('Ticket promedio', pedidosMes ? quetzales(vendido / pedidosMes) : quetzales(0)),
        cifraReporte('En ruta', String(porEstado.en_camino?.pedidos || 0)),
        cifraReporte('Entregados', String(porEstado.entregado?.pedidos || 0)),
        cifraReporte('Cancelados', String(cancelados), cancelados ? 'peligro' : ''),
    );
    $('#reporte-flujo')?.replaceChildren(...RUTA_PEDIDO.map(([clave, titulo, icono]) => {
        const fila = porEstado[clave] || { pedidos: 0, total: 0 };
        return el('article', { class: 'etapa-logistica' },
            el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, icono),
            el('b', {}, titulo),
            el('strong', {}, String(fila.pedidos)),
            el('small', {}, quetzales(fila.total)));
    }));
    pintarGraficoDias(datos.dias || []);
}

function pintarReporteVendidos(datos) {
    const productos = datos.productos || [];
    const unidades = productos.reduce((s, p) => s + Number(p.unidades || 0), 0);
    const venta = productos.reduce((s, p) => s + Number(p.venta || 0), 0);
    const maxVenta = Math.max(1, ...productos.map((p) => Number(p.venta) || 0));
    $('#reporte-vendidos-cifras')?.replaceChildren(
        cifraReporte('Productos con salida', String(productos.length)),
        cifraReporte('Unidades vendidas', String(unidades)),
        cifraReporte('Venta', quetzales(venta)),
    );
    $('#ranking-productos')?.replaceChildren(...(productos.length ? productos.slice(0, 8).map((p) => barraRanking(
        p.nombre,
        `${p.unidades} u · ${quetzales(p.venta)}`,
        (Number(p.venta) / maxVenta) * 100,
    )) : [el('p', { class: 'vacio' }, 'Este mes no tiene salidas de bodega')]));
    $('#tabla-reporte-vendidos')?.replaceChildren(...(productos.length ? productos.map((p) => el('tr', {},
        el('td', {}, el('strong', {}, p.nombre)),
        el('td', {}, String(p.unidades)),
        el('td', {}, quetzales(p.venta)),
    )) : [el('tr', {}, el('td', { colspan: '3', class: 'vacio' }, 'Este mes no tiene salidas de bodega'))]));
}

function pintarReporteEntregas(datos) {
    const municipios = datos.municipios || [];
    const pedidos = municipios.reduce((s, m) => s + Number(m.pedidos || 0), 0);
    const venta = municipios.reduce((s, m) => s + Number(m.total || 0), 0);
    const maxMunicipio = Math.max(1, ...municipios.map((m) => Number(m.pedidos) || 0));
    $('#reporte-entregas-cifras')?.replaceChildren(
        cifraReporte('Municipios', String(municipios.length)),
        cifraReporte('Pedidos', String(pedidos)),
        cifraReporte('Venta', quetzales(venta)),
    );
    $('#tabla-reporte-municipios')?.replaceChildren(...(municipios.length ? municipios.map((m) => el('tr', {},
        el('td', {}, el('div', { class: 'ranking-fila' },
            el('span', {}, m.municipio),
            el('small', {}, String(m.pedidos)),
            el('div', { class: 'ranking-pista' }, el('i', { style: `width:${(Number(m.pedidos) / maxMunicipio) * 100}%` })))),
        el('td', {}, String(m.pedidos)),
        el('td', {}, quetzales(m.total)),
    )) : [el('tr', {}, el('td', { colspan: '3', class: 'vacio' }, 'Este mes no tiene entregas'))]));
    $('#zonas-reporte')?.replaceChildren(...(datos.zonas || []).map((z) => el('span', {}, `${z.zona}: ${z.pedidos}`)));
}

const REPORTES = [
    { id: 'inventario', titulo: 'Reporte de inventario', nota: 'Mercancía de hoy: stock, estado y valor a costo.', icono: 'warehouse', permiso: 'catalogo.gestionar', mes: false },
    { id: 'critico', titulo: 'Reporte de stock crítico', nota: 'Productos con poco stock o agotados, para reponer.', icono: 'warning', permiso: 'catalogo.gestionar', mes: false },
    { id: 'vence', titulo: 'Reporte de vencimiento', nota: 'Lotes vencidos o que vencen en los próximos 15 días.', icono: 'event_busy', permiso: 'catalogo.gestionar', mes: false },
    { id: 'pagos', titulo: 'Reporte de pagos', nota: 'Cobros del mes: tarjeta, efectivo y tarjeta de regalo.', icono: 'payments', mes: true },
    { id: 'ventas', titulo: 'Reporte de ventas', nota: 'Pedidos del mes y en qué paso de la ruta van.', icono: 'local_shipping', mes: true },
    { id: 'vendidos', titulo: 'Reporte de productos vendidos', nota: 'Lo que salió de bodega en el mes.', icono: 'sell', mes: true },
    { id: 'entregas', titulo: 'Reporte de entregas', nota: 'Pedidos y venta por municipio y zona.', icono: 'pin_drop', mes: true },
];
let reporteAbierto = null;

function mesReporte() {
    const mesInput = $('#reporte-mes');
    if (mesInput && !mesInput.value) {
        const hoy = new Date();
        mesInput.value = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
    }
    return mesInput?.value || '';
}

function pintarIndiceReportes() {
    const lista = REPORTES.filter((r) => !r.permiso || sesion.puede(r.permiso));
    $('#rejilla-reportes')?.replaceChildren(...lista.map((r) => el('button', {
        type: 'button',
        class: 'tarjeta-reporte',
        onclick: () => abrirReporte(r.id),
    },
    el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, r.icono),
    el('strong', {}, r.titulo),
    el('small', {}, r.nota))));
}

function mostrarIndiceReportes() {
    reporteAbierto = null;
    const indice = $('#reporte-indice');
    const hoja = $('#reporte-hoja');
    if (indice) indice.hidden = false;
    if (hoja) hoja.hidden = true;
}

async function abrirReporte(id) {
    reporteAbierto = id;
    const indice = $('#reporte-indice');
    const hoja = $('#reporte-hoja');
    if (indice) indice.hidden = true;
    if (hoja) hoja.hidden = false;
    document.querySelectorAll('#reporte-hoja .bloque-reporte').forEach((bloque) => {
        bloque.hidden = bloque.id !== `reporte-bloque-${id}`;
    });
    await cargarReporteAbierto();
    hoja?.scrollIntoView({ block: 'start' });
}

async function cargarReporteAbierto() {
    const def = REPORTES.find((r) => r.id === reporteAbierto);
    if (!def) return;
    const mes = mesReporte();
    const titulo = $('#reporte-hoja-titulo');
    const nota = $('#reporte-hoja-nota');
    if (titulo) titulo.textContent = def.titulo;
    if (nota) nota.textContent = def.mes ? `${def.nota} Mes ${mes}.` : `${def.nota} Corte de hoy.`;
    if (def.id === 'inventario' || def.id === 'critico' || def.id === 'vence') {
        const [productos, lotes] = await Promise.all([
            api('GET', '/api/catalogo/admin/productos'),
            api('GET', '/api/catalogo/admin/lotes'),
        ]);
        if (!productos.ok || !lotes.ok) {
            aviso(productos.datos?.error || lotes.datos?.error || 'No se pudo cargar el reporte', true);
            return;
        }
        if (def.id === 'inventario') pintarReporteInventario(productos.datos.productos || []);
        if (def.id === 'critico') pintarReporteCritico(productos.datos.productos || []);
        if (def.id === 'vence') pintarReporteVence(lotes.datos.lotes || []);
        return;
    }
    if (def.id === 'pagos') {
        const cobros = await api('GET', `/api/pagos/admin/reporte?mes=${encodeURIComponent(mes)}`);
        if (!cobros.ok) { aviso(cobros.datos?.error || 'No se pudo cargar el reporte de pagos', true); return; }
        pintarReportePagos(cobros.datos.medios || []);
        return;
    }
    const ventas = await api('GET', `/api/pedidos/admin/reporte?mes=${encodeURIComponent(mes)}`);
    if (!ventas.ok) { aviso(ventas.datos?.error || 'No se pudo cargar el reporte', true); return; }
    if (def.id === 'ventas') pintarReporteVentas(ventas.datos);
    if (def.id === 'vendidos') pintarReporteVendidos(ventas.datos);
    if (def.id === 'entregas') pintarReporteEntregas(ventas.datos);
}

async function cargarReportes() {
    mesReporte();
    pintarIndiceReportes();
    if (reporteAbierto) await cargarReporteAbierto();
    else mostrarIndiceReportes();
}

$('#form-reporte-mes')?.addEventListener('submit', (e) => {
    e.preventDefault();
    if (reporteAbierto) cargarReporteAbierto();
});
$('#btn-volver-reportes')?.addEventListener('click', mostrarIndiceReportes);
$('#btn-imprimir-reporte')?.addEventListener('click', () => {
    document.body.classList.add('imprimiendo-reporte');
    const quitar = () => document.body.classList.remove('imprimiendo-reporte');
    window.addEventListener('afterprint', quitar, { once: true });
    window.print();
    setTimeout(quitar, 1000);
});

$('#form-regalo')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/pagos/admin/tarjetas-regalo', Object.fromEntries(new FormData(e.target)));
    if (!ok) { aviso(datos.error, true); return; }
    $('#codigo-regalo').textContent = `Código para el cliente: ${datos.codigo}. Saldo ${quetzales(datos.saldo)}. Se usa una sola vez, en su cuenta.`;
    e.target.reset();
});

// ---------- Usuarios

async function cargarAdminUsuarios() {
    const { ok, datos } = await api('GET', '/api/auth/admin/usuarios');
    if (!ok) { aviso(datos.error, true); return; }
    $('#tabla-usuarios').replaceChildren(...datos.usuarios.map((u) => {
        const acciones = el('td', {},
            el('button', {
                type: 'button', class: 'mini',
                onclick: async () => {
                    const nombre = prompt('Nombre:', u.nombre);
                    if (nombre === null) return;
                    const email = prompt('Email:', u.email);
                    if (email === null) return;
                    const rol = prompt('Rol (cliente, operador, contador, admin):', u.rol);
                    if (rol === null) return;
                    const password = prompt('Nueva contraseña (dejar vacío para no cambiar):', '');
                    if (password === null) return;
                    const cuerpo = { nombre: nombre.trim(), email: email.trim(), rol: rol.trim() };
                    if (password.trim()) cuerpo.password = password;
                    const r = await api('PUT', `/api/auth/admin/usuarios/${u.id}`, cuerpo);
                    if (!r.ok) { aviso(r.datos.error, true); return; }
                    aviso('Usuario actualizado');
                    cargarAdminUsuarios();
                },
            }, 'Editar'), ' ',
            el('button', {
                type: 'button', class: 'mini secundario',
                onclick: async () => {
                    const r = await api('PUT', `/api/auth/admin/usuarios/${u.id}/suspender`, { activo: !u.activo });
                    if (!r.ok) { aviso(r.datos.error, true); return; }
                    aviso(u.activo ? 'Usuario suspendido' : 'Usuario reactivado');
                    cargarAdminUsuarios();
                },
            }, u.activo ? 'Suspender' : 'Activar'), ' ',
            el('button', {
                type: 'button', class: 'mini peligro',
                onclick: async () => {
                    if (!confirm(`¿Eliminar a ${u.email}? Se desactiva y libera el email.`)) return;
                    const r = await api('DELETE', `/api/auth/admin/usuarios/${u.id}`);
                    if (!r.ok) { aviso(r.datos.error, true); return; }
                    aviso('Usuario eliminado');
                    cargarAdminUsuarios();
                },
            }, 'Eliminar'));
        return el('tr', {},
            el('td', {}, u.id),
            el('td', {}, u.nombre),
            el('td', {}, u.email),
            el('td', {}, u.rol),
            el('td', {}, el('span', { class: u.activo ? 'badge-activo' : 'badge-inactivo' }, u.activo ? 'Activo' : 'Suspendido')),
            el('td', {}, u.ultimo_login ? new Date(String(u.ultimo_login).replace(' ', 'T')).toLocaleString('es-GT') : '—'),
            acciones);
    }));
}

$('#btn-nuevo-usuario').addEventListener('click', () => {
    $('#form-usuario').hidden = !$('#form-usuario').hidden;
});

$('#form-usuario').addEventListener('submit', async (e) => {
    e.preventDefault();
    const datosForm = Object.fromEntries(new FormData(e.target));
    const { ok, datos } = await api('POST', '/api/auth/admin/usuarios', datosForm);
    if (!ok) { aviso(datos.error, true); return; }
    aviso(`Usuario ${datos.usuario.email} creado`);
    e.target.reset();
    e.target.hidden = true;
    cargarAdminUsuarios();
});

// ---------- Roles

const ROLES_SISTEMA = [
    { rol: 'admin', nombre: 'Administrador' },
    { rol: 'operador', nombre: 'Operador' },
    { rol: 'contador', nombre: 'Contador' },
    { rol: 'cliente', nombre: 'Cliente' },
];

const ACCIONES_ROL = [
    { id: 'ver', nombre: 'Ver' },
    { id: 'editar', nombre: 'Editar' },
    { id: 'eliminar', nombre: 'Eliminar' },
    { id: 'cancelar', nombre: 'Cancelar' },
];

const MATRIZ_ROL = [
    { apartado: 'Productos', cliente: ['ver'], operador: ['ver', 'editar', 'eliminar'], contador: [], admin: ['ver', 'editar', 'eliminar'] },
    { apartado: 'Canasta', cliente: ['ver', 'editar'], operador: [], contador: [], admin: [] },
    { apartado: 'Mis pedidos', cliente: ['ver', 'cancelar'], operador: [], contador: [], admin: [] },
    { apartado: 'Envases', cliente: ['ver'], operador: [], contador: [], admin: [] },
    { apartado: 'Cuenta', cliente: ['ver', 'editar'], operador: [], contador: [], admin: [] },
    { apartado: 'Inventario', cliente: [], operador: ['ver', 'editar'], contador: [], admin: ['ver', 'editar'] },
    { apartado: 'Categorías y marcas', cliente: [], operador: ['ver', 'editar'], contador: [], admin: ['ver', 'editar'] },
    { apartado: 'Frescura', cliente: [], operador: ['ver', 'editar'], contador: [], admin: ['ver', 'editar'] },
    { apartado: 'Pedidos del local', cliente: [], operador: ['ver', 'editar', 'cancelar'], contador: [], admin: ['ver', 'editar', 'cancelar'] },
    { apartado: 'Pagos', cliente: [], operador: [], contador: ['ver'], admin: ['ver'] },
    { apartado: 'Capital', cliente: [], operador: [], contador: ['ver', 'editar'], admin: ['ver', 'editar'] },
    { apartado: 'Clientes', cliente: [], operador: [], contador: ['ver'], admin: ['ver'] },
    { apartado: 'Reportería', cliente: [], operador: ['ver'], contador: ['ver'], admin: ['ver'] },
    { apartado: 'Usuarios', cliente: [], operador: [], contador: [], admin: ['ver', 'editar', 'eliminar'] },
    { apartado: 'Roles', cliente: [], operador: ['ver'], contador: ['ver'], admin: ['ver'] },
    { apartado: 'Bitácora', cliente: [], operador: [], contador: [], admin: ['ver'] },
];

const rolesEstado = { filtro: 'admin' };
const CLAVE_MATRIZ_ROL = 'mv-matriz-rol';

const NAV_APARTADO = {
    catalogo: 'Productos',
    producto: 'Productos',
    carrito: 'Canasta',
    pedidos: 'Mis pedidos',
    envases: 'Envases',
    'admin-productos': 'Productos',
    'admin-inventario': 'Inventario',
    'admin-clasificacion': 'Categorías y marcas',
    'admin-fefo': 'Frescura',
    'admin-pedidos': 'Pedidos del local',
    'admin-pagos': 'Pagos',
    'admin-capital': 'Capital',
    'admin-clientes': 'Clientes',
    'admin-reportes': 'Reportería',
    'admin-usuarios': 'Usuarios',
    'admin-roles': 'Roles',
    'admin-bitacora': 'Bitácora',
};

function leerMatrizRol() {
    try {
        const data = JSON.parse(localStorage.getItem(CLAVE_MATRIZ_ROL) || 'null');
        return data && typeof data === 'object' ? data : null;
    } catch {
        return null;
    }
}

function accionesDe(rol, apartado) {
    const custom = leerMatrizRol()?.[rol]?.[apartado];
    if (Array.isArray(custom)) return custom;
    const fila = MATRIZ_ROL.find((f) => f.apartado === apartado);
    return fila ? [...(fila[rol] || [])] : [];
}

function rolDeLaSesion() {
    return sesion.usuario?.rol || 'cliente';
}

function puedeVerNav(nav) {
    const apartado = NAV_APARTADO[nav];
    if (!apartado) return true;
    return accionesDe(rolDeLaSesion(), apartado).includes('ver');
}

function guardarAccionRol(rol, apartado, accion, activo) {
    if (rol === 'admin' && apartado === 'Roles' && accion === 'ver' && !activo) {
        aviso('Roles se queda visible para el administrador. Si se apaga, no habría cómo volver a marcarlo.', true);
        pintarPermisosRol();
        return;
    }
    const todo = {};
    ROLES_SISTEMA.forEach((r) => {
        todo[r.rol] = {};
        MATRIZ_ROL.forEach((fila) => {
            todo[r.rol][fila.apartado] = [...accionesDe(r.rol, fila.apartado)];
        });
    });
    const marcas = new Set(todo[rol][apartado]);
    if (activo) {
        marcas.add(accion);
        if (accion !== 'ver') marcas.add('ver');
    } else if (accion === 'ver') {
        marcas.clear();
    } else {
        marcas.delete(accion);
    }
    todo[rol][apartado] = [...marcas];
    localStorage.setItem(CLAVE_MATRIZ_ROL, JSON.stringify(todo));
    pintarPermisosRol();
    refrescarSesion();
    const nombreRol = ROLES_SISTEMA.find((r) => r.rol === rol)?.nombre || rol;
    const verbo = ACCIONES_ROL.find((a) => a.id === accion)?.nombre || accion;
    aviso(activo
        ? `${nombreRol} ahora puede ${verbo.toLowerCase()} en ${apartado}.`
        : `${nombreRol} ya no puede ${verbo.toLowerCase()} en ${apartado}. El menú se actualiza.`);
}

function fraseDelRol(rol) {
    const partes = MATRIZ_ROL.map((fila) => {
        const acciones = accionesDe(rol, fila.apartado);
        if (!acciones.length) return '';
        const nombres = ACCIONES_ROL.filter((a) => acciones.includes(a.id)).map((a) => a.nombre.toLowerCase());
        const texto = nombres.length === 1 ? nombres[0] : `${nombres.slice(0, -1).join(', ')} y ${nombres.at(-1)}`;
        return `en ${fila.apartado} puede ${texto}`;
    }).filter(Boolean);
    const nombre = ROLES_SISTEMA.find((r) => r.rol === rol)?.nombre || rol;
    return partes.length ? `${nombre}: ${partes.join('; ')}.` : `${nombre}: no abre apartados.`;
}

function pintarSelectorRoles() {
    $('#selector-roles')?.replaceChildren(...ROLES_SISTEMA.map((r) => el('button', {
        type: 'button',
        class: rolesEstado.filtro === r.rol ? 'activo' : '',
        onclick: () => { rolesEstado.filtro = r.rol; pintarSelectorRoles(); pintarPermisosRol(); },
    }, r.nombre)));
}

function pintarPermisosRol() {
    const rol = rolesEstado.filtro;
    const resumen = $('#resumen-rol');
    if (resumen) resumen.textContent = fraseDelRol(rol);
    const cuerpo = $('#tabla-permisos-rol');
    if (!cuerpo) return;
    cuerpo.replaceChildren(...MATRIZ_ROL.map((fila) => {
        const acciones = accionesDe(rol, fila.apartado);
        return el('tr', {},
            el('td', {}, fila.apartado),
            ...ACCIONES_ROL.map((accion) => {
                const bloqueado = rol === 'admin' && fila.apartado === 'Roles' && accion.id === 'ver';
                return el('td', {}, el('input', {
                    type: 'checkbox',
                    checked: acciones.includes(accion.id),
                    disabled: bloqueado,
                    'aria-label': `${fila.apartado}: ${accion.nombre}`,
                    onchange: (e) => guardarAccionRol(rol, fila.apartado, accion.id, e.target.checked),
                }));
            }));
    }));
}

function cargarAdminRoles() {
    pintarSelectorRoles();
    pintarPermisosRol();
}

// ---------- Clientes

async function cargarAdminClientes() {
    const { ok, datos } = await api('GET', '/api/pedidos/admin/clientes');
    if (!ok) { aviso(datos.error, true); return; }
    $('#tabla-clientes').replaceChildren(...(datos.clientes.length ? datos.clientes.map((c) => el('tr', {},
        el('td', {}, c.nombre),
        el('td', {}, c.email),
        el('td', {}, c.pedidos),
        el('td', {}, quetzales(c.total_comprado)),
        el('td', {}, c.ultima_compra ? new Date(c.ultima_compra.replace(' ', 'T')).toLocaleString('es-GT') : '—'),
    )) : [el('tr', {}, el('td', { colspan: '5', class: 'vacio' }, 'Aún no hay clientes con pedidos'))]));
}

$('#btn-exportar').addEventListener('click', async () => {
    const respuesta = await fetch('/api/pedidos/admin/clientes/exportar', { headers: { Authorization: `Bearer ${sesion.token}` } });
    if (!respuesta.ok) { aviso('No se pudo exportar', true); return; }
    const url = URL.createObjectURL(await respuesta.blob());
    const enlace = el('a', { href: url, download: `clientes_${new Date().toISOString().slice(0, 10)}.csv` });
    document.body.append(enlace);
    enlace.click();
    enlace.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    aviso('Exportación descargada (queda en la bitácora)');
});

// ---------- Bitácora

function describirEvento(ev) {
    const n = ev.dato_nuevo || {};
    const a = ev.dato_anterior || {};
    switch (ev.accion) {
        case 'PRECIO_MODIFICADO':
            if (n.motivo === 'producto_nuevo') return `Producto nuevo: ${n.nombre} a ${quetzales(n.precio)}`;
            return `${n.nombre || 'Producto'}: ${quetzales(a.precio)} → ${quetzales(n.precio)}`;
        case 'PEDIDO_CREADO':
            if (ev.resultado === 'FRAUDE') return `Intento de manipular precios (${(n.campos || []).join(', ')})`;
            return `Pedido ${n.uuid || ''} · total ${quetzales(n.total || 0)}`;
        case 'PEDIDO_ESTADO_CAMBIADO':
            return `${(a.estado || '').replace('_', ' ')} → ${(n.estado || '').replace('_', ' ')}`;
        case 'PRODUCTO_AGOTADO':
            return `${n.nombre || a.nombre || 'Producto'} se quedó sin stock`;
        case 'EXPORTACION_CLIENTES':
            return `CSV con ${n.registros || 0} registros`;
        case 'ACCESO_ADMIN_PANEL':
            return ev.resultado === 'EXITO' ? 'Entró al panel' : 'Intento denegado';
        case 'LOGIN_CLIENTE_FALLIDO':
            if (n.motivo === 'usuario_inexistente') return 'Correo no registrado';
            if (n.motivo === 'cuenta_bloqueada') return 'Cuenta bloqueada por intentos fallidos';
            if (n.motivo === 'cuenta_inactiva') return 'Cuenta inactiva';
            return 'Contraseña incorrecta';
        case 'TOKEN_REVOCADO':
            return 'Cierre de sesión';
        case 'LOTE_MERMA':
            return `${n.codigo || 'Lote'} · ${n.estado || n.motivo || 'actualizado'}`;
        case 'ENVASE_ACREDITADO':
            return `Envases acreditados ${quetzales(n.monto || 0)}`;
        case 'RUTA_ACTUALIZADA':
            return n.repartidor ? `Ruta de ${n.repartidor}` : 'Ruta actualizada';
        case 'CALIFICACION_PEDIDO':
            return `Calificación ${n.estrellas || ''}/5`;
        default:
            return n.motivo || '';
    }
}

async function cargarBitacora() {
    const accion = $('#filtro-accion').value;
    const resultado = $('#filtro-resultado').value;
    const params = new URLSearchParams({ limite: '100' });
    if (accion) params.set('accion', accion);
    if (resultado) params.set('resultado', resultado);

    const [resumen, eventos] = await Promise.all([
        api('GET', '/api/auditoria/eventos/resumen'),
        api('GET', `/api/auditoria/eventos?${params}`),
    ]);
    if (!resumen.ok || !eventos.ok) { aviso('No se pudo cargar la bitácora', true); return; }

    $('#resumen-bitacora').replaceChildren(
        el('span', { class: 'chip-resumen' }, el('strong', {}, resumen.datos.total_registros), ' registros'),
        el('span', { class: 'chip-resumen' }, el('strong', {}, `${resumen.datos.tipos_de_evento}/${resumen.datos.tipos_requeridos}`), ' tipos'),
        el('span', { class: 'chip-resumen' }, el('strong', {}, (eventos.datos.eventos || []).length), ' mostrados'));

    $('#tabla-bitacora').replaceChildren(...(eventos.datos.eventos.length ? eventos.datos.eventos.map((ev) => el('tr', {},
        el('td', {}, new Date(String(ev.timestamp).replace(' ', 'T')).toLocaleString('es-GT')),
        el('td', {}, ev.accion.replaceAll('_', ' ')),
        el('td', {}, ev.usuario_email || '—'),
        el('td', {}, el('span', { class: ev.resultado }, ev.resultado)),
        el('td', { class: 'cambio' }, describirEvento(ev))))
    : [el('tr', {}, el('td', { colspan: '5', class: 'vacio' }, 'No hay eventos con ese filtro'))]));
}

$('#btn-recargar-bitacora').addEventListener('click', cargarBitacora);

// ============================================================ Inicio

$('#form-pase')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { ok, datos } = await api('POST', '/api/pedidos/envases/acreditar', { pase: new FormData(e.target).get('pase') });
    if (!ok) { aviso(datos.error, true); return; }
    aviso(`Se acreditaron ${quetzales(datos.acreditado)}. Saldo ${quetzales(datos.saldo)}.`);
    e.target.reset();
    cargarEnvases();
});

function cerrarModalLote() {
    const modal = $('#modal-lote');
    if (modal?.open) modal.close();
}
$('#btn-recibir-lote')?.addEventListener('click', () => {
    const ingreso = $('#form-lote')?.fecha_ingreso;
    if (ingreso && !ingreso.value) ingreso.value = new Date().toISOString().slice(0, 10);
    llenarPreciosLote();
    $('#modal-lote')?.showModal();
});
$('#cerrar-modal-lote')?.addEventListener('click', cerrarModalLote);
$('#cancelar-modal-lote')?.addEventListener('click', cerrarModalLote);
$('#modal-lote')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) cerrarModalLote(); });

$('#form-lote')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const datosForm = Object.fromEntries(new FormData(e.target));
    if ($('#campo-temperatura-lote')?.hidden) delete datosForm.temperatura;
    datosForm.precio_compra = Number(datosForm.precio_compra);
    datosForm.inyeccion = Number(datosForm.inyeccion || 0);
    datosForm.margen_utilidad = Number(datosForm.margen_utilidad || 0);
    delete datosForm.precio;
    const { ok, datos } = await api('POST', '/api/catalogo/admin/lotes', datosForm);
    if (!ok) { aviso(datos.error, true); return; }
    aviso('Lote recibido. La compra salió del efectivo del mes y el stock aumentó.');
    e.target.reset();
    cerrarModalLote();
    cargarFefo();
});

$('#form-busqueda').addEventListener('submit', (e) => {
    e.preventDefault();
    estado.busqueda = $('#campo-busqueda').value.trim();
    if (location.hash !== '#catalogo') location.hash = 'catalogo';
    else cargarProductos();
});

function cerrarMenuMovil() {
    $('#sidebar').classList.remove('abierto');
    $('#sidebar-overlay').hidden = true;
}

$('#btn-menu').addEventListener('click', () => {
    $('#sidebar').classList.add('abierto');
    $('#sidebar-overlay').hidden = false;
});
$('#sidebar-overlay').addEventListener('click', cerrarMenuMovil);
document.querySelectorAll('.sidebar a').forEach((a) => a.addEventListener('click', cerrarMenuMovil));

window.addEventListener('hashchange', mostrarVista);
refrescarSesion();
mostrarVista();
if (sesion.token) api('GET', '/api/pedidos/carrito').then((r) => { if (r.ok) actualizarContador(r.datos); });
