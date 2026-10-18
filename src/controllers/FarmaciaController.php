<?php
declare(strict_types=1);

namespace MediCore\Controllers;

use MediCore\Config\Database;
use MediCore\Core\Security;
use PDO;
use PDOException;

/**
 * Controlador del Submódulo de Farmacia, Lotes FEFO, Dispensación y Kárdex (FAR)
 * Cumplimiento: RF-FAR-01 a RF-FAR-04, RBD-INT-02, RBD-INT-03, RBD-INT-04, RBD-INT-05, RN-CLI-04, RN-CLI-06
 */
final class FarmaciaController
{
    /**
     * RF-FAR-01 y RF-FAR-04: Obtiene el catálogo maestro de medicamentos con el cálculo en tiempo real
     * de existencias vigentes no caducadas y condición de alerta de stock crítico (<= stock_minimo).
     */
    public function listarMedicamentosYAlertasStock(): array
    {
        $pdo = Database::getConnection();

        $medicamentos = $pdo->query(
            "SELECT m.id_medicamento, m.codigo_sku, m.nombre_generico, m.nombre_comercial,
                    m.principio_activo, m.id_alergeno, m.forma_farmaceutica, m.concentracion,
                    m.stock_minimo, m.stock_global_actual, m.activo,
                    ca.principio_activo_o_familia AS alergeno_nombre,
                    COALESCE((
                        SELECT SUM(li.cantidad_actual)
                        FROM lotes_inventario li
                        WHERE li.id_medicamento = m.id_medicamento
                          AND li.estado_lote = 'ACTIVO'
                          AND li.fecha_caducidad > CURDATE()
                    ), 0) AS existencias_vigentes
             FROM medicamentos m
             LEFT JOIN catalogo_alergias ca ON ca.id_alergeno = m.id_alergeno
             ORDER BY m.codigo_sku ASC"
        )->fetchAll();

        foreach ($medicamentos as &$med) {
            $med['alerta_stock_critico'] = ((int)$med['existencias_vigentes'] <= (int)$med['stock_minimo']);
        }
        unset($med);

        return $medicamentos;
    }

    /**
     * RF-FAR-04: Obtiene todos los lotes de inventario clasificados mediante el Semáforo de Caducidad:
     * - ROJO: fecha_caducidad <= CURDATE() (caducados, bloqueados para dispensación)
     * - AMBAR: vigencia entre 1 y 90 días (próximos a vencer, prioridad FEFO)
     * - VERDE: vigencia > 90 días
     */
    public function listarLotesSemaforo(): array
    {
        $pdo = Database::getConnection();

        return $pdo->query(
            "SELECT li.id_lote, li.id_medicamento, li.numero_lote, li.fecha_fabricacion,
                    li.fecha_caducidad, li.cantidad_inicial, li.cantidad_actual, li.estado_lote,
                    li.fecha_recepcion,
                    DATEDIFF(li.fecha_caducidad, CURDATE()) AS dias_vigencia,
                    CASE
                        WHEN li.fecha_caducidad <= CURDATE() OR li.estado_lote = 'CADUCADO' THEN 'ROJO'
                        WHEN DATEDIFF(li.fecha_caducidad, CURDATE()) <= 90 THEN 'AMBAR'
                        ELSE 'VERDE'
                    END AS semaforo_caducidad,
                    m.codigo_sku, m.nombre_generico, m.nombre_comercial, m.concentracion,
                    u.username AS recibido_por
             FROM lotes_inventario li
             INNER JOIN medicamentos m ON m.id_medicamento = li.id_medicamento
             INNER JOIN usuarios u ON u.id_usuario = li.id_usuario_recepcion
             ORDER BY li.fecha_caducidad ASC, li.id_lote ASC"
        )->fetchAll();
    }

