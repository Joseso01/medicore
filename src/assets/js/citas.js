/**
 * Lógica Cliente Vanilla JS (ES6+) — Módulo de Control de Citas Médicas y Agenda (RF-CIT-01 a RF-CIT-04)
 * Cumplimiento:
 * - RF-CIT-04: Tablero interactivo de agenda diaria y filtrado dinámico
 * - RNF-SEG-03: Manipulación segura del DOM usando textContent y envío de X-CSRF-Token
 */
'use strict';

function obtenerCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function mostrarAlertaCitas(mensaje, tipo = 'success') {
    const box = document.getElementById('alertaCitas');
    const txt = document.getElementById('alertaCitasTexto');
    if (!box || !txt) return;

    box.className = `alert alert-${tipo} d-flex align-items-center justify-content-between mb-3`;
    txt.textContent = mensaje;
}

function filtrarMedicosPorEspecialidad() {
    const selectEsp = document.getElementById('filtroEspecialidad');
    const selectMed = document.getElementById('filtroMedico');
    if (!selectEsp || !selectMed) return;

    const idEspSeleccionada = selectEsp.value;
    const opciones = selectMed.querySelectorAll('option');

    opciones.forEach((opt) => {
        if (!opt.value) return; // Opción default "Todos"
        const espOpt = opt.getAttribute('data-esp');
        if (!idEspSeleccionada || espOpt === idEspSeleccionada) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });

    if (idEspSeleccionada) {
        const seleccionActual = selectMed.selectedOptions[0];
        if (seleccionActual && seleccionActual.value && seleccionActual.getAttribute('data-esp') !== idEspSeleccionada) {
            selectMed.value = '';
        }
    }
}

/**
 * RF-CIT-04: Carga y renderiza de forma asíncrona las citas según los filtros aplicados.
 */
