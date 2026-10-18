/**
 * Lógica Cliente Vanilla JS (ES6+) — Módulo de Gestión de Pacientes (RF-PAC-01 a RF-PAC-04)
 * Cumplimiento:
 * - RF-PAC-03: Búsqueda asíncrona en tiempo real con debounce de 300 ms (< 200 ms)
 * - RNF-SEG-03: Manipulación segura del DOM usando exclusivamente textContent y X-CSRF-Token
 */
'use strict';

let debounceTimer = null;

function obtenerCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function mostrarAlertaPacientes(mensaje, tipo = 'success') {
    const box = document.getElementById('alertaPacientes');
    const txt = document.getElementById('alertaPacientesTexto');
    if (!box || !txt) return;

    box.className = `alert alert-${tipo} d-flex align-items-center justify-content-between mb-3`;
    txt.textContent = mensaje;
}

/**
 * RF-PAC-03: Ejecuta la consulta asíncrona paginada y reconstruye la tabla usando textContent (XSS-safe).
 */
async function ejecutarBusquedaPacientes(termino = '') {
    const tbody = document.getElementById('tbodyPacientes');
    const tabla = document.getElementById('tablaPacientes');
    const lblTotal = document.getElementById('lblTotalPacientes');
    const lblLatencia = document.getElementById('lblLatenciaMs');
    if (!tbody || !tabla) return;

    const puedeEditar = tabla.getAttribute('data-puede-editar') === '1';

    try {
        const url = `/api/pacientes.php?action=buscar&q=${encodeURIComponent(termino)}`;
        const resp = await fetch(url);
        const data = await resp.json();

        if (!data.ok) return;

        if (lblTotal) lblTotal.textContent = String(data.total || 0);
        if (lblLatencia) lblLatencia.textContent = `${data.tiempo_ms} ms`;

        tbody.replaceChildren();

        if (!Array.isArray(data.pacientes) || data.pacientes.length === 0) {
            const trVacio = document.createElement('tr');
            const tdVacio = document.createElement('td');
            tdVacio.colSpan = 8;
            tdVacio.className = 'text-center text-muted py-4';
            tdVacio.textContent = 'No se encontraron expedientes que coincidan con el criterio buscado.';
            trVacio.appendChild(tdVacio);
            tbody.appendChild(trVacio);
            return;
        }

        data.pacientes.forEach((p) => {
            const tr = document.createElement('tr');

            // 1. MRN
            const tdMrn = document.createElement('td');
            const codeMrn = document.createElement('code');
            codeMrn.className = 'fw-bold fs-6 text-primary';
            codeMrn.textContent = p.mrn;
            tdMrn.appendChild(codeMrn);

            // 2. Nombre e Identidad
            const tdNombre = document.createElement('td');
            const divNom = document.createElement('div');
            divNom.className = 'fw-bold';
            divNom.textContent = p.nombre_completo;
            const divDoc = document.createElement('div');
            divDoc.className = 'text-muted font-mono';
            divDoc.textContent = `ID: ${p.documento_identidad}`;
            tdNombre.append(divNom, divDoc);

            // 3. Edad y Sexo
            const tdEdad = document.createElement('td');
            const divEdad = document.createElement('div');
            divEdad.textContent = `${p.edad} años (${p.fecha_nacimiento})`;
            const spanSexo = document.createElement('span');
            spanSexo.className = 'text-muted';
            spanSexo.textContent = p.sexo_biologico;
            tdEdad.append(divEdad, spanSexo);

            // 4. Grupo Sanguíneo
            const tdSangre = document.createElement('td');
            const badgeSangre = document.createElement('span');
            badgeSangre.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger-subtle fs-6';
            badgeSangre.textContent = p.grupo_sanguineo;
            tdSangre.appendChild(badgeSangre);

            // 5. Alertas Clínicas de Alergias (RF-PAC-02: badge bg-danger)
            const tdAlergias = document.createElement('td');
            if (!Array.isArray(p.alergias) || p.alergias.length === 0) {
                const badgeSin = document.createElement('span');
                badgeSin.className = 'badge bg-success-subtle text-success border';
                badgeSin.textContent = 'Sin alergias registradas';
                tdAlergias.appendChild(badgeSin);
            } else {
                const wrapAlg = document.createElement('div');
                wrapAlg.className = 'd-flex flex-wrap gap-1';
                p.alergias.forEach((alg) => {
                    const badgeAlg = document.createElement('span');
                    badgeAlg.className = 'badge bg-danger';
                    badgeAlg.title = alg.reaccion_descrita || '';
                    badgeAlg.textContent = `${alg.principio_activo_o_familia} (${alg.severidad})`;
                    wrapAlg.appendChild(badgeAlg);
                });
                tdAlergias.appendChild(wrapAlg);
            }

            // 6. Contacto y Emergencia
            const tdContacto = document.createElement('td');
            const divTel = document.createElement('div');
            divTel.textContent = p.telefono || 'N/D';
            tdContacto.appendChild(divTel);
            if (p.nombre_contacto) {
                const divEm = document.createElement('div');
                divEm.className = 'text-muted';
                divEm.textContent = `Emergencia: ${p.nombre_contacto} (${p.parentesco}: ${p.telefono_emergencia})`;
                tdContacto.appendChild(divEm);
            }

            // 7. Consultas Cerradas
            const tdCerradas = document.createElement('td');
            if (Number(p.consultas_cerradas) > 0) {
                const badgeCer = document.createElement('span');
                badgeCer.className = 'badge bg-secondary';
                badgeCer.textContent = `${p.consultas_cerradas} cerrada(s)`;
                tdCerradas.appendChild(badgeCer);
            } else {
                const spanCer = document.createElement('span');
                spanCer.className = 'text-muted';
                spanCer.textContent = '0 cerradas';
                tdCerradas.appendChild(spanCer);
            }

            // 8. Acciones
            const tdAcciones = document.createElement('td');
            tdAcciones.className = 'text-end';
            const grp = document.createElement('div');
            grp.className = 'btn-group btn-group-sm';

            const btnAlg = document.createElement('button');
            btnAlg.type = 'button';
            btnAlg.className = 'btn btn-outline-danger';
            btnAlg.textContent = 'Alergias';
            btnAlg.addEventListener('click', () => abrirModalAlergia(p.id_paciente, p.mrn, p.nombre_completo));
            grp.appendChild(btnAlg);

            if (puedeEditar) {
                const btnEdit = document.createElement('button');
                btnEdit.type = 'button';
                btnEdit.className = 'btn btn-outline-primary';
                btnEdit.textContent = 'Editar';
                btnEdit.addEventListener('click', () => abrirModalEditarPaciente(p.id_paciente));
                grp.appendChild(btnEdit);
            }

            tdAcciones.appendChild(grp);
            tr.append(tdMrn, tdNombre, tdEdad, tdSangre, tdAlergias, tdContacto, tdCerradas, tdAcciones);
            tbody.appendChild(tr);
        });
    } catch (err) {
        mostrarAlertaPacientes('Error al ejecutar la búsqueda de pacientes.', 'danger');
    }
}