    /**
     * RF-FAR-02: Lista las recetas médicas en estado PENDIENTE_SURTIR o SURTIDA_PARCIAL
     * filtrando opcionalmente por folio_receta o MRN del paciente, y calcula la propuesta
     * automática de lotes bajo estricto ordenamiento FEFO (ORDER BY fecha_caducidad ASC).
     */
    public function buscarRecetasPendientesFefo(string $termino = ''): array
    {
        $pdo = Database::getConnection();
        $termino = trim($termino);

        $sql = "SELECT r.id_receta, r.folio_receta, r.id_consulta, r.estado_receta,
                       r.observaciones_generales, r.fecha_emision,
                       p.id_paciente, p.mrn, p.nombre_completo AS paciente_nombre, p.grupo_sanguineo,
                       u.nombre_completo AS medico_nombre, m.cedula_profesional, e.nombre AS especialidad_nombre
                FROM recetas_medicas r
                INNER JOIN consultas_clinicas cc ON cc.id_consulta = r.id_consulta
                INNER JOIN pacientes p ON p.id_paciente = cc.id_paciente
                INNER JOIN medicos m ON m.id_medico = r.id_medico_emisor
                INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
                INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
                WHERE r.estado_receta IN ('PENDIENTE_SURTIR', 'SURTIDA_PARCIAL')";

        $params = [];
        if ($termino !== '') {
            $sql .= " AND (r.folio_receta LIKE :f OR p.mrn LIKE :m OR p.nombre_completo LIKE :n)";
            $params[':f'] = $termino . '%';
            $params[':m'] = $termino . '%';
            $params[':n'] = '%' . $termino . '%';
        }

        $sql .= " ORDER BY r.fecha_emision ASC LIMIT 30";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $recetas = $stmt->fetchAll();

        // Para cada receta pendiente, obtener sus renglones y sugerir lotes vigentes ordenados por FEFO
        $stmtDet = $pdo->prepare(
            "SELECT rd.id_receta_detalle, rd.id_receta, rd.id_medicamento, rd.dosis,
                    rd.via_administracion, rd.frecuencia_horaria, rd.duracion_tratamiento,
                    rd.cantidad_prescrita, rd.cantidad_surtida,
                    (rd.cantidad_prescrita - rd.cantidad_surtida) AS cantidad_pendiente,
                    m.codigo_sku, m.nombre_generico, m.nombre_comercial, m.forma_farmaceutica, m.concentracion
             FROM receta_detalle rd
             INNER JOIN medicamentos m ON m.id_medicamento = rd.id_medicamento
             WHERE rd.id_receta = :id_rec"
        );

        // Consulta FEFO indexada (idx_lotes_fefo: id_medicamento, estado_lote, fecha_caducidad)
        $stmtLotesFefo = $pdo->prepare(
            "SELECT id_lote, numero_lote, fecha_caducidad, cantidad_actual,
                    DATEDIFF(fecha_caducidad, CURDATE()) AS dias_vigencia
             FROM lotes_inventario
             WHERE id_medicamento = :id_med
               AND estado_lote = 'ACTIVO'
               AND fecha_caducidad > CURDATE()
               AND cantidad_actual > 0
             ORDER BY fecha_caducidad ASC, id_lote ASC"
        );

        foreach ($recetas as &$rec) {
            $stmtDet->execute([':id_rec' => (int)$rec['id_receta']]);
            $renglones = $stmtDet->fetchAll();

            foreach ($renglones as &$renglon) {
                $pendiente = (int)$renglon['cantidad_pendiente'];
                $stmtLotesFefo->execute([':id_med' => (int)$renglon['id_medicamento']]);
                $lotesVigentes = $stmtLotesFefo->fetchAll();

                // Asignación automática FEFO (puede cubrirse con 1 o múltiples lotes vigentes en orden de caducidad)
                $asignacionFefo = [];
                $porCubrir = $pendiente;

                foreach ($lotesVigentes as $lote) {
                    if ($porCubrir <= 0) {
                        break;
                    }
                    $disponibleLote = (int)$lote['cantidad_actual'];
                    $tomar = min($porCubrir, $disponibleLote);
                    $asignacionFefo[] = [
                        'id_lote'             => (int)$lote['id_lote'],
                        'numero_lote'         => $lote['numero_lote'],
                        'fecha_caducidad'     => $lote['fecha_caducidad'],
                        'dias_vigencia'       => (int)$lote['dias_vigencia'],
                        'disponible_en_lote'  => $disponibleLote,
                        'cantidad_sugerida'   => $tomar,
                    ];
                    $porCubrir -= $tomar;
                }

                $renglon['lotes_vigentes']  = $lotesVigentes;
                $renglon['asignacion_fefo'] = $asignacionFefo;
            }
            unset($renglon);

            $rec['renglones'] = $renglones;
        }
        unset($rec);

        return $recetas;
    }

