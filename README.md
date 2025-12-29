# 🦅 Mail Log Reader Pro

> **Una interfaz moderna, elegante y en tiempo real para analizar logs de correo (Postfix/Syslog & Rspamd).**

Mail Log Reader Pro transforma archivos de logs crudos y difíciles de leer en un dashboard interactivo, visual y potente. Diseñado para administradores de sistemas que necesitan monitorear el flujo de correos con estilo y eficiencia.

---

## ✨ Características Principales

*   **🎨 Diseño Premium "Liquid Glass"**: Interfaz oscura moderna con efectos de desenfoque y transparencias.
*   **📂 Multi-Motor de Logs**:
    *   **Syslog Universal**: Compatible con logs estándar de Postfix/Sendmail.
    *   **⚡ Rspamd Integration**: Soporte nativo para `rspamd_history_json`. Visualiza **Scores**, **Acciones** y **Símbolos** con indicadores de toxicidad codificados por color.
*   **⏱️ Monitoreo en Tiempo Real**: Actualización automática de logs sin recargar la página (Polling silencioso).
*   **🔍 Búsqueda Inteligente**:
    *   Filtrado instantáneo por Remitente, Destinatario o Contenido.
    *   **Traza de Mensajes**: Al buscar por `Queue ID`, visualiza gráficamente el flujo `FROM -> TO`.
*   **🌍 Geolocalización de IPs**: Detecta automáticamente el país y muestra la bandera correspondiente para las IPs en los logs.
*   **⚙️ Configuración Dinámica**: Cambia fácilmente entre tipos de log y rutas de archivo desde la interfaz gráfica, sin editar código.
*   **🛡️ Gestión Integral de Usuarios**:
    *   **Modo Setup Automático**: Creación guiada del primer administrador si no existen usuarios.
    *   **Panel de Administración**: Añade y elimina usuarios directamente desde la interfaz.
    *   Almacenamiento seguro en JSON (sin base de datos SQL).

---

## 🚀 Instalación y Configuración

Sigue estos pasos para desplegar el proyecto en tu servidor:

1.  **Clonar el Repositorio**
    ```bash
    git clone https://tu-repo/maillogreader.git
    cd maillogreader
    ```

2.  **Configurar Archivos Base**
    El proyecto incluye archivos de ejemplo. Debes crear tus archivos de configuración locales:

    ```bash
    # Copiar configuración de ejemplo
    cp config.sample.php config.php
    ```

    *Nota 1: `users.json` es opcional al inicio. Si no existe, la aplicación entrará en **Modo Setup** y te pedirá crear el primer usuario al abrirla en el navegador.*
    
    *Nota 2: `settings.json` se creará automáticamente cuando guardes la configuración desde la UI.*

3.  **Permisos (¡Importante!)**
    El servidor web (www-data/apache/nginx) necesita permisos para:
    *   **Leer** los archivos de logs que configures.
    *   **Escribir** en el directorio (para crear/actualizar `users.json` y `settings.json`).

    ```bash
    # Ejemplo de permisos (ajustar según tu entorno)
    chown www-data:www-data .
    chmod 770 .
    ```

---

## 📖 Cómo Funciona

### 1. Dashboard Principal
Al acceder, verás los logs más recientes.
*   **Modo Syslog**: Muestra Timestamp, Status (Sent/Deferred/Error), Componente y Mensaje.
*   **Modo Rspamd**: Muestra Score, Action (Reject/No Action), Subject y Símbolos de Spam.

### 2. Panel de Configuración
Desde el menú de usuario (arriba a la derecha), accede a **Configuración**:
*   Selecciona el tipo de log (`Standard Mail Log` o `Rspamd History`).
*   Define la ruta absoluta al archivo (ej: `/var/log/rspamd/history.json`).

### 3. Gestión de Usuarios
Desde el menú de usuario, accede a **Usuarios**:
*   Visualiza todos los usuarios con acceso.
*   Añade nuevos administradores o elimina los existentes.

### 4. Detalles Avanzados
Haz clic en cualquier fila para desplegar:
*   **IPs Enriquecidas**: Banderas de países automáticas.
*   **Traza de ID**: Flujo visual de mensajes.
*   **Explorador de Símbolos (Rspamd)**: Píldoras de colores (Rojo=Spam, Verde=Ham) con descripciones al pasar el mouse.

---

## 🛠️ Requisitos Técnicos

*   **PHP**: 7.4 o superior.
*   **Servidor Web**: Apache, Nginx o IIS.
*   **Navegador**: Cualquiera moderno (Chrome, Edge, Firefox).
*   **Dependencias**: Ninguna (Uses Vanilla JS/CSS).

---

<p align="center">
  <sub>Desarrollado por NodoVIP</sub>
</p>