function abrirModalAlergia(idPaciente, mrn, nombreCompleto) {
    document.getElementById('alg_id_paciente').value = idPaciente;
    document.getElementById('alg_paciente_mrn').textContent = mrn;
    document.getElementById('alg_paciente_nombre').textContent = nombreCompleto;

    const modal = new bootstrap.Modal(document.getElementById('modalAlergiaPaciente'));
    modal.show();
}

async function abrirModalEditarPaciente(idPaciente) {
    const tabla = document.getElementById('tablaPacientes');
    const idRol = parseInt(tabla ? tabla.getAttribute('data-rol') : '0', 10);

    try {
        const resp = await fetch(`/api/pacientes.php?action=detalle&id_paciente=${idPaciente}`);
        const data = await resp.json();
        if (!data.ok || !data.paciente) {
            mostrarAlertaPacientes('No se pudo cargar el expediente del paciente.', 'danger');
            return;
        }

        const p = data.paciente;
        document.getElementById('edit_id_paciente').value = p.id_paciente;
        document.getElementById('edit_mrn_display').textContent = p.mrn;
        document.getElementById('edit_nombres').value = p.nombres || '';
        document.getElementById('edit_apellidos').value = p.apellidos || '';
        document.getElementById('edit_documento').value = p.documento_identidad || '';
        document.getElementById('edit_fecha_nacimiento').value = p.fecha_nacimiento || '';
        document.getElementById('edit_sexo').value = p.sexo_biologico || 'MASCULINO';
        document.getElementById('edit_sangre').value = p.grupo_sanguineo || 'O+';
        document.getElementById('edit_telefono').value = p.telefono || '';
        document.getElementById('edit_email').value = p.email || '';
        document.getElementById('edit_direccion').value = p.direccion || '';
        document.getElementById('edit_nombre_contacto').value = p.nombre_contacto || '';
        document.getElementById('edit_parentesco').value = p.parentesco || '';
        document.getElementById('edit_tel_emergencia').value = p.telefono_emergencia || '';
        document.getElementById('edit_motivo_auditoria').value = '';

        const tieneCerradas = Number(p.consultas_cerradas) > 0;
        const aviso = document.getElementById('avisoInmutabilidad');
        const camposCriticos = document.querySelectorAll('#formEditarPaciente .campo-critico');

        if (tieneCerradas) {
            aviso.classList.remove('d-none');
            // RF-PAC-04: Si es Recepcionista (idRol !== 1), bloquear los campos críticos en UI además del backend
            camposCriticos.forEach((el) => {
                if (idRol !== 1) {
                    el.setAttribute('readonly', 'readonly');
                    if (el.tagName === 'SELECT') {
                        el.style.pointerEvents = 'none';
                        el.classList.add('bg-light');
                    }
                } else {
                    el.removeAttribute('readonly');
                    el.style.pointerEvents = '';
                    el.classList.remove('bg-light');
                }
            });
        } else {
            aviso.classList.add('d-none');
            camposCriticos.forEach((el) => {
                el.removeAttribute('readonly');
                el.style.pointerEvents = '';
                el.classList.remove('bg-light');
            });
        }

        const modal = new bootstrap.Modal(document.getElementById('modalEditarPaciente'));
        modal.show();
    } catch (err) {
        mostrarAlertaPacientes('Error al consultar el detalle del paciente.', 'danger');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const inputBusqueda = document.getElementById('inputBuscadorPacientes');
    if (inputBusqueda) {
        // RF-PAC-03: Técnica de debounce de 300 ms
        inputBusqueda.addEventListener('input', (e) => {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            const valor = e.target.value;
            debounceTimer = setTimeout(() => {
                ejecutarBusquedaPacientes(valor);
            }, 300);
        });
    }

    const formNuevo = document.getElementById('formNuevoPaciente');
    if (formNuevo) {
        formNuevo.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formNuevo).entries());
            payload.action = 'registrar';

            try {
                const resp = await fetch('/api/pacientes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();
                mostrarAlertaPacientes(data.mensaje || 'Operación completada.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('modalNuevoPaciente')).hide();
                    formNuevo.reset();
                    ejecutarBusquedaPacientes('');
                }
            } catch (err) {
                mostrarAlertaPacientes('Error de red al empadronar paciente.', 'danger');
            }
        });
    }

    const formEditar = document.getElementById('formEditarPaciente');
    if (formEditar) {
        formEditar.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formEditar).entries());
            payload.action = 'actualizar';

            try {
                const resp = await fetch('/api/pacientes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();
                mostrarAlertaPacientes(data.mensaje || 'Actualización procesada.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('modalEditarPaciente')).hide();
                    ejecutarBusquedaPacientes(inputBusqueda ? inputBusqueda.value : '');
                }
            } catch (err) {
                mostrarAlertaPacientes('Error de red al actualizar paciente.', 'danger');
            }
        });
    }

    const formAlergia = document.getElementById('formAlergiaPaciente');
    if (formAlergia) {
        formAlergia.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formAlergia).entries());
            payload.action = 'registrar_alergia';

            try {
                const resp = await fetch('/api/pacientes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();
                mostrarAlertaPacientes(data.mensaje || 'Alergia registrada.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('modalAlergiaPaciente')).hide();
                    ejecutarBusquedaPacientes(inputBusqueda ? inputBusqueda.value : '');
                }
            } catch (err) {
                mostrarAlertaPacientes('Error al registrar alerta de alergia.', 'danger');
            }
        });
    }
});