async function aplicarFiltrosCitas() {
    const tbody = document.getElementById('tbodyCitas');
    const tabla = document.getElementById('tablaCitas');
    const lblTotal = document.getElementById('lblTotalCitas');
    if (!tbody || !tabla) return;

    const idRol = parseInt(tabla.getAttribute('data-rol') || '0', 10);
    const puedeEditar = tabla.getAttribute('data-puede-editar') === '1';

    const fecha = document.getElementById('filtroFecha')?.value || '';
    const idEsp = document.getElementById('filtroEspecialidad')?.value || '';
    const idMed = document.getElementById('filtroMedico')?.value || '';
    const estado = document.getElementById('filtroEstado')?.value || 'TODOS';

    const params = new URLSearchParams({
        action: 'listar',
        fecha: fecha,
        id_especialidad: idEsp,
        id_medico: idMed,
        estado: estado,
    });

    try {
        const resp = await fetch(`/api/citas.php?${params.toString()}`);
        const data = await resp.json();

        if (!data.ok) return;

        if (lblTotal) {
            lblTotal.textContent = `${data.citas.length} registro(s)`;
        }

        tbody.replaceChildren();

        if (!Array.isArray(data.citas) || data.citas.length === 0) {
            const trVacio = document.createElement('tr');
            const tdVacio = document.createElement('td');
            tdVacio.colSpan = 7;
            tdVacio.className = 'text-center text-muted py-4';
            tdVacio.textContent = 'No se encontraron citas para los criterios seleccionados.';
            trVacio.appendChild(tdVacio);
            tbody.appendChild(trVacio);
            return;
        }

        data.citas.forEach((c) => {
            const tr = document.createElement('tr');

            // 1. Horario y Fecha
            const tdHorario = document.createElement('td');
            const divHoras = document.createElement('div');
            divHoras.className = 'fw-bold font-mono';
            divHoras.textContent = `${c.hora_inicio.substring(0, 5)} - ${c.hora_fin.substring(0, 5)}`;
            const divFecha = document.createElement('div');
            divFecha.className = 'text-muted font-mono mc-fs-xs';
            divFecha.textContent = c.fecha_cita;
            tdHorario.append(divHoras, divFecha);

            // 2. Paciente
            const tdPaciente = document.createElement('td');
            const codeMrn = document.createElement('code');
            codeMrn.className = 'fw-bold text-primary';
            codeMrn.textContent = c.mrn;
            const divNom = document.createElement('div');
            divNom.className = 'fw-semibold';
            divNom.textContent = c.paciente_nombre;
            const divDet = document.createElement('div');
            divDet.className = 'small';
            const badgeRh = document.createElement('span');
            badgeRh.className = 'badge bg-danger bg-opacity-10 text-danger me-1';
            badgeRh.textContent = c.grupo_sanguineo;
            divDet.appendChild(badgeRh);

            if (Number(c.tiene_alergias_severas) > 0) {
                const badgeAlg = document.createElement('span');
                badgeAlg.className = 'badge bg-danger';
                badgeAlg.textContent = 'Alergia Severa';
                divDet.appendChild(badgeAlg);
            }

            tdPaciente.append(codeMrn, divNom, divDet);

            // 3. Médico y Especialidad
            const tdMedico = document.createElement('td');
            const divMed = document.createElement('div');
            divMed.className = 'fw-semibold';
            divMed.textContent = c.medico_nombre;
            const spanEsp = document.createElement('span');
            spanEsp.className = 'text-muted';
            spanEsp.textContent = c.especialidad_nombre;
            tdMedico.append(divMed, spanEsp);

            // 4. Consultorio
            const tdCons = document.createElement('td');
            const badgeCons = document.createElement('span');
            badgeCons.className = 'badge bg-light text-dark border';
            badgeCons.textContent = c.codigo_consultorio;
            tdCons.appendChild(badgeCons);

            // 5. Motivo y Cancelación
            const tdMotivo = document.createElement('td');
            const divMotivo = document.createElement('div');
            divMotivo.textContent = c.motivo_consulta;
            tdMotivo.appendChild(divMotivo);

            if (c.estado === 'CANCELADA' && c.motivo_cancelacion) {
                const divCanc = document.createElement('div');
                divCanc.className = 'text-danger small mt-1';
                divCanc.textContent = `Motivo cancelación: ${c.motivo_cancelacion}`;
                tdMotivo.appendChild(divCanc);
            }

            // 6. Estado Badge
            const tdEstado = document.createElement('td');
            const badgeEst = document.createElement('span');
            let colorClase = 'bg-secondary';
            if (c.estado === 'CONFIRMADA') colorClase = 'bg-primary';
            else if (c.estado === 'EN_CURSO') colorClase = 'bg-warning text-dark';
            else if (c.estado === 'ATENDIDA') colorClase = 'bg-success';
            else if (c.estado === 'CANCELADA') colorClase = 'bg-danger';
            else if (c.estado === 'NO_ASISTIO') colorClase = 'bg-dark';

            badgeEst.className = `badge ${colorClase} fs-7`;
            badgeEst.textContent = c.estado;
            tdEstado.appendChild(badgeEst);

            // 7. Acciones
            const tdAcciones = document.createElement('td');
            tdAcciones.className = 'text-end';
            const grp = document.createElement('div');
            grp.className = 'btn-group btn-group-sm';

            // Si es rol Médico (idRol=2) y cita activa
            if (idRol === 2 && ['PROGRAMADA', 'CONFIRMADA', 'EN_CURSO'].includes(c.estado)) {
                const aEhr = document.createElement('a');
                aEhr.href = `/consultas.php?id_cita=${c.id_cita}`;
                aEhr.className = 'btn btn-outline-success fw-semibold';
                aEhr.textContent = 'Atender';
                grp.appendChild(aEhr);
            }

            if (puedeEditar && ['PROGRAMADA', 'CONFIRMADA'].includes(c.estado)) {
                if (c.estado === 'PROGRAMADA') {
                    const btnConf = document.createElement('button');
                    btnConf.type = 'button';
                    btnConf.className = 'btn btn-outline-primary';
                    btnConf.textContent = 'Confirmar';
                    btnConf.addEventListener('click', () => cambiarEstadoCita(c.id_cita, 'CONFIRMADA'));
                    grp.appendChild(btnConf);
                }

                const btnReprog = document.createElement('button');
                btnReprog.type = 'button';
                btnReprog.className = 'btn btn-outline-info';
                btnReprog.title = 'Reprogramar Horario';
                btnReprog.textContent = 'Reprog.';
                btnReprog.addEventListener('click', () => abrirModalReprogramar(c.id_cita, c.fecha_cita, c.hora_inicio, c.id_consultorio));
                grp.appendChild(btnReprog);

                const btnNoAsist = document.createElement('button');
                btnNoAsist.type = 'button';
                btnNoAsist.className = 'btn btn-outline-warning text-dark';
                btnNoAsist.textContent = 'No Asistió';
                btnNoAsist.addEventListener('click', () => cambiarEstadoCita(c.id_cita, 'NO_ASISTIO'));
                grp.appendChild(btnNoAsist);

                const btnCanc = document.createElement('button');
                btnCanc.type = 'button';
                btnCanc.className = 'btn btn-outline-danger';
                btnCanc.textContent = 'Cancelar';
                btnCanc.addEventListener('click', () => abrirModalCancelar(c.id_cita));
                grp.appendChild(btnCanc);
            }

            tdAcciones.appendChild(grp);
            tr.append(tdHorario, tdPaciente, tdMedico, tdCons, tdMotivo, tdEstado, tdAcciones);
            tbody.appendChild(tr);
        });
    } catch (err) {
        mostrarAlertaCitas('Error al cargar la agenda de citas.', 'danger');
    }
}