    /**
     * RF-FAR-01: Alta de nuevo medicamento en el catálogo maestro (exclusivo Administrador).
     */
    public function crearMedicamento(array $datos, int $idAdminEjecutor): array
    {
        $sku           = strtoupper(trim((string)($datos['codigo_sku'] ?? '')));
        $generico      = trim((string)($datos['nombre_generico'] ?? ''));
        $comercial     = trim((string)($datos['nombre_comercial'] ?? ''));
        $principio     = trim((string)($datos['principio_activo'] ?? ''));
        $idAlergeno    = !empty($datos['id_alergeno']) ? (int)$datos['id_alergeno'] : null;
        $forma         = trim((string)($datos['forma_farmaceutica'] ?? ''));
        $concentracion = trim((string)($datos['concentracion'] ?? ''));
        $stockMinimo   = isset($datos['stock_minimo']) ? max(0, (int)$datos['stock_minimo']) : 10;

        if ($sku === '' || $generico === '' || $comercial === '' || $principio === '' || $forma === '' || $concentracion === '') {
            return [
                'ok'      => false,
                'mensaje' => 'RF-FAR-01: Todos los campos del catálogo maestro de medicamentos (SKU, genérico, comercial, principio activo, forma y concentración) son obligatorios.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO medicamentos (
                    codigo_sku, nombre_generico, nombre_comercial, principio_activo,
                    id_alergeno, forma_farmaceutica, concentracion, stock_minimo, stock_global_actual, activo
                 ) VALUES (
                    :sku, :gen, :com, :prin, :alg, :forma, :conc, :min, 0, 1
                 )"
            );
            $stmt->execute([
                ':sku'   => $sku,
                ':gen'   => $generico,
                ':com'   => $comercial,
                ':prin'  => $principio,
                ':alg'   => $idAlergeno,
                ':forma' => $forma,
                ':conc'  => $concentracion,
                ':min'   => $stockMinimo,
            ]);

            $idMed = (int)$pdo->lastInsertId();

            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_nuevos, ip_origen)
                 VALUES ('medicamentos', 'INSERT', :id_reg, :uid, :motivo, :nuevos, :ip)"
            );
            $stmtAudit->execute([
                ':id_reg' => (string)$idMed,
                ':uid'    => $idAdminEjecutor,
                ':motivo' => "RF-FAR-01: Alta de medicamento en catálogo maestro [{$sku} - {$generico}]",
                ':nuevos' => json_encode(['codigo_sku' => $sku, 'nombre_generico' => $generico, 'stock_minimo' => $stockMinimo], JSON_UNESCAPED_UNICODE),
                ':ip'     => Security::getClientIp(),
            ]);

            return [
                'ok'             => true,
                'id_medicamento' => $idMed,
                'mensaje'        => "Medicamento [{$sku} — {$generico}] agregado al catálogo maestro. Recuerde registrar lotes de recepción en lotes_inventario para ingresar existencias (RF-FAR-01).",
            ];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'mensaje' => "El código SKU [{$sku}] ya existe en el catálogo."];
            }
            return ['ok' => false, 'mensaje' => 'Error al crear medicamento: ' . $e->getMessage()];
        }
    }

    /**
     * RF-FAR-01: Recepción de un nuevo lote físico en lotes_inventario.
     * El Trigger trg_after_insert_lote_inventario actualizará automáticamente stock_global_actual
     * e insertará el movimiento ENTRADA_LOTE en movimientos_kardex.
     */
    public function recepcionarLote(array $datos, int $idUsuarioRecepcion): array
    {
        $idMedicamento    = (int)($datos['id_medicamento'] ?? 0);
        $numeroLote       = strtoupper(trim((string)($datos['numero_lote'] ?? '')));
        $fechaFabricacion = trim((string)($datos['fecha_fabricacion'] ?? ''));
        $fechaCaducidad   = trim((string)($datos['fecha_caducidad'] ?? ''));
        $cantidadInicial  = (int)($datos['cantidad_inicial'] ?? 0);

        if ($idMedicamento <= 0 || $numeroLote === '' || $fechaFabricacion === '' || $fechaCaducidad === '' || $cantidadInicial <= 0) {
            return [
                'ok'      => false,
                'mensaje' => 'RF-FAR-01: Debe especificar medicamento, número de lote del fabricante, fechas de fabricación/caducidad y una cantidad recibida mayor a cero.',
            ];
        }

        if ($fechaCaducidad <= date('Y-m-d')) {
            return [
                'ok'      => false,
                'mensaje' => 'RF-FAR-01: Rechazo sanitario. No se permite recepcionar un lote con fecha de caducidad vencida o del día en curso (fecha_caducidad > CURDATE()).',
            ];
        }

        if ($fechaCaducidad <= $fechaFabricacion) {
            return [
                'ok'      => false,
                'mensaje' => 'La fecha de caducidad debe ser posterior a la fecha de fabricación.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO lotes_inventario (
                    id_medicamento, numero_lote, fecha_fabricacion, fecha_caducidad,
                    cantidad_inicial, cantidad_actual, estado_lote, id_usuario_recepcion
                 ) VALUES (
                    :id_med, :lote, :ffab, :fcad, :cant_ini, :cant_act, 'ACTIVO', :uid
                 )"
            );
            $stmt->execute([
                ':id_med'   => $idMedicamento,
                ':lote'     => $numeroLote,
                ':ffab'     => $fechaFabricacion,
                ':fcad'     => $fechaCaducidad,
                ':cant_ini' => $cantidadInicial,
                ':cant_act' => $cantidadInicial,
                ':uid'      => $idUsuarioRecepcion,
            ]);

            $idLote = (int)$pdo->lastInsertId();
            $pdo->commit();

            return [
                'ok'      => true,
                'id_lote' => $idLote,
                'mensaje' => "Lote [{$numeroLote}] recepcionado exitosamente (+{$cantidadInicial} unidades). El Trigger de MySQL actualizó el stock global y asentó el movimiento ENTRADA_LOTE en el Kárdex.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'mensaje' => "El número de lote [{$numeroLote}] ya fue registrado previamente para este medicamento."];
            }
            return ['ok' => false, 'mensaje' => 'Error al recepcionar lote: ' . $e->getMessage()];
        }
    }

    /**
     * RF-FAR-02, RF-FAR-03, RBD-INT-02, RBD-INT-03, RBD-INT-04 y RN-CLI-04:
     * Ejecuta la dispensación atómica de una receta bajo la regla FEFO:
     * - Bloquea con SELECT ... FOR UPDATE las filas de lotes_inventario en orden FEFO.
     * - Inserta el encabezado en dispensaciones y los renglones en dispensacion_detalle.
     * - Los Triggers trg_before_insert_dispensacion y trg_after_insert_dispensacion validan
     *   caducidad y saldo, descuentan existencias, actualizan estado_receta e inscriben en movimientos_kardex.
     */
    public function dispensarRecetaFefo(int $idReceta, string $notasDespacho, int $idUsuarioDespacha): array
    {
        if ($idReceta <= 0) {
            return ['ok' => false, 'mensaje' => 'Debe indicar una receta válida para dispensar.'];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Bloquear receta
            $stmtRec = $pdo->prepare(
                "SELECT id_receta, folio_receta, estado_receta
                 FROM recetas_medicas
                 WHERE id_receta = :id
                 FOR UPDATE"
            );
            $stmtRec->execute([':id' => $idReceta]);
            $receta = $stmtRec->fetch();

            if (!$receta) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'La receta médica no existe.'];
            }

            if (!in_array($receta['estado_receta'], ['PENDIENTE_SURTIR', 'SURTIDA_PARCIAL'], true)) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => "La receta {$receta['folio_receta']} se encuentra en estado [{$receta['estado_receta']}] y no admite nuevos despachos.",
                ];
            }

            // Obtener renglones con saldo pendiente de surtir
            $stmtDet = $pdo->prepare(
                "SELECT rd.id_receta_detalle, rd.id_medicamento, rd.cantidad_prescrita, rd.cantidad_surtida,
                        (rd.cantidad_prescrita - rd.cantidad_surtida) AS pendiente,
                        m.nombre_generico
                 FROM receta_detalle rd
                 INNER JOIN medicamentos m ON m.id_medicamento = rd.id_medicamento
                 WHERE rd.id_receta = :id_rec
                   AND rd.cantidad_surtida < rd.cantidad_prescrita
                 FOR UPDATE"
            );
            $stmtDet->execute([':id_rec' => $idReceta]);
            $renglonesPendientes = $stmtDet->fetchAll();

            if (empty($renglonesPendientes)) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'Todos los medicamentos de esta receta ya fueron surtidos en su totalidad.'];
            }

            // Insertar encabezado de dispensación (el Trigger trg_before_insert_dispensacion_cabecera valida RN-CLI-06)
            $stmtInsDisp = $pdo->prepare(
                "INSERT INTO dispensaciones (id_receta, id_usuario_despacha, notas_despacho)
                 VALUES (:id_rec, :uid, :notas)"
            );
            $stmtInsDisp->execute([
                ':id_rec' => $idReceta,
                ':uid'    => $idUsuarioDespacha,
                ':notas'  => trim($notasDespacho) !== '' ? trim($notasDespacho) : 'Despacho automático en ventanilla bajo regla FEFO',
            ]);
            $idDispensacion = (int)$pdo->lastInsertId();

            // Consulta de bloqueo pesimista FEFO (RBD-INT-02 + RF-FAR-02)
            $stmtLotesFefo = $pdo->prepare(
                "SELECT id_lote, numero_lote, cantidad_actual, fecha_caducidad
                 FROM lotes_inventario
                 WHERE id_medicamento = :id_med
                   AND estado_lote = 'ACTIVO'
                   AND fecha_caducidad > CURDATE()
                   AND cantidad_actual > 0
                 ORDER BY fecha_caducidad ASC, id_lote ASC
                 FOR UPDATE"
            );

            $stmtInsDispDet = $pdo->prepare(
                "INSERT INTO dispensacion_detalle (id_dispensacion, id_receta_detalle, id_lote, cantidad_dispensada)
                 VALUES (:id_disp, :id_recdet, :id_lote, :cant)"
            );

            $totalUnidadesDespachadas = 0;
            $resumenLotes = [];

            foreach ($renglonesPendientes as $renglon) {
                $porSurtir = (int)$renglon['pendiente'];
                $idMed = (int)$renglon['id_medicamento'];
                $idRecDet = (int)$renglon['id_receta_detalle'];

                $stmtLotesFefo->execute([':id_med' => $idMed]);
                $lotesDisponibles = $stmtLotesFefo->fetchAll();

                foreach ($lotesDisponibles as $lote) {
                    if ($porSurtir <= 0) {
                        break;
                    }
                    $saldoLote = (int)$lote['cantidad_actual'];
                    $despacharDeEsteLote = min($porSurtir, $saldoLote);

                    if ($despacharDeEsteLote > 0) {
                        // Al ejecutar este INSERT se disparan trg_before_insert_dispensacion (RBD-INT-03)
                        // y trg_after_insert_dispensacion (RBD-INT-04)
                        $stmtInsDispDet->execute([
                            ':id_disp'   => $idDispensacion,
                            ':id_recdet' => $idRecDet,
                            ':id_lote'   => (int)$lote['id_lote'],
                            ':cant'      => $despacharDeEsteLote,
                        ]);

                        $porSurtir -= $despacharDeEsteLote;
                        $totalUnidadesDespachadas += $despacharDeEsteLote;
                        $resumenLotes[] = "{$renglon['nombre_generico']}: {$despacharDeEsteLote} u. (Lote {$lote['numero_lote']} vence {$lote['fecha_caducidad']})";
                    }
                }
            }

            if ($totalUnidadesDespachadas === 0) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => 'ERR_FAR_STOCK_INSUFICIENTE: No existen lotes vigentes no caducados con saldo disponible para surtir esta receta.',
                ];
            }

            $pdo->commit();

            return [
                'ok'              => true,
                'id_dispensacion' => $idDispensacion,
                'mensaje'         => "Dispensación #{$idDispensacion} ejecutada bajo regla FEFO para la receta {$receta['folio_receta']}. Detalle: " . implode('; ', $resumenLotes) . ". Movimientos registrados en Kárdex.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok'      => false,
                'mensaje' => 'Error transaccional en dispensación: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * RBD-INT-04 y RBD-INT-05: Consulta el historial inmutable de movimientos_kardex.
     */
    public function listarMovimientosKardex(int $limite = 60): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT k.id_movimiento, k.tipo_movimiento, k.cantidad_previa, k.cantidad_movimiento,
                    k.saldo_resultante, k.referencia_receta, k.fecha_movimiento,
                    li.numero_lote, li.fecha_caducidad,
                    m.codigo_sku, m.nombre_generico, m.concentracion,
                    u.username AS usuario_ejecutor
             FROM movimientos_kardex k
             INNER JOIN lotes_inventario li ON li.id_lote = k.id_lote
             INNER JOIN medicamentos m ON m.id_medicamento = k.id_medicamento
             LEFT JOIN usuarios u ON u.id_usuario = k.id_usuario_ejecutor
             ORDER BY k.id_movimiento DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
