/**
 * Lógica Cliente Vanilla JS (ES6+) — Módulo de Farmacia, Lotes FEFO y Kárdex (RF-FAR-01 a RF-FAR-04)
 * Cumplimiento: RNF-SEG-03 (Manipulación segura del DOM usando textContent y envío de X-CSRF-Token)
 */
'use strict';

function obtenerCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function mostrarAlertaFarmacia(mensaje, tipo = 'success') {
    const box = document.getElementById('alertaFarmacia');
    const txt = document.getElementById('alertaFarmaciaTexto');
    if (!box || !txt) return;

    box.className = `alert alert-${tipo} d-flex align-items-center justify-content-between mb-3`;
    txt.textContent = mensaje;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function dispensarRecetaFefo(idReceta, folioReceta) {
    if (!window.confirm(
        `¿Confirma ejecutar la DISPENSACIÓN ATÓMICA FEFO de la receta ${folioReceta} (RF-FAR-02 / RF-FAR-03)?\n\nLos Triggers de MySQL descontarán automáticamente los lotes con caducidad más próxima e inscribirán el movimiento inmutable en el Kárdex.`
    )) {
        return;
    }

    try {
        const resp = await fetch('/api/farmacia.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': obtenerCsrfToken(),
            },
            body: JSON.stringify({
                action: 'dispensar_receta',
                id_receta: idReceta,
                notas_despacho: `Despacho FEFO confirmado en ventanilla para ${folioReceta}`,
            }),
        });
        const data = await resp.json();
        mostrarAlertaFarmacia(data.mensaje || 'Dispensación procesada.', data.ok ? 'success' : 'danger');
        if (data.ok) {
            setTimeout(() => window.location.reload(), 1100);
        }
    } catch (err) {
        mostrarAlertaFarmacia('Error de red al procesar la dispensación.', 'danger');
    }
}

/** Crea un elemento con clase y texto opcionales (siempre vía textContent, RNF-SEG-03). */
function crearEl(tag, className = '', texto = null) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (texto !== null && texto !== undefined) el.textContent = String(texto);
    return el;
}

function crearIcono(clases) {
    const i = crearEl('i', `bi ${clases}`);
    i.setAttribute('aria-hidden', 'true');
    return i;
}

/** Renderiza la tabla de renglones de una receta con la propuesta de lotes FEFO. */
function renderTablaRenglones(renglones) {
    const wrap = crearEl('div', 'table-responsive');
    const table = crearEl('table', 'table table-sm table-bordered align-middle small mb-3');
    const thead = crearEl('thead', 'table-light');
    const trh = document.createElement('tr');
    ['Medicamento Prescrito', 'Posología', 'Prescrito', 'Surtido', 'Pendiente', 'Lote(s) Sugeridos FEFO']
        .forEach((t) => trh.appendChild(crearEl('th', '', t)));
    thead.appendChild(trh);

    const tbody = document.createElement('tbody');
    (renglones || []).forEach((r) => {
        const tr = document.createElement('tr');

        const tdMed = document.createElement('td');
        tdMed.appendChild(crearEl('div', 'fw-bold', `${r.nombre_generico} (${r.concentracion})`));
        tdMed.appendChild(crearEl('code', 'text-muted', r.codigo_sku));
        tdMed.appendChild(document.createTextNode(` — ${r.forma_farmaceutica}`));

        const tdPos = crearEl('td', '',
            `${r.dosis} | ${r.via_administracion} | ${r.frecuencia_horaria} por ${r.duracion_tratamiento}`);
        const tdPres = crearEl('td', 'fw-bold text-center', r.cantidad_prescrita);
        const tdSurt = crearEl('td', 'text-center text-success', r.cantidad_surtida);
        const tdPend = crearEl('td', 'fw-bold text-center text-danger', r.cantidad_pendiente);

        const tdLotes = document.createElement('td');
        const asignacion = Array.isArray(r.asignacion_fefo) ? r.asignacion_fefo : [];
        if (asignacion.length === 0) {
            tdLotes.appendChild(crearEl('span', 'badge bg-danger', 'Sin lotes vigentes disponibles'));
        } else {
            asignacion.forEach((a) => {
                const fila = crearEl('div', 'mb-1');
                fila.appendChild(crearEl('span', 'badge bg-info text-dark', `Lote: ${a.numero_lote}`));
                fila.appendChild(document.createTextNode(' Vence: '));
                fila.appendChild(crearEl('strong', '', a.fecha_caducidad));
                fila.appendChild(document.createTextNode(` (${a.dias_vigencia}d) `));
                fila.appendChild(crearEl('span', 'badge bg-success',
                    `Tomar ${a.cantidad_sugerida} de ${a.disponible_en_lote} u.`));
                tdLotes.appendChild(fila);
            });
        }

        tr.append(tdMed, tdPos, tdPres, tdSurt, tdPend, tdLotes);
        tbody.appendChild(tr);
    });

    table.append(thead, tbody);
    wrap.appendChild(table);
    return wrap;
}

