/**
 * Lógica Cliente Vanilla JS (ES6+) — Módulo de Seguridad y Auditoría (RF-SEG)
 * Cumplimiento: RNF-SEG-03 (Uso exclusivo de textContent para prevenir XSS y envío de X-CSRF-Token)
 */
'use strict';

function obtenerCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function mostrarAlerta(mensaje, tipo = 'success') {
    const contenedor = document.getElementById('alertaSeguridad');
    const texto = document.getElementById('alertaSeguridadTexto');
    if (!contenedor || !texto) return;

    contenedor.className = `alert alert-${tipo} d-flex align-items-center justify-content-between mb-3`;
    // RNF-SEG-03: Uso estricto de textContent en lugar de innerHTML
    texto.textContent = mensaje;
}

function toggleCamposMedico() {
    const selectRol = document.getElementById('selectRolUsuario');
    const seccionMedico = document.getElementById('seccionDatosMedico');
    if (!selectRol || !seccionMedico) return;

    if (parseInt(selectRol.value, 10) === 2) {
        seccionMedico.classList.remove('d-none');
    } else {
        seccionMedico.classList.add('d-none');
    }
}

async function cambiarEstadoUsuario(idUsuario, nuevoEstado) {
    const confirmMsg = nuevoEstado === 'BAJA_LOGICA'
        ? `¿Confirma aplicar BAJA_LOGICA al usuario #${idUsuario}? Se revocarán de inmediato sus sesiones activas preservando sus llaves foráneas históricas (RF-SEG-03).`
        : `¿Confirma cambiar el estado del usuario #${idUsuario} a [${nuevoEstado}]?`;

    if (!window.confirm(confirmMsg)) {
        return;
    }

    try {
        const resp = await fetch('/api/seguridad.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': obtenerCsrfToken()
            },
            body: JSON.stringify({
                action: 'cambiar_estado',
                id_usuario: idUsuario,
                estado: nuevoEstado
            })
        });

        const data = await resp.json();
        mostrarAlerta(data.mensaje || 'Operación procesada.', data.ok ? 'success' : 'danger');
        if (data.ok) {
            setTimeout(() => window.location.reload(), 900);
        }
    } catch (err) {
        mostrarAlerta('Error de red al comunicarse con el servidor.', 'danger');
    }
}

async function desbloquearUsuario(idUsuario) {
    try {
        const resp = await fetch('/api/seguridad.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': obtenerCsrfToken()
            },
            body: JSON.stringify({
                action: 'desbloquear_cuenta',
                id_usuario: idUsuario
            })
        });

        const data = await resp.json();
        mostrarAlerta(data.mensaje || 'Cuenta desbloqueada.', data.ok ? 'success' : 'danger');
        if (data.ok) {
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) {
        mostrarAlerta('Error al desbloquear cuenta.', 'danger');
    }
}

async function recargarBitacora() {
    const tbody = document.getElementById('tbodyBitacora');
    if (!tbody) return;

    try {
        const resp = await fetch('/api/seguridad.php?action=listar_bitacora&limite=50');
        const data = await resp.json();
        if (!data.ok || !Array.isArray(data.bitacora)) return;

        tbody.replaceChildren();

        data.bitacora.forEach((ev) => {
            const tr = document.createElement('tr');

            const tdId = document.createElement('td');
            tdId.className = 'font-mono';
            tdId.textContent = `#${ev.id_evento}`;

            const tdFecha = document.createElement('td');
            tdFecha.className = 'font-mono';
            tdFecha.textContent = ev.fecha_evento || '';

            const tdTabla = document.createElement('td');
            const codeTabla = document.createElement('code');
            codeTabla.textContent = ev.tabla_afectada || '';
            tdTabla.appendChild(codeTabla);

            const tdOp = document.createElement('td');
            const badgeOp = document.createElement('span');
            badgeOp.className = 'badge bg-dark';
            badgeOp.textContent = ev.operacion || '';
            tdOp.appendChild(badgeOp);

            const tdReg = document.createElement('td');
            tdReg.className = 'font-mono';
            tdReg.textContent = ev.id_registro_afectado || '-';

            const tdExec = document.createElement('td');
            const divUser = document.createElement('div');
            divUser.textContent = ev.usuario_ejecutor || 'Trigger MySQL';
            const spanIp = document.createElement('span');
            spanIp.className = 'text-muted font-mono';
            spanIp.textContent = ev.ip_origen || 'motor-innodb';
            tdExec.appendChild(divUser);
            tdExec.appendChild(spanIp);

            const tdMotivo = document.createElement('td');
            tdMotivo.textContent = ev.motivo || 'Auditoría automática por Trigger RBD-INT-05';

            const tdJson = document.createElement('td');
            tdJson.className = 'font-mono mc-cell-json';

            if (ev.valores_anteriores) {
                const divOld = document.createElement('div');
                divOld.className = 'text-danger text-truncate';
                divOld.title = ev.valores_anteriores;
                divOld.textContent = `OLD: ${ev.valores_anteriores}`;
                tdJson.appendChild(divOld);
            }
            if (ev.valores_nuevos) {
                const divNew = document.createElement('div');
                divNew.className = 'text-success text-truncate';
                divNew.title = ev.valores_nuevos;
                divNew.textContent = `NEW: ${ev.valores_nuevos}`;
                tdJson.appendChild(divNew);
            }

            tr.append(tdId, tdFecha, tdTabla, tdOp, tdReg, tdExec, tdMotivo, tdJson);
            tbody.appendChild(tr);
        });
    } catch (err) {
        mostrarAlerta('No fue posible actualizar la bitácora de auditoría.', 'warning');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    toggleCamposMedico();

    const formNuevo = document.getElementById('formNuevoUsuario');
    if (formNuevo) {
        formNuevo.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formNuevo);
            const payload = Object.fromEntries(formData.entries());
            payload.action = 'crear_usuario';

            try {
                const resp = await fetch('/api/seguridad.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();
                mostrarAlerta(data.mensaje || 'Resultado recibido.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    setTimeout(() => window.location.reload(), 900);
                }
            } catch (err) {
                mostrarAlerta('Error al registrar el usuario.', 'danger');
            }
        });
    }
});
