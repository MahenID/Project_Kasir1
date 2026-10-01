# Project Kasir (Point of Sale)

Sistem Kasir modern berbasis web dengan arsitektur frontend dan backend terpisah.

## Struktur Project

- **`frontend/`**: Aplikasi frontend berbasis [Next.js](https://nextjs.org/) (React, TypeScript, Tailwind CSS).
- **`backend/`**: RESTful API backend berbasis [Laravel](https://laravel.com/) (PHP, MySQL).
- **`projectkasir_old/`**: Versi aplikasi lama (PHP Native & database dump SQL).

## Menjalankan Project

### Backend (Laravel)
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend (Next.js)
```bash
cd frontend
npm install
npm run dev
```