async function buscarRecetasFefo() {
    const q = document.getElementById('inputBuscarReceta')?.value || '';
    const cont = document.getElementById('contenedorRecetasPendientes');
    if (!cont) return;

    const puedeDispensar = cont.getAttribute('data-puede-dispensar') === '1';
    cont.setAttribute('aria-busy', 'true');

    try {
        const resp = await fetch(`/api/farmacia.php?action=recetas_pendientes&q=${encodeURIComponent(q)}`);
        const data = await resp.json();
        if (!data.ok) {
            mostrarAlertaFarmacia(data.mensaje || 'No fue posible consultar las recetas pendientes.', 'danger');
            return;
        }

        cont.replaceChildren();

        if (!Array.isArray(data.recetas) || data.recetas.length === 0) {
            const empty = crearEl('div', 'card shadow-sm border-0 mc-empty-state');
            empty.appendChild(crearIcono('bi-search'));
            empty.appendChild(document.createTextNode('No se encontraron recetas pendientes de surtir para el criterio buscado.'));
            cont.appendChild(empty);
            return;
        }

        data.recetas.forEach((rec) => {
            const card = crearEl('div', 'card shadow-sm border-0 mb-3');

            const header = crearEl('div', 'card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2');
            const left = document.createElement('div');
            left.append(
                crearEl('code', 'fs-5 fw-bold text-primary me-2', rec.folio_receta),
                crearEl('span', 'badge bg-warning text-dark', rec.estado_receta),
                crearEl('span', 'ms-2 small text-muted', `Emitida: ${rec.fecha_emision}`)
            );

            const right = crearEl('div', 'small');
            right.append(
                crearEl('strong', '', 'Paciente: '),
                document.createTextNode(`${rec.paciente_nombre} `),
                crearEl('code', '', rec.mrn),
                document.createTextNode(' | '),
                crearEl('strong', '', 'Prescriptor: '),
                document.createTextNode(`${rec.medico_nombre} (Cédula: ${rec.cedula_profesional})`)
            );
            header.append(left, right);

            const body = crearEl('div', 'card-body');
            body.appendChild(renderTablaRenglones(rec.renglones));

            if (puedeDispensar) {
                const acciones = crearEl('div', 'd-flex justify-content-end');
                const btn = crearEl('button', 'btn btn-success btn-sm fw-semibold js-dispensar');
                btn.type = 'button';
                btn.dataset.idReceta = String(rec.id_receta);
                btn.dataset.folio = String(rec.folio_receta);
                btn.append(crearIcono('bi-check2-all me-1'),
                    document.createTextNode('Confirmar Despacho Atómico FEFO y Registrar en Kárdex (RF-FAR-03)'));
                acciones.appendChild(btn);
                body.appendChild(acciones);
            } else {
                const aviso = crearEl('div', 'text-end small text-muted');
                aviso.append(crearIcono('bi-shield-lock me-1'),
                    document.createTextNode('RN-CLI-06: El rol Médico tiene restringida la ejecución de despachos en ventanilla de farmacia.'));
                body.appendChild(aviso);
            }

            card.append(header, body);
            cont.appendChild(card);
        });
    } catch (err) {
        mostrarAlertaFarmacia('Error al consultar recetas pendientes.', 'danger');
    } finally {
        cont.removeAttribute('aria-busy');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Delegación: un solo listener cubre los botones renderizados por PHP y por AJAX
    const contRecetas = document.getElementById('contenedorRecetasPendientes');
    if (contRecetas) {
        contRecetas.addEventListener('click', async (e) => {
            const btn = e.target.closest('.js-dispensar');
            if (!btn || btn.disabled) return;

            const idReceta = parseInt(btn.dataset.idReceta || '0', 10);
            const folio = btn.dataset.folio || '';
            if (!idReceta) return;

            btn.disabled = true;
            try {
                await dispensarRecetaFefo(idReceta, folio);
            } finally {
                btn.disabled = false;
            }
        });
    }
    const formMed = document.getElementById('formNuevoMedicamento');
    if (formMed) {
        formMed.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formMed).entries());
            payload.action = 'crear_medicamento';

            try {
                const resp = await fetch('/api/farmacia.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken(),
                    },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                mostrarAlertaFarmacia(data.mensaje || 'Resultado recibido.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    setTimeout(() => window.location.reload(), 900);
                }
            } catch (err) {
                mostrarAlertaFarmacia('Error al registrar medicamento.', 'danger');
            }
        });
    }

    const formLote = document.getElementById('formRecepcionarLote');
    if (formLote) {
        formLote.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formLote).entries());
            payload.action = 'recepcionar_lote';

            try {
                const resp = await fetch('/api/farmacia.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken(),
                    },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                mostrarAlertaFarmacia(data.mensaje || 'Resultado recibido.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    setTimeout(() => window.location.reload(), 900);
                }
            } catch (err) {
                mostrarAlertaFarmacia('Error al recepcionar lote de inventario.', 'danger');
            }
        });
    }
});
