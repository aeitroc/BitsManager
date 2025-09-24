# BitsManager - Simple PHP File Browser

BitsManager is a web-based project designed to help you manage and organize your digital assets efficiently.

## Features
- Simple and intuitive interface
- Easy asset management
- Fast setup and deployment

## Getting Started
1. Clone the repository:
   ```bash
   git clone https://github.com/aeitroc/BitsManager.git
   ```
2. Open the project folder:
   ```bash
   cd BitsManager
   ```
3. Start your local server and open `index.php` in your browser.

## Requirements
- PHP 7.4 or higher
- A web server (e.g., Apache, Nginx)

## Security Hardening (September 2025)
- Uploads are restricted to a safe allowlist (`txt`, `pdf`, `png`, `jpg`, `jpeg`, `gif`, `csv`, `zip`, `tar`, `gz`) and capped at 10&nbsp;MB per file. Update the `ALLOWED_UPLOAD_EXTENSIONS` and `MAX_UPLOAD_BYTES` constants in `index.php` to adjust policy.
- CSRF protection now guards all login and mutating form posts. Sessions ship with Secure, HttpOnly, and SameSite cookie flags, and repeated failed logins trigger a 15-minute cooldown.
- A `.setup_lock` file is written after initial configuration; if `config.php` disappears, the app will refuse to auto-bootstrap. Restore `config.php` (or remove both files intentionally) to run setup again.
- Operational events (logins, uploads, deletes, path violations) are recorded in `logs/app.log`. Protect this directory at the web server level or relocate it outside the web root.

## Operational Notes
- Keep `config.php`, `.setup_lock`, and `logs/` out of backups that sync to public storage.
- For production deployments, serve the app strictly over HTTPS so HSTS and secure cookies remain effective.

## Contributing
Contributions are welcome! Please open issues or submit pull requests for improvements.

## License
This project is licensed under the MIT License.
