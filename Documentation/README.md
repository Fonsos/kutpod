# KutPod

<p align="center">
  <strong>🇪🇸 <a href="#español">Español</a></strong> · <strong>🇬🇧 <a href="#english">English</a></strong>
</p>

---

## Español

KutPod es una plataforma de alojamiento web y gestión de podcasts construida en **PHP puro y SQLite**, sin dependencias ni frameworks pesados. Sirve como el núcleo (backend y frontend) de la suite **KutStudio**.

### Características Principales

* **Independiente**: Sin Node.js, sin Composer, sin base de datos pesada. Funciona en cualquier servidor web estándar (Nginx/Apache) que soporte PHP y SQLite.
* **Integración nativa con el Fediverso (ActivityPub)**: KutPod actúa como una instancia compatible con Mastodon. Los usuarios del Fediverso pueden buscar, seguir e interactuar con tus podcasts directamente (ej. `@mipodcast@tudominio.com`).
* **Estadísticas Nativas con OP3**: Rastreo de descargas sin depender de servicios de terceros gracias a la compatibilidad integrada con Open Podcast Prefix Project.
* **Diseño Moderno y Responsivo**: Tema personalizable y reproductor global ininterrumpido en el frontend web público.
* **Gestión de Permisos**: Soporte nativo para administrar dueños, editores, y autores para múltiples podcasts dentro de la misma instancia.
* **API RESTful**: Interfaz de API preparada para conectarse con los clientes de escritorio como **KutEditor**, **KutFast** y **KutFast_Linux**.

### Estructura del Proyecto

* `/api/`: Endpoints REST consumidos por los clientes de la suite.
* `/cli/`: Herramientas y demonios de línea de comandos (Worker de Fediverso, Worker de Backups, Importadores).
* `/includes/`: Lógica de negocio principal, manejo de base de datos y helpers de la interfaz.
* `/pages/`: Panel de administración (Dashboard).
* `/public/`: Frontend de los sitios públicos de los podcasts y portal del reproductor de episodios.
* `/storage/`: Base de datos SQLite y archivos de metadatos seguros.
* `/media/`: Almacenamiento multimedia para audios de episodios, portadas y banners.

### Instalación

KutPod se instala fácilmente copiando el contenido en el directorio de tu servidor web, y asegurando los permisos. Una vez desplegado, el asistente de instalación web te guiará en la creación del primer usuario administrador.

*Es crítico seguir las directrices de la guía de hardening (`security_guide.md`) para asegurar las carpetas `/storage` y `/media`.*

### Suite KutStudio

KutPod está diseñado para integrarse con:
* **KutEditor**: Editor de audio y generador de episodios (Qt/C++).
* **KutFast**: Publicador web ligero para crear episodios al instante.
* **KutFast_Linux**: Cliente nativo para escritorio Linux enfocado en publicación ágil.

---

## English

KutPod is a web-hosting and podcast management platform built with **pure PHP and SQLite**, with no heavy dependencies or frameworks. It serves as the core (backend and frontend) of the **KutStudio** suite.

### Key Features

* **Self-contained**: No Node.js, no Composer, no heavyweight database. Runs on any standard web server (Nginx/Apache) with PHP and SQLite support.
* **Native Fediverse Integration (ActivityPub)**: KutPod acts as a Mastodon-compatible instance. Fediverse users can search, follow, and interact with your podcasts directly (e.g. `@mypodcast@yourdomain.com`).
* **Native Analytics with OP3**: Download tracking without relying on third-party services, thanks to built-in Open Podcast Prefix Project compatibility.
* **Modern & Responsive Design**: Customizable theme and uninterrupted global player on the public web frontend.
* **Permission Management**: Native support for managing owners, editors, and authors across multiple podcasts within the same instance.
* **RESTful API**: API interface ready to connect with desktop clients such as **KutEditor**, **KutFast**, and **KutFast_Linux**.

### Project Structure

* `/api/`: REST endpoints consumed by suite clients.
* `/cli/`: Command-line tools and daemons (Fediverse Worker, Backup Worker, Importers).
* `/includes/`: Core business logic, database handling, and UI helpers.
* `/pages/`: Administration panel (Dashboard).
* `/public/`: Public podcast site frontend and episode player portal.
* `/storage/`: SQLite database and secure metadata files.
* `/media/`: Media storage for episode audio, covers, and banners.

### Installation

KutPod is easily installed by copying the contents into your web server directory and setting the proper permissions. Once deployed, the web installation wizard will guide you through creating your first admin user.

*It is critical to follow the hardening guide (`security_guide.md`) to secure the `/storage` and `/media` directories.*

### KutStudio Suite

KutPod is designed to integrate with:
* **KutEditor**: Audio editor and episode generator (Qt/C++).
* **KutFast**: Lightweight web publisher for instant episode creation.
* **KutFast_Linux**: Native Linux desktop client focused on agile publishing.

---

## 💖 Donaciones / Donations

Si KutPod te es útil en tu flujo de trabajo como podcaster, considera apoyar el desarrollo con una donación. ¡Cada aporte ayuda a mantener el proyecto vivo y en constante mejora!

If KutPod is useful in your podcasting workflow, consider supporting development with a donation. Every contribution helps keep the project alive and constantly improving!

<p align="center">

  <a href="https://paypal.me/elav">
    <img src="https://img.shields.io/badge/PayPal-Donar%20%2F%20Donate-00457C?style=for-the-badge&logo=paypal&logoColor=white" alt="PayPal">
  </a>
  &nbsp;&nbsp;
  <a href="https://www.buymeacoffee.com/ernestoacostame">
    <img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Invítame%20un%20café-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee">
  </a>
  &nbsp;&nbsp;
  <a href="https://ko-fi.com/ernestoacostame">
    <img src="https://img.shields.io/badge/Ko--fi-Apóyame-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white" alt="Ko-fi">
  </a>

</p>