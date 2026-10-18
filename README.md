# MediCore Systems 

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat&logo=docker&logoColor=white)](https://www.docker.com/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=flat&logo=bootstrap&logoColor=white)](https://getbootstrap.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**MediCore Systems** es una plataforma integral de gestión hospitalaria y registro de **Historial Clínico Electrónico (EHR / HCE)** diseñada bajo estándares de alta seguridad, normalización estricta (3FN/BCNF) y defensa en profundidad mediante base de datos relacional y arquitectura PHP MVC moderna.

Cumple con las directrices de la especificación técnica **`ERS-MCS-DB-2026-V1.0`**.

---

## Características Principales

### 1. Seguridad y Control de Acceso Basado en Roles (RBAC)
- **Roles segregados:** Administrador TI, Personal Médico y Recepción/Admisión.
- **Protección de datos:** Hashing de contraseñas con **Bcrypt (cost=12)**.
- **Segregación de funciones:** El personal administrativo no tiene acceso a notas clínicas confidenciales; los médicos no pueden despachar farmacia ni atenderse a sí mismos (*RN-CLI-06*).
- **Protección Web:** Tokens anti-CSRF criptográficos con `random_bytes(32)`, sanitización XSS contextual y sentencias preparadas nativas con PDO (emulación deshabilitada).

### 2. Gestión de Pacientes (`PAC`)
- Identificador médico estandarizado **MRN** (`MCS-YYYY-XXXXXX`).
- Catálogo y control estricto de **alergias a medicamentos** con niveles de severidad (Leve, Moderada, Anafilaxia Severa).
- Registro de contactos de emergencia y trazabilidad demográfica.

### 3. Agenda y Control de Citas Médicas (`CIT`)
- Bloqueo en tiempo real de **empalmes de horarios** (`RN-CLI-01`) validado por base de datos (Triggers y `SELECT ... FOR UPDATE` para evitar condiciones de carrera).
- Programación por especialidad, médico y consultorio disponible.

### 4. Historial Clínico Electrónico (`HIS`)
- Registro de signos vitales con cálculo automático de IMC.
- Diagnósticos codificados bajo catálogo **CIE-10**.
- **Inmutabilidad clínica (`RN-CLI-03`):** Una vez cerrada la consulta, la nota clínica, diagnósticos y recetas quedan bloqueados contra edición o borrado. Cualquier aclaración posterior requiere **adendas clínicas fechadas**.
- Alerta y bloqueo automático de prescripciones si el paciente presenta contraindicación por alergia severa (`RN-CLI-05`).

### 5. Farmacia e Inventario FEFO (`FAR`)
- Algoritmo de despacho **FEFO** (*First Expired, First Out*): despacha primero el lote con fecha de caducidad más próxima.
- Bloqueo de lotes vencidos o con stock insuficiente mediante triggers en base de datos.
- Registro inmutable de transacciones en **Kárdex** para auditoría forense de entradas, salidas y mermas.

---

## Stack Tecnológico

| Capa | Tecnología | Descripción |
| :--- | :--- | :--- |
| **Backend** | PHP 8.2+ (Apache) | Arquitectura MVC ligera, **PDO nativo puro** (cero ORMs), tipado estricto. |
| **Base de Datos** | MySQL 8.0 | Motor InnoDB, UTF-8 (`utf8mb4_unicode_ci`), Triggers de auditoría y reglas clínicas. |
| **Frontend** | HTML5, CSS3, JS Vanilla | Bootstrap 5, Iconos SVG/Bootstrap Icons, Fetch API asíncrona. |
| **Infraestructura** | Docker & Docker Compose | Contenedores orquestados para entorno web y base de datos. |

---


## Instalación y Puesta en Marcha con Docker

### Prerrequisitos
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) instalado y en ejecución.
- Git instalado.

### 1. Clonar el repositorio
```bash
git clone https://github.com/TU_USUARIO/medicore-systems.git
cd medicore-systems
```

### 2. Levantar el entorno
Ejecuta el siguiente comando en la raíz del proyecto:
```bash
docker-compose up -d --build
```
> El contenedor de MySQL ejecutará automáticamente los scripts de inicialización (`01_init.sql`, `02_triggers.sql`, `03_seeders.sql`) cargando la estructura, triggers y datos de prueba.

### 3. Acceder al sistema
Abre tu navegador e ingresa a:
**[http://localhost:8080](http://localhost:8080)**

---

## Credenciales de Prueba (Seeders)

El sistema incluye usuarios preconfigurados para evaluar los diferentes roles y permisos:

| Rol | Usuario | Contraseña | Acceso / Funcionalidad |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin` | `Admin2026*` | Gestión de usuarios, catálogos, lotes de farmacia y bitácora de auditoría. |
| **Médico** | `dr.mendoza` | `Medico2026*` | Consulta externa (OPD), registro de signos vitales, diagnóstico CIE-10 y recetas. |
| **Médico** | `dra.salazar` | `Medico2026*` | Consulta clínica y prescripción electrónica. |
| **Recepcionista** | `recepcion` | `Recep2026*` | Admisión de pacientes, agendamiento de citas y dispensación en farmacia. |

---

## Estructura del Proyecto

```text
medicore-systems/
├── docker/
│   └── php/
│       └── Dockerfile              # Imagen PHP 8.2 Apache con extensiones PDO MySQL
├── Documentacion/
│   └── ERS-MCS-DB-2026-V1.0.md     # Especificación de Requisitos y Arquitectura
├── sql/
│   ├── 01_init.sql                 # Esquema relacional DDL (3FN/BCNF)
│   ├── 02_triggers.sql             # Triggers de reglas clínicas, FEFO y auditoría
│   └── 03_seeders.sql              # Catálogos base (CIE-10, roles, usuarios, lotes)
├── src/
│   ├── api/                        # Endpoints JSON para consumo asíncrono
│   ├── assets/                     # Hojas de estilo CSS y scripts JS vanilla
│   ├── config/                     # Conexión PDO Singleton (Database.php)
│   ├── controllers/                # Controladores MVC
│   ├── core/                       # Middlewares de autenticación, CSRF y Bootstrap
│   ├── views/                      # Plantillas y layouts reutilizables
│   └── index.php                   # Enrutamiento principal y vistas
├── .gitattributes                  # Normalización de saltos de línea (CRLF/LF)
├── .gitignore                      # Exclusión de archivos confidenciales y logs
├── docker-compose.yml              # Orquestación de servicios Web y MySQL
└── README.md                       # Documentación principal del repositorio
```

---

## Principios de Seguridad y Cumplimiento
- **Inmutabilidad:** Las tablas críticas de kárdex y bitácora prohíben operaciones `UPDATE` y `DELETE`.
- **Integridad Referencial:** Restricción estricta de borrado (`ON DELETE RESTRICT`) para evitar pérdida accidental de registros médicos.
- **Trazabilidad:** Disparadores automáticos registran cualquier cambio de datos (`OLD` vs `NEW`) en formato JSON dentro de `bitacora_auditoria`.
