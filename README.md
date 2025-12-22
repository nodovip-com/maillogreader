# 🦅 Mail Log Reader Pro

> **Una interfaz moderna, elegante y en tiempo real para analizar logs de correo (Postfix/Syslog).**

Mail Log Reader Pro transforma archivos de logs crudos y difíciles de leer en un dashboard interactivo, visual y potente. Diseñado para administradores de sistemas que necesitan monitorear el flujo de correos con estilo y eficiencia.

---

## ✨ Características Principales

*   **🎨 Diseño Premium "Liquid Glass"**: Interfaz oscura moderna con efectos de desenfoque y transparencias.
*   **⏱️ Monitoreo en Tiempo Real**: Actualización automática de logs sin recargar la página (Polling silencioso).
*   **🔍 Búsqueda Inteligente**:
    *   Filtrado instantáneo por Remitente, Destinatario o Contenido.
    *   **Traza de Mensajes**: Al buscar por `Queue ID`, visualiza gráficamente el flujo `FROM -> TO`.
*   **🌍 Geolocalización de IPs**: Detecta automáticamente el país y muestra la bandera correspondiente para las IPs en los logs.
*   **📂 Vista Detallada**: Expande cualquier log para ver el mensaje crudo vs. analizado y metadatos extendidos.
*   **🛡️ Gestión de Usuarios**:
    *   Sistema de autenticación simple y seguro.
    *   Gestión de contraseñas integrada.
    *   Almacenamiento de usuarios en JSON (sin base de datos SQL).

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
    
    # Copiar base de datos de usuarios
    cp users.sample.json users.json
    ```

3.  **Editar `config.php`**
    Abre `config.php` y ajusta la ruta a tu archivo de logs:
    ```php
    define('LOG_FILE_PATH', '/var/log/mail.log'); // Ruta absoluta a tu log
    ```

4.  **Permisos (¡Importante!)**
    El servidor web (www-data/apache/nginx) necesita permisos para:
    *   **Leer** el archivo de logs definido en `config.php`.
    *   **Escribir** en `users.json` (para cambiar contraseñas).

    ```bash
    # Ejemplo de permisos (ajustar según tu entorno)
    chown www-data:www-data users.json
    chmod 660 users.json
    chmod +r /var/log/mail.log
    ```

---

## 📖 Cómo Funciona

### 1. Dashboard Principal
Al acceder, verás los logs más recientes.
*   **Colores de Estado**:
    *   🟢 **Sent**: Enviado correctamente.
    *   🔴 **Bounced/Error**: Error en el envío.
    *   🟡 **Deferred**: Temporalmente retrasado.
    *   🔵 **Info**: Información general del sistema.

### 2. Filtrado y Búsqueda
Usa la barra superior para buscar cualquier texto.
*   **Tip Pro**: Pega un `Queue ID` (ej: `34F2A600Z`) para aislar automáticamente toda la traza de ese correo específico. Aparecerá un resumen de la trayectoria en la parte superior.

### 3. Detalles Técnicos
Haz clic en cualquier fila para desplegar los detalles.
*   Las **Direcciones IP** y **Emails** se resaltan automáticamente.
*   Pasa el mouse sobre las banderas para ver el nombre del país.

### 4. Cambio de Contraseña
Desde el menú de usuario (esquina superior derecha), puedes actualizar tu contraseña de forma segura.

---

## 🛠️ Requisitos Técnicos

*   **PHP**: 7.4 o superior.
*   **Servidor Web**: Apache, Nginx o IIS.
*   **Navegador**: Cualquiera moderno (Chrome, Edge, Firefox).
*   **Dependencias**: Ninguna (No requiere Composer ni Node.js para correr). Uses Vanilla JS/CSS.

---

<p align="center">
  <sub>Desarrollado por NodoVIP</sub>
</p>
