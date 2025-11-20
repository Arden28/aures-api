🍽️ Restaurant Management System (RMS) – Backend API

---

## 📖 Project Overview

This is the core **backend API** for the Restaurant Management System (RMS).  
It is built as a **headless, API-first** application using **Laravel 12**.

The backend acts as the **central nervous system** for all roles in the restaurant:

- **Owners** – business intelligence, configuration, and control  
- **Staff (Managers/Hosts)** – oversight of tables and operations  
- **Waiters** – table & order handling  
- **Kitchen** – real-time Kitchen Display System (KDS)  
- **Cashiers** – billing and payments  
- **Clients (Guests)** – QR-code based menu, self-ordering, and live tracking  

The goal is to provide a **modular, scalable, real-time** foundation that can power web, mobile, and tablet clients.

---

## 🏗️ Architecture

The project follows a **Modular Monolith** approach with a clear, domain-based structure.  
Everything lives in a single codebase and deployment unit, but responsibilities are split by **business domain**:

- `Users & Auth`
- `Restaurants & Tables`
- `Menu & Products`
- `Orders & KDS`
- `Billing & Payments`
- `Clients & Reviews`
- `Dashboards & Analytics`

This keeps:

- **Development simple** (no microservice overhead)  
- **Boundaries clear** (each domain has its own models, services, controllers)  
- **Scaling possible later** (domains can be extracted if needed)

---

## 🧰 Tech Stack

- **Framework:** Laravel **12.x**
- **Language:** PHP **8.3+**
- **Database:** PostgreSQL / MySQL 8.0+
- **Cache / Queue:** Redis
- **Real-Time Events:** Laravel Reverb (WebSocket Server) or Pusher
- **Authentication:** Laravel Sanctum (token-based API auth)
- **Storage:** Local filesystem in dev, AWS S3 / MinIO / compatible in production

Optional integrations (future-ready):

- Logging / Monitoring (e.g. Laravel Telescope, Sentry, etc.)
- CI/CD (GitHub Actions, GitLab CI)

---

## 👥 User Roles & Capabilities

The system uses **Role-Based Access Control (RBAC)** to restrict access and behavior.

### 1. Owner (Admin)

- **Dashboard**: Live sales, revenue, and key metrics  
- **Menu Management**: CRUD for categories, products, and modifiers  
- **Staff Management**: Create staff accounts, assign roles, PINs/credentials  
- **Table Layout**: Configure floor plans, table labels, QR tokens/URLs  
- **Settings**: Taxes, currency, service charges, opening hours, etc.

### 2. Staff / Manager

- **Operations Overview**: See all active tables and orders  
- **Table Assignment**: Assign/transfer tables between waiters  
- **Issue Handling**: Flag problematic orders or delays  
- **Basic Management**: Limited edits to menu availability / table config

### 3. Waiter

- **Order Taking**: Create orders linked to specific tables or clients  
- **Item Management**: Add/remove items, add notes/special instructions  
- **Status Updates**: Mark items as served / delivered  
- **Notifications**: Receive alerts when the kitchen marks items as "Ready"

### 4. Kitchen (KDS)

- **KDS Screen**: Real-time queue of incoming orders and items  
- **Item Status Control**: Mark items as `Pending`, `Cooking`, `Ready`  
- **Timing Awareness**: See order times and priority  
- **Quick Availability**: Toggle items as out-of-stock (86'ing items)

### 5. Cashier

- **Checkout**: Process payments (Cash, Card, Split bills, etc.)  
- **Order Review**: Verify items, discounts, and service charges  
- **Invoicing**: Generate and print/send receipts  
- **Refunds / Corrections**: Adjust or refund orders when required

### 6. Client (Guest)

- **Digital Menu**: QR-based access, no user account required (or anonymous auth)  
- **Self-Ordering**: Place orders directly from their device (if enabled for the restaurant)  
- **Live Order Tracking**: See order status in real-time (e.g. `Received`, `Cooking`, `Ready`)  
- **Feedback**: Optionally rate experience or leave a short review

---

## 🗄️ Database Schema (High Level)

### Core Entities

- **users**  
  System users (Owner, Staff, Waiter, Kitchen, Cashier), with role/permission mapping.

- **clients**  
  Guest users (often anonymous, identified by session, token, or device).

- **restaurants**  
  Restaurant profiles (name, logo, currency, settings).  
  *(Future-ready for multi-restaurant / multi-tenant setups.)*

- **tables**  
  Physical tables with mapping to restaurant and QR code data.  
  Fields: `restaurant_id`, `name`, `code`, `capacity`, `status`, `qr_token`.

- **categories** & **products**  
  Menu structure (categories: Starters, Mains, Drinks; products: items).  
  Fields: `name`, `description`, `price`, `is_available`, `image_url`.

- **orders**  
  Parent container for a dining session or ticket.  
  Relationships:
  - Belongs to **restaurant**
  - Belongs to **table** (optional for takeaway)
  - Belongs to **waiter** (nullable if self-ordered)
  - Belongs to **client** (nullable/anonymous)
  - Has many **order_items**

- **order_items**  
  Individual items in an order.  
  Fields: `order_id`, `product_id`, `qty`, `unit_price`, `total_price`, `status`, `notes`.

- **transactions**  
  Payment records linked to **orders**.  
  Fields: `order_id`, `amount`, `method`, `status`, `reference`, `paid_at`.

- **reviews** (optional, for clients)  
  Ratings and comments linked to an order or restaurant.

### Key Relationships Summary

- **Order** belongs to **Table** and **Waiter** (nullable for self-ordering)  
- **Order** has many **OrderItem**  
- **OrderItem** belongs to **Product**  
- **Order** has many **Transactions**  
- **Restaurant** has many **Tables**, **Products**, **Orders**, **Staff**

---

## 🔌 Real-Time Event Strategy

We use **Laravel Broadcasting** (Reverb or Pusher) to keep all clients updated **without polling**.

### Core Events

| Event Name           | Triggered When                                    | Typical Listeners / Consumers                          |
|----------------------|---------------------------------------------------|--------------------------------------------------------|
| `OrderCreated`       | Waiter/Client submits a new order                | Kitchen (KDS), Cashier dashboard, Owner dashboard      |
| `OrderUpdated`       | Order info or items are updated                  | Waiter, Client, Cashier, Manager                       |
| `ItemStatusUpdated`  | Kitchen marks item as `Cooking` or `Ready`       | Waiter device, Client device                           |
| `OrderStatusUpdated` | Order moves to `Completed` / `Cancelled`         | Owner/Manager, Analytics dashboard                     |
| `TableStatusChanged` | Table becomes `Occupied`, `Free`, or `NeedsCleaning` | Host/Staff overview screens                         |
| `CallWaiter`         | Client taps “Call Waiter” from their device      | Waiter phone/watch/tablet notifications                |

### Channel Strategy

- `private-restaurant.{restaurantId}.kitchen` – KDS & kitchen staff  
- `private-restaurant.{restaurantId}.waiters` – waiters assigned to that restaurant  
- `private-table.{tableId}` – client screen + assigned waiter  
- `private-order.{orderId}` – detailed order events (for clients/receipts/etc.)

---

