<div align="center">
  <br />
  <a href="https://github.com/Arden28/aures-client">
    <img src="public/images/logo.png" alt="Tapla Logo" width="150" />
  </a>
  <br />

  <h1 align="center">Tapla POS</h1>

  <p align="center">
    <strong>The Next-Gen Restaurant Management Operating System.</strong>
    <br />
    Seamlessly connecting Waiters, Kitchens, and Cashiers in real-time.
  </p>

  <p align="center">
    <a href="https://reactjs.org/">
      <img src="https://img.shields.io/badge/React-20232A?style=for-the-badge&logo=react&logoColor=61DAFB" alt="React" />
    </a>
    <a href="https://www.typescriptlang.org/">
      <img src="https://img.shields.io/badge/TypeScript-007ACC?style=for-the-badge&logo=typescript&logoColor=white" alt="TypeScript" />
    </a>
    <a href="https://tailwindcss.com/">
      <img src="https://img.shields.io/badge/Tailwind_CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white" alt="Tailwind CSS" />
    </a>
    <a href="https://vitejs.dev/">
      <img src="https://img.shields.io/badge/Vite-B73C92?style=for-the-badge&logo=vite&logoColor=white" alt="Vite" />
    </a>
    <a href="https://laravel.com/">
      <img src="https://img.shields.io/badge/Laravel_12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel" />
    </a>
    <a href="https://pusher.com/">
      <img src="https://img.shields.io/badge/Pusher-Realtime-20c997?style=for-the-badge&logo=pusher&logoColor=white" alt="Pusher" />
    </a>
  </p>
</div>

<br />

## ⚡ Overview

**Tapla** is a high-performance Single Page Application (SPA) designed to modernize restaurant operations. Unlike traditional bulky POS systems, Tapla is role-based, reactive, and mobile-first.

It is divided into three distinct synchronized interfaces:

1.  **🤵 Waiter Mode:** A mobile-optimized interface for taking orders, managing tables, and receiving notifications when food is ready.
2.  **👨‍🍳 Kitchen Display System (KDS):** A digital Kanban board for chefs to view, track, and bump orders via drag-and-drop or touch interactions.
3.  **💸 Cashier Station:** A streamlined dashboard for processing payments, splitting bills, and viewing daily sales statistics.

---

## 📸 Interface Previews

| **Waiter Mobile View** | **Kitchen Display System** |
|:---:|:---:|
| <img src="https://placehold.co/300x600/18181b/FFF?text=Waiter+UI" alt="Waiter UI" width="250" /> | <img src="https://placehold.co/600x400/18181b/FFF?text=KDS+Board" alt="KDS UI" width="400" /> |

---

## ✨ Key Features

* **🖥️ Point of Sale (POS):** Interactive interface for waiters and cashiers to manage orders and payments.
* **🗺️ Live Floor Plan:** Drag-and-drop table management with real-time status indicators (Occupied, Free, Needs Cleaning).
* **👨‍🍳 Kitchen Display System (KDS):** Real-time order pipeline for kitchen staff to track prep times and statuses.
* **📱 QR Customer Portal:** Progressive Web App (PWA) allowing customers to scan tables and order directly.
* **📊 Executive Dashboard:** Real-time analytics, revenue tracking, and staff performance metrics with auto-polling.
* **⚡ Real-time Sync:** Instant updates across all devices using WebSockets (Pusher).

### 🤵 Waiter Interface
-   **Live Task Feed:** Real-time push notifications and audio alerts for new table assignments and "Order Ready" signals.
-   **Table Management:** Visual floor plan to claim tables and view occupancy.
-   **Offline Detection:** Smart handling of network drops with auto-reconnection polling.
-   **Optimistic UI:** Instant UI updates while data syncs in the background.

### 🍳 Kitchen Display (KDS)
-   **Kanban Workflow:** Pending -> Cooking -> Ready -> Served columns.
-   **Drag & Drop:** Intuitive touch-enabled drag system to move tickets.
-   **Ticket Timer:** Color-coded timers (Green/Orange/Red) to highlight delayed orders.
-   **Sound Alerts:** Audio cues when new tickets arrive in the kitchen.

### 💸 Cashier
-   **Unpaid Orders Feed:** Live list of tables waiting to settle their bill.
-   **Daily Stats:** Real-time calculation of Total Sales and Completed Orders.
-   **Quick Actions:** One-tap payment processing and receipt viewing.

---