async function cambiarEstadoCita(idCita, nuevoEstado) {
    if (!window.confirm(`¿Confirma cambiar el estado de la cita #${idCita} a [${nuevoEstado}]?`)) {
        return;
    }

    try {
        const resp = await fetch('/api/citas.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': obtenerCsrfToken(),
            },
            body: JSON.stringify({
                action: 'cambiar_estado',
                id_cita: idCita,
                estado: nuevoEstado,
            }),
        });
        const data = await resp.json();
        mostrarAlertaCitas(data.mensaje || 'Estado actualizado.', data.ok ? 'success' : 'danger');
        if (data.ok) {
            aplicarFiltrosCitas();
        }
    } catch (err) {
        mostrarAlertaCitas('Error de red al actualizar estado de la cita.', 'danger');
    }
}

function abrirModalCancelar(idCita) {
    document.getElementById('cancelar_id_cita').value = idCita;
    document.getElementById('cancelar_motivo').value = '';
    const modal = new bootstrap.Modal(document.getElementById('modalCancelarCita'));
    modal.show();
}

function abrirModalReprogramar(idCita, fechaActual, horaActual, idConsultorio) {
    document.getElementById('reprog_id_cita').value = idCita;
    document.getElementById('reprog_fecha').value = fechaActual;
    document.getElementById('reprog_hora').value = horaActual ? horaActual.substring(0, 5) : '09:00';
    if (idConsultorio) {
        document.getElementById('reprog_consultorio').value = idConsultorio;
    }
    const modal = new bootstrap.Modal(document.getElementById('modalReprogramarCita'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', () => {
    // Formulario de programación de citas (RF-CIT-01)
    const formProg = document.getElementById('formProgramarCita');
    if (formProg) {
        formProg.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formProg).entries());
            payload.action = 'programar';

            try {
                const resp = await fetch('/api/citas.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken(),
                    },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                mostrarAlertaCitas(data.mensaje || 'Resultado de programación recibido.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('modalProgramarCita')).hide();
                    formProg.reset();
                    aplicarFiltrosCitas();
                }
            } catch (err) {
                mostrarAlertaCitas('Error de red al intentar programar la cita.', 'danger');
            }
        });
    }

    // Formulario de cancelación con motivo (RF-CIT-03)
    const formCanc = document.getElementById('formCancelarCita');
    if (formCanc) {
        formCanc.addEventListener('submit', async (e) => {
            e.preventDefault();
            const idCita = parseInt(document.getElementById('cancelar_id_cita').value, 10);
            const motivo = document.getElementById('cancelar_motivo').value;

            try {
                const resp = await fetch('/api/citas.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken(),
                    },
                    body: JSON.stringify({
                        action: 'cambiar_estado',
                        id_cita: idCita,
                        estado: 'CANCELADA',
                        motivo_cancelacion: motivo,
                    }),
                });
                const data = await resp.json();
                mostrarAlertaCitas(data.mensaje || 'Cancelación procesada.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('modalCancelarCita')).hide();
                    aplicarFiltrosCitas();
                }
            } catch (err) {
                mostrarAlertaCitas('Error al cancelar la cita.', 'danger');
            }
        });
    }

    // Formulario de reprogramación (RF-CIT-03 / RN-CLI-01)
    const formReprog = document.getElementById('formReprogramarCita');
    if (formReprog) {
        formReprog.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(formReprog).entries());
            payload.action = 'reprogramar';

            try {
                const resp = await fetch('/api/citas.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken(),
                    },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                mostrarAlertaCitas(data.mensaje || 'Resultado de reprogramación.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('modalReprogramarCita')).hide();
                    aplicarFiltrosCitas();
                }
            } catch (err) {
                mostrarAlertaCitas('Error al reprogramar la cita.', 'danger');
            }
        });
    }
});
