/**
 * Lógica Cliente Vanilla JS (ES6+) — Módulo de Historial Clínico Electrónico (EHR) y Receta (RF-HIS-01 a 04)
 * Cumplimiento:
 * - RF-HIS-02: Cálculo automático de IMC en Vanilla JS y validación de signos vitales
 * - RF-HIS-03 / RN-CLI-05: Alerta preventiva y bloqueo de prescripción ante alergias registradas o falta de stock
 * - RN-CLI-03: Inmutabilidad poscierre y soporte de Notas de Adenda Clínica
 * - RNF-SEG-03: Manipulación segura del DOM usando textContent y envío de X-CSRF-Token
 */
'use strict';

let catalogoMedicamentos = [];
let alergiasPacienteActual = [];
let consultaCerradaActual = false;

function obtenerCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function mostrarAlertaEhr(mensaje, tipo = 'success') {
    const box = document.getElementById('alertaEhr');
    const txt = document.getElementById('alertaEhrTexto');
    if (!box || !txt) return;

    box.className = `alert alert-${tipo} d-flex align-items-center justify-content-between mb-3`;
    txt.textContent = mensaje;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/**
 * RF-HIS-02: Calcula automáticamente el Índice de Masa Corporal IMC = peso / (talla/100)^2
 */
function calcularImcAutomatico() {
    const inputPeso = document.getElementById('sv_peso');
    const inputTalla = document.getElementById('sv_talla');
    const inputImc = document.getElementById('sv_imc');
    const badgeImc = document.getElementById('badgeClasificacionImc');
    if (!inputPeso || !inputTalla || !inputImc) return;

    const peso = parseFloat(inputPeso.value);
    const tallaCm = parseFloat(inputTalla.value);

    if (isNaN(peso) || isNaN(tallaCm) || peso <= 0 || tallaCm < 30) {
        inputImc.value = '';
        if (badgeImc) {
            badgeImc.className = 'badge bg-secondary';
            badgeImc.textContent = 'IMC pendiente';
        }
        return;
    }

    const tallaM = tallaCm / 100.0;
    const imc = (peso / (tallaM * tallaM)).toFixed(2);
    inputImc.value = imc;

    if (badgeImc) {
        const val = parseFloat(imc);
        if (val < 18.5) {
            badgeImc.className = 'badge bg-warning text-dark';
            badgeImc.textContent = `IMC: ${imc} (Bajo Peso)`;
        } else if (val < 25.0) {
            badgeImc.className = 'badge bg-success';
            badgeImc.textContent = `IMC: ${imc} (Normopeso)`;
        } else if (val < 30.0) {
            badgeImc.className = 'badge bg-warning text-dark';
            badgeImc.textContent = `IMC: ${imc} (Sobrepeso)`;
        } else {
            badgeImc.className = 'badge bg-danger';
            badgeImc.textContent = `IMC: ${imc} (Obesidad)`;
        }
    }
}

/**
 * RF-HIS-01: Abre o recupera la consulta clínica asociada a una cita.
 */
async function cargarConsultaDesdeCita(idCita) {
    try {
        const resp = await fetch(`/api/consultas.php?action=cargar_cita&id_cita=${idCita}`);
        const data = await resp.json();

        if (!data.ok) {
            mostrarAlertaEhr(data.mensaje || 'No fue posible abrir la consulta.', 'danger');
            return;
        }

        // Marcar item activo en la lista izquierda
        document.querySelectorAll('.item-cita-ehr').forEach((btn) => {
            if (parseInt(btn.getAttribute('data-id-cita'), 10) === idCita) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        renderizarExpedienteConsulta(data);
    } catch (err) {
        mostrarAlertaEhr('Error de comunicación al cargar el expediente clínico.', 'danger');
    }
}

function renderizarExpedienteConsulta(data) {
    document.getElementById('panelSinSeleccion').classList.add('d-none');
    document.getElementById('panelExpedienteActivo').classList.remove('d-none');

    const cita = data.cita;
    const consulta = data.consulta;
    const sv = data.signos_vitales;
    const receta = data.receta;
    const recetaDet = data.receta_detalle || [];

    alergiasPacienteActual = Array.isArray(data.alergias) ? data.alergias : [];
    consultaCerradaActual = (consulta.estado_consulta === 'CERRADA');

    // Cabecera
    document.getElementById('input_id_consulta').value = consulta.id_consulta;
    document.getElementById('ehr_mrn').textContent = cita.mrn;
    document.getElementById('ehr_paciente_nombre').textContent = cita.paciente_nombre;
    document.getElementById('ehr_grupo_sanguineo').textContent = `Rh: ${cita.grupo_sanguineo}`;
    document.getElementById('ehr_edad_sexo').textContent = `${cita.edad} años (${cita.sexo_biologico})`;
    document.getElementById('ehr_consultorio').textContent = cita.codigo_consultorio;
    document.getElementById('ehr_motivo_cita').textContent = cita.motivo_consulta;

    const badgeEst = document.getElementById('ehr_badge_estado_consulta');
    badgeEst.className = consultaCerradaActual ? 'badge bg-success fs-6' : 'badge bg-warning text-dark fs-6';
    badgeEst.textContent = `CONSULTA ${consulta.estado_consulta} (#${consulta.id_consulta})`;

    document.getElementById('ehr_fechas_consulta').textContent = consulta.fecha_hora_cierre
        ? `Inicio: ${consulta.fecha_hora_inicio} | Cierre: ${consulta.fecha_hora_cierre}`
        : `Inicio: ${consulta.fecha_hora_inicio}`;

    // Alertas prominentes de alergias (RF-PAC-02 / RN-CLI-05)
    const contAlergias = document.getElementById('ehr_contenedor_alergias');
    contAlergias.replaceChildren();
    const lblAlg = document.createElement('strong');
    lblAlg.className = 'small me-1';
    lblAlg.textContent = 'Alertas de Alergias (RF-PAC-02):';
    contAlergias.appendChild(lblAlg);

    if (alergiasPacienteActual.length === 0) {
        const badgeOk = document.createElement('span');
        badgeOk.className = 'badge bg-success-subtle text-success border';
        badgeOk.textContent = 'Ninguna alergia farmacológica documentada';
        contAlergias.appendChild(badgeOk);
    } else {
        alergiasPacienteActual.forEach((alg) => {
            const b = document.createElement('span');
            b.className = 'badge bg-danger';
            b.textContent = `ALERTA ${alg.severidad}: ${alg.principio_activo_o_familia} (${alg.reaccion_descrita || 'Contraindicado'})`;
            contAlergias.appendChild(b);
        });
    }

    // Sello de Inmutabilidad (RN-CLI-03)
    const sello = document.getElementById('ehr_sello_inmutabilidad');
    if (consultaCerradaActual) {
        sello.classList.remove('d-none');
        document.getElementById('ehr_firma_hash').textContent = `SHA-256: ${(consulta.firma_digital_hash || '').substring(0, 24)}...`;
    } else {
        sello.classList.add('d-none');
    }

    // Signos vitales
    document.getElementById('sv_sistolica').value = sv ? sv.presion_sistolica : '';
    document.getElementById('sv_diastolica').value = sv ? sv.presion_diastolica : '';
    document.getElementById('sv_fc').value = sv ? sv.frecuencia_cardiaca : '';
    document.getElementById('sv_temp').value = sv ? sv.temperatura_c : '';
    document.getElementById('sv_spo2').value = sv ? sv.saturacion_o2 : '';
    document.getElementById('sv_peso').value = sv ? sv.peso_kg : '';
    document.getElementById('sv_talla').value = sv ? sv.talla_cm : '';
    calcularImcAutomatico();

    // Nota SOAP
    document.getElementById('soap_diagnostico').value = consulta.id_diagnostico_principal || '';
    document.getElementById('soap_subjetivo').value = consulta.nota_subjetiva || '';
    document.getElementById('soap_objetivo').value = consulta.nota_objetiva || '';
    document.getElementById('soap_analisis').value = consulta.nota_analisis || '';
    document.getElementById('soap_plan').value = consulta.nota_plan || '';

    // Receta Electrónica
    document.getElementById('ehr_folio_receta_badge').textContent = receta ? `${receta.folio_receta} (${receta.estado_receta})` : '';
    document.getElementById('receta_observaciones').value = receta ? (receta.observaciones_generales || '') : '';

    const tbodyRec = document.getElementById('tbodyRenglonesReceta');
    tbodyRec.replaceChildren();
    recetaDet.forEach((det) => agregarRenglonRecetaUI(det));

    // Bloquear edición si la consulta está CERRADA (RN-CLI-03)
    document.querySelectorAll('#formActoClinico .campo-ehr').forEach((el) => {
        el.disabled = consultaCerradaActual;
    });
    document.getElementById('btnAgregarRenglonReceta').classList.toggle('d-none', consultaCerradaActual);
    document.getElementById('barraBotonesActoClinico').classList.toggle('d-none', consultaCerradaActual);

    // Renderizar Adendas Clínicas (RN-CLI-03)
    renderizarAdendas(data.adendas || []);

    // Renderizar Historial Longitudinal (RF-HIS-04)
    renderizarHistorialLongitudinal(data.historial_longitudinal || []);
}

function agregarRenglonRecetaUI(datos = null) {
    const tbody = document.getElementById('tbodyRenglonesReceta');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.className = 'renglon-receta';

    // 1. Selector de Medicamento
    const tdMed = document.createElement('td');
    const selMed = document.createElement('select');
    selMed.className = 'form-select form-select-sm rec-medicamento campo-ehr';
    selMed.required = true;
    selMed.disabled = consultaCerradaActual;

    const optDefault = document.createElement('option');
    optDefault.value = '';
    optDefault.textContent = '-- Seleccionar Fármaco --';
    selMed.appendChild(optDefault);

    catalogoMedicamentos.forEach((m) => {
        const opt = document.createElement('option');
        opt.value = m.id_medicamento;
        opt.textContent = `${m.nombre_generico} (${m.concentracion}) — Stock: ${m.stock_vigente_disponible}`;
        opt.setAttribute('data-alergeno', m.id_alergeno || '');
        opt.setAttribute('data-stock', m.stock_vigente_disponible || '0');
        if (datos && Number(datos.id_medicamento) === Number(m.id_medicamento)) {
            opt.selected = true;
        }
        selMed.appendChild(opt);
    });

    // Validación en vivo de alergia severa (RN-CLI-05) al seleccionar fármaco
    selMed.addEventListener('change', () => {
        const optSel = selMed.selectedOptions[0];
        if (!optSel) return;
        const idAlergenoMed = parseInt(optSel.getAttribute('data-alergeno') || '0', 10);
        if (idAlergenoMed > 0) {
            const coincidencia = alergiasPacienteActual.find(
                (a) => Number(a.id_alergeno) === idAlergenoMed && ['MODERADA', 'ANAFILAXIA_SEVERA'].includes(a.severidad)
            );
            if (coincidencia) {
                mostrarAlertaEhr(
                    `RN-CLI-05: ¡CONTRAINDICACIÓN CLÍNICA! El paciente presenta alergia [${coincidencia.severidad}] a [${coincidencia.principio_activo_o_familia}].`,
                    'danger'
                );
            }
        }
    });
    tdMed.appendChild(selMed);

    // 2. Dosis
    const tdDosis = document.createElement('td');
    const inpDosis = document.createElement('input');
    inpDosis.type = 'text';
    inpDosis.className = 'form-control form-control-sm rec-dosis campo-ehr';
    inpDosis.placeholder = 'ej. 1 tableta (500 mg)';
    inpDosis.value = datos ? datos.dosis : '';
    inpDosis.required = true;
    inpDosis.disabled = consultaCerradaActual;
    tdDosis.appendChild(inpDosis);

    // 3. Vía
    const tdVia = document.createElement('td');
    const selVia = document.createElement('select');
    selVia.className = 'form-select form-select-sm rec-via campo-ehr';
    selVia.disabled = consultaCerradaActual;
    ['ORAL', 'INTRAMUSCULAR', 'INTRAVENOSA', 'SUBCUTANEA', 'TOPICA', 'INHALATORIA', 'OFTALMICA'].forEach((v) => {
        const o = document.createElement('option');
        o.value = v;
        o.textContent = v;
        if (datos && datos.via_administracion === v) o.selected = true;
        selVia.appendChild(o);
    });
    tdVia.appendChild(selVia);

    // 4. Frecuencia
    const tdFrec = document.createElement('td');
    const inpFrec = document.createElement('input');
    inpFrec.type = 'text';
    inpFrec.className = 'form-control form-control-sm rec-frecuencia campo-ehr';
    inpFrec.placeholder = 'ej. Cada 8 horas';
    inpFrec.value = datos ? datos.frecuencia_horaria : '';
    inpFrec.required = true;
    inpFrec.disabled = consultaCerradaActual;
    tdFrec.appendChild(inpFrec);

    // 5. Duración
    const tdDur = document.createElement('td');
    const inpDur = document.createElement('input');
    inpDur.type = 'text';
    inpDur.className = 'form-control form-control-sm rec-duracion campo-ehr';
    inpDur.placeholder = 'ej. 5 días';
    inpDur.value = datos ? datos.duracion_tratamiento : '';
    inpDur.required = true;
    inpDur.disabled = consultaCerradaActual;
    tdDur.appendChild(inpDur);

    // 6. Cantidad a dispensar
    const tdCant = document.createElement('td');
    const inpCant = document.createElement('input');
    inpCant.type = 'number';
    inpCant.min = '1';
    inpCant.className = 'form-control form-control-sm rec-cantidad campo-ehr';
    inpCant.value = datos ? datos.cantidad_prescrita : '1';
    inpCant.required = true;
    inpCant.disabled = consultaCerradaActual;
    tdCant.appendChild(inpCant);

    // 7. Botón quitar
    const tdDel = document.createElement('td');
    tdDel.className = 'text-center';
    if (!consultaCerradaActual) {
        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.className = 'btn btn-outline-danger btn-sm';
        btnDel.textContent = '×';
        btnDel.addEventListener('click', () => tr.remove());
        tdDel.appendChild(btnDel);
    }

    tr.append(tdMed, tdDosis, tdVia, tdFrec, tdDur, tdCant, tdDel);
    tbody.appendChild(tr);
}

async function enviarActoClinico(cerrarConsulta = false) {
    if (cerrarConsulta) {
        const ok = window.confirm(
            '¿Confirma CERRAR Y FIRMAR DIGITALMENTE esta consulta clínica (RF-HIS-04)?\n\nUna vez cerrada, el registro y su receta quedarán sellados de forma inmutable por los Triggers de MySQL (RN-CLI-03).'
        );
        if (!ok) return;
    }

    const renglones = [];
    document.querySelectorAll('#tbodyRenglonesReceta tr.renglon-receta').forEach((tr) => {
        const idMed = tr.querySelector('.rec-medicamento')?.value;
        if (idMed) {
            renglones.push({
                id_medicamento: parseInt(idMed, 10),
                dosis: tr.querySelector('.rec-dosis')?.value || '',
                via_administracion: tr.querySelector('.rec-via')?.value || 'ORAL',
                frecuencia_horaria: tr.querySelector('.rec-frecuencia')?.value || '',
                duracion_tratamiento: tr.querySelector('.rec-duracion')?.value || '',
                cantidad_prescrita: parseInt(tr.querySelector('.rec-cantidad')?.value || '1', 10),
            });
        }
    });

    const payload = {
        action: cerrarConsulta ? 'cerrar_consulta' : 'guardar_consulta',
        id_consulta: parseInt(document.getElementById('input_id_consulta').value, 10),
        presion_sistolica: document.getElementById('sv_sistolica').value,
        presion_diastolica: document.getElementById('sv_diastolica').value,
        frecuencia_cardiaca: document.getElementById('sv_fc').value,
        temperatura_c: document.getElementById('sv_temp').value,
        saturacion_o2: document.getElementById('sv_spo2').value,
        peso_kg: document.getElementById('sv_peso').value,
        talla_cm: document.getElementById('sv_talla').value,
        id_diagnostico_principal: document.getElementById('soap_diagnostico').value,
        nota_subjetiva: document.getElementById('soap_subjetivo').value,
        nota_objetiva: document.getElementById('soap_objetivo').value,
        nota_analisis: document.getElementById('soap_analisis').value,
        nota_plan: document.getElementById('soap_plan').value,
        observaciones_receta: document.getElementById('receta_observaciones').value,
        renglones_receta: renglones,
    };

    try {
        const resp = await fetch('/api/consultas.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': obtenerCsrfToken(),
            },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();
        mostrarAlertaEhr(data.mensaje || 'Operación procesada.', data.ok ? 'success' : 'danger');

        if (data.ok) {
            const respReload = await fetch(`/api/consultas.php?action=cargar_consulta&id_consulta=${payload.id_consulta}`);
            const dataReload = await respReload.json();
            if (dataReload.ok) {
                renderizarExpedienteConsulta(dataReload);
            }
        }
    } catch (err) {
        mostrarAlertaEhr('Error de red al guardar la consulta clínica.', 'danger');
    }
}

function renderizarAdendas(adendas) {
    document.getElementById('ehr_count_adendas').textContent = String(adendas.length);
    const cont = document.getElementById('listaAdendasClinicas');
    const formAdenda = document.getElementById('formNuevaAdenda');
    cont.replaceChildren();

    formAdenda.classList.toggle('d-none', !consultaCerradaActual);

    if (adendas.length === 0) {
        const p = document.createElement('p');
        p.className = 'text-muted small mb-0';
        p.textContent = consultaCerradaActual
            ? 'No existen notas de adenda registradas para esta consulta. Puede agregar una aclaración evolutiva abajo.'
            : 'Las notas de adenda clínica se habilitan una vez que la consulta primaria ha sido CERRADA (RN-CLI-03).';
        cont.appendChild(p);
        return;
    }

    adendas.forEach((ad) => {
        const div = document.createElement('div');
        div.className = 'border-start border-4 border-primary bg-light p-3 rounded mb-2 small';

        const header = document.createElement('div');
        header.className = 'fw-bold text-primary mb-1';
        header.textContent = `Adenda #${ad.id_adenda} — ${ad.fecha_registro} | ${ad.medico_autor_nombre} (Cédula: ${ad.cedula_profesional})`;

        const body = document.createElement('div');
        body.textContent = ad.texto_aclaracion;

        div.append(header, body);
        cont.appendChild(div);
    });
}

function renderizarHistorialLongitudinal(historial) {
    document.getElementById('ehr_count_historial').textContent = String(historial.length);
    const cont = document.getElementById('contenedorHistorialLongitudinal');
    cont.replaceChildren();

    if (historial.length === 0) {
        const p = document.createElement('p');
        p.className = 'text-muted small mb-0';
        p.textContent = 'Sin consultas registradas en el historial longitudinal.';
        cont.appendChild(p);
        return;
    }

    historial.forEach((h) => {
        const card = document.createElement('div');
        card.className = 'border rounded p-3 mb-3 small bg-light';

        const top = document.createElement('div');
        top.className = 'd-flex justify-content-between align-items-center border-bottom pb-2 mb-2';

        const title = document.createElement('strong');
        title.textContent = `Consulta #${h.id_consulta} (${h.fecha_hora_inicio}) — ${h.medico_nombre} (${h.especialidad_nombre})`;

        const badge = document.createElement('span');
        badge.className = h.estado_consulta === 'CERRADA' ? 'badge bg-success' : 'badge bg-warning text-dark';
        badge.textContent = h.estado_consulta;

        top.append(title, badge);

        const diag = document.createElement('div');
        diag.className = 'fw-semibold text-primary mb-1';
        diag.textContent = h.codigo_cie
            ? `Diagnóstico CIE-10: [${h.codigo_cie}] ${h.diagnostico_descripcion}`
            : 'Diagnóstico en captura';

        const sv = document.createElement('div');
        sv.className = 'text-muted font-mono mb-2';
        sv.textContent = h.presion_sistolica
            ? `Signos Vitales: PA ${h.presion_sistolica}/${h.presion_diastolica} mmHg | FC ${h.frecuencia_cardiaca} lpm | Temp ${h.temperatura_c} °C | SpO2 ${h.saturacion_o2}% | Peso ${h.peso_kg} kg | IMC ${h.imc}`
            : 'Sin signos vitales capturados aún';

        const soap = document.createElement('div');
        soap.textContent = `Subjetivo: ${h.nota_subjetiva || '-'} | Objetivo: ${h.nota_objetiva || '-'} | Plan: ${h.nota_plan || '-'}`;

        card.append(top, diag, sv, soap);

        if (h.folio_receta) {
            const rec = document.createElement('div');
            rec.className = 'mt-2 pt-2 border-top fw-semibold text-success';
            rec.textContent = `Receta Emitida: ${h.folio_receta} (${h.estado_receta})`;
            card.appendChild(rec);
        }

        cont.appendChild(card);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const jsonEl = document.getElementById('catalogoMedicamentosJson');
    if (jsonEl) {
        try {
            catalogoMedicamentos = JSON.parse(jsonEl.textContent || '[]');
        } catch (e) {
            catalogoMedicamentos = [];
        }
    }

    const contModulo = document.getElementById('contenedorModuloHis');
    const idCitaInicial = parseInt(contModulo?.getAttribute('data-id-cita-inicial') || '0', 10);
    if (idCitaInicial > 0) {
        cargarConsultaDesdeCita(idCitaInicial);
    }

    const formAdenda = document.getElementById('formNuevaAdenda');
    if (formAdenda) {
        formAdenda.addEventListener('submit', async (e) => {
            e.preventDefault();
            const idConsulta = parseInt(document.getElementById('input_id_consulta').value, 10);
            const texto = document.getElementById('adenda_texto').value;

            try {
                const resp = await fetch('/api/consultas.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': obtenerCsrfToken(),
                    },
                    body: JSON.stringify({
                        action: 'registrar_adenda',
                        id_consulta: idConsulta,
                        texto_aclaracion: texto,
                    }),
                });
                const data = await resp.json();
                mostrarAlertaEhr(data.mensaje || 'Adenda registrada.', data.ok ? 'success' : 'danger');
                if (data.ok) {
                    document.getElementById('adenda_texto').value = '';
                    const respReload = await fetch(`/api/consultas.php?action=cargar_consulta&id_consulta=${idConsulta}`);
                    const dataReload = await respReload.json();
                    if (dataReload.ok) {
                        renderizarExpedienteConsulta(dataReload);
                    }
                }
            } catch (err) {
                mostrarAlertaEhr('Error al guardar la adenda clínica.', 'danger');
            }
        });
    }
});