## 🛠️ Tech Stack

### Frontend (Client)
* **Framework:** React 18 + TypeScript
* **Build Tool:** Vite
* **Styling:** TailwindCSS + Shadcn/UI
* **Icons:** [Lucide React](https://lucide.dev/)
* **State/Data:** TanStack Query (React Query)
* **Animations:** Framer Motion
* **Charts:** Recharts
* **Drag & Drop:** Mobile-drag-drop (Polyfill for touch devices)
* **Real-time:** [Laravel Echo](https://github.com/laravel/echo) + [Pusher JS](https://github.com/pusher/pusher-js)

### Backend (Server)
* **Framework:** Laravel 12 (PHP)
* **Database:** MySQL 8.0+
* **Cache / Queue:** Redis
* **Authentication:** Laravel Sanctum (SPA Auth)
* **Broadcasting:** Pusher Channels (WebSockets)
* **API:** RESTful JSON API

---

## ⚙️ Installation & Setup

### Prerequisites
* Node.js (v18+)
* PHP (v8.2+) & Composer
* MySQL Server
* A free [Pusher](https://pusher.com/) account (or a local Laravel Reverb setup)

### 1. Backend Setup (Laravel)

```bash
# Clone the repository
git clone [https://github.com/Arden28/aures-api.git](https://github.com/Arden28/aures-api.git)
cd tapla-backend

# Install dependencies
composer install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database & Migration
# (Ensure you created a database named 'tapla' in MySQL)
php artisan migrate --seed

# Link Storage
php artisan storage:link

# Start Server
php artisan serve

```


### 2. Frontend Setup (React)

```bash
# Clone the repository
git clone https://github.com/Arden28/aures-client.git
cd tapla-frontend

# Install dependencies
npm install

# Environment setup
# Create a .env file based on the example below
touch .env

# Start Dev Server
npm run dev
```

---

## ⚙️ Configuration

### Backend `.env`

Configure your database and Pusher credentials here.

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tapla
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=pusher

PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_APP_CLUSTER=mt1

# Allow frontend requests (CORS)
FRONTEND_URL=http://localhost:5173
```

---

### Frontend `.env`

Create this file in the root of your React project.

```ini
# Base URL for the Laravel API
VITE_API_BASE_URL=http://localhost:8000/api

# Pusher Configuration (Must match backend)
VITE_PUSHER_APP_KEY=your_app_key
VITE_PUSHER_CLUSTER=mt1
```

---

## 🌍 Deployment Guide

Since the frontend and backend are decoupled, they should be deployed as separate services.

---

### Part A: Backend Deployment (VPS / DigitalOcean / AWS)

* **Server:** Ubuntu 22.04 with Nginx, PHP 8.2, and MySQL
* **Pull Code:** Clone the backend repo to `/var/www/tapla-api`
* **Install:**

```bash
composer install --no-dev --optimize-autoloader
```

* **Permissions:**

```bash
chmod -R 775 storage bootstrap/cache
```

* **Nginx:** Point the root to `/public`
* **Queue Worker:** Use Supervisor for real-time events

```ini
[program:tapla-worker]
command=php /var/www/tapla-api/artisan queue:work --tries=3
autostart=true
autorestart=true
user=www-data
```

* **SSL:** Secure API using Let's Encrypt (Certbot)

---

### Part B: Frontend Deployment (Vercel / Netlify / Static)

```bash
npm run build
```

**Vercel (Recommended):**

* Connect GitHub repo
* Build Command: `npm run build`
* Output Directory: `dist`
* Env Vars: `VITE_API_BASE_URL=https://api.tapla.com/api`

**SPA Routing (Nginx):**

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

---

### Part C: Connecting Them (CORS)

Ensure allowed origins include your frontend domain.

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['https://app.tapla.com', 'http://localhost:5173'],
```

---

## 📡 WebSocket Architecture

1. **Trigger:** Laravel fires an event (e.g., `OrderCreated`)
2. **Broadcast:** Payload is sent to Pusher
3. **Listen:** React subscribes via `laravel-echo`
4. **Sync:** UI updates instantly via React Query cache invalidation

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch

```bash
git checkout -b feature/AmazingFeature
```

3. Commit your changes
4. Push to the branch
5. Open a Pull Request

---

## 📄 License

Distributed under the MIT License. See `LICENSE` for more information.

<br /> <div align="center"> <p>Built by the [Arden BOUET](https://github.com/Arden28)</p> </div>
