# BuildingCare Lite

Lightweight WordPress apartment management for building owners and managers. Track flats, residents, monthly service charges, expenses, payments, and balance sheets — without custom database tables or external frameworks.

**Author:** [MagePeople Team](https://mage-people.com/)  
**Version:** 1.4.0  
**License:** GPL v2 or later

---

## Features

- **Building & flat management** — Register buildings, flats, floor details, occupancy status, and monthly service charges
- **Resident records** — Contact details, move-in/out dates, and flat assignments
- **Automated billing** — Generate monthly bills for all flats; vacant flats billed at 50% service charge
- **Payment collection** — Record full or partial payments from the Bills & Payments screen
- **Expense tracking** — One-time expenses with categories and attachments
- **Recurring expenses** — Monthly vouchers auto-generated from recurring expense templates
- **Dashboard** — Income, expenses, closing balance, and outstanding dues at a glance
- **Reports** — Collection, flat-wise, resident-wise, due, expense, and income vs expense reports with CSV export
- **User roles** — Building Admin and Manager with scoped capabilities
- **REST API** — Endpoints for dashboard, bills, flats, residents, expenses, reports, and audit log
- **Audit log** — Tracks bill generation, payments, exports, and cron activity
- **WordPress-native storage** — Custom Post Types, taxonomies, and post meta only

---

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress   | 6.0+    |
| PHP         | 8.2+    |

No WooCommerce, Node.js, or build tools required.

---

## Installation

1. Download or clone this repository into your WordPress plugins directory:

   ```
   wp-content/plugins/buildingcare-lite/
   ```

2. Activate **BuildingCare Lite** from **Plugins** in the WordPress admin.

3. On activation, the plugin will:
   - Register custom post types and roles
   - Seed default expense categories
   - Schedule monthly cron tasks

4. Open **BuildingCare → Dashboard** from the admin sidebar to get started.

---

## Quick Start

### 1. Configure settings

Go to **BuildingCare → Settings** and set:

- **Opening balance** — Starting fund balance for reports
- **Currency symbol** — Default is `৳`
- **Default building** — Optional default for new records
- **Vacant flat charge (%)** — Percentage of the service charge billed to vacant flats (default 50%)
- **Payment due day** — Day of the billing month payment is due (1–28)
- **Late fee** — Fixed amount or percent of the overdue balance, applied when a bill carries debt forward
- **Email notifications** — Opt-in payment receipts and daily due reminders to residents
- **Data cleanup** — Optionally delete all plugin data on uninstall

### 2. Add your data

1. **Buildings** — Create each property with address and floor count
2. **Flats** — Add flats under a building with flat number, size, service charge, and occupancy
3. **Residents** — Add tenant details and assign each resident to a flat

### 3. Generate bills

Bills are **not** created manually via Add New.

1. Go to **BuildingCare → Bills & Payments**
2. Select the billing month
3. Click **Generate Monthly Bills**

The plugin creates one bill per flat. Billing rules:

| Occupancy | Service charge |
|-----------|----------------|
| Occupied  | 100%           |
| Vacant    | 50%            |

Previous unpaid amounts are rolled into the new bill automatically.

### 4. Collect payments

From **Bills & Payments**, use **Collect** or **Record Payment** on each row. Supported methods: Cash, Bank Transfer, Mobile Banking.

### 5. Track expenses

- **Expenses** — Record one-time building costs with category and receipt attachment
- **Recurring Expenses** — Define monthly items (e.g. cleaner salary); vouchers are generated automatically

### 6. View reports

**BuildingCare → Reports** provides filtered reports with CSV export for any date range.

---

## Admin Interface

BuildingCare adds a **single** admin menu item — **BuildingCare** — that opens a tabbed dashboard. All features are tabs inside this one page (no separate submenus):

| Tab                  | Description                              |
|----------------------|------------------------------------------|
| Dashboard            | Monthly overview, balance sheet, income vs expense chart |
| Buildings            | Create/edit/delete buildings in-place    |
| Flats                | Create/edit/delete flats in-place        |
| Residents            | Create/edit/delete residents in-place    |
| Bills & Payments     | Generate bills and collect payments      |
| Expenses             | One-time expense entries                 |
| Recurring Expenses   | Monthly expense templates                |
| Reports              | Analytics and CSV export                 |
| Settings             | Balance, currency, billing rules, emails |
| Audit Log            | Activity history                         |

Tabs are shown only for the capabilities the current user holds.

---

## User Roles

### Building Admin

Full access to buildings, flats, residents, bills, expenses, reports, and settings.

| Capability            | Access |
|-----------------------|--------|
| `bc_manage_buildings` | Buildings |
| `bc_manage_flats`     | Flats |
| `bc_manage_residents` | Residents |
| `bc_generate_bills`   | Generate bills, view All Bills |
| `bc_manage_payments`  | Record payments |
| `bc_manage_expenses`  | Expenses & recurring expenses |
| `bc_view_reports`     | Dashboard & reports |
| `bc_manage_settings`  | Settings & audit log |

### Manager

Operational access for day-to-day collections and expenses.

| Capability            | Access |
|-----------------------|--------|
| `bc_manage_payments`  | Bills & Payments |
| `bc_manage_expenses`  | Expenses & recurring expenses |
| `bc_view_reports`     | Dashboard & reports |

WordPress **Administrators** receive all BuildingCare capabilities automatically.

> **Note:** Roles are site-wide. Per-building user assignment is not included in this version.

---

## Data Model

### Custom Post Types

| Post type              | Purpose                        |
|------------------------|--------------------------------|
| `bc_building`          | Property / building            |
| `bc_flat`              | Individual flat or unit        |
| `bc_resident`          | Tenant or owner record         |
| `bc_bill`              | Monthly service charge bill    |
| `bc_expense`           | One-time expense voucher       |
| `bc_recurring_expense` | Recurring monthly expense      |
| `bc_payment`           | Immutable payment ledger entry |

### Taxonomy

| Taxonomy              | Used by                              |
|-----------------------|--------------------------------------|
| `bc_expense_category` | `bc_expense`, `bc_recurring_expense` |

Default categories are seeded on activation: Lift Maintenance, Staff Salary, Cleaner Salary, Generator Expense, Electricity, Water, Internet, Repairs, Miscellaneous.

### Key meta fields

| Entity    | Notable fields |
|-----------|----------------|
| Building  | `bc_address`, `bc_total_floors`, `bc_status` |
| Flat      | `bc_building_id`, `bc_flat_number`, `bc_monthly_service_charge`, `bc_occupancy_status` |
| Resident  | `bc_mobile`, `bc_email`, `bc_assigned_flat_id`, `bc_move_in_date` |
| Bill      | `bc_billing_month`, `bc_service_charge_amount`, `bc_previous_due_amount`, `bc_total_payable_amount`, `bc_payment_status` |
| Expense   | `bc_amount`, `bc_expense_date`, `bc_is_paid`, `bc_attachment_id` |

---

## Automation

A WordPress cron event (`bcl_monthly_tasks`) runs on the **first day of each month** and:

1. Generates bills for the current billing month
2. Creates expense vouchers from active recurring expenses

You can also trigger bill generation manually from **Bills & Payments** at any time.

When reminders are enabled, a second daily cron event (`bcl_daily_reminders`) emails residents whose bills are due today or overdue and still unpaid.

---

## Reports

| Report type         | Description |
|---------------------|-------------|
| `collection`        | Monthly collection summary |
| `flat_wise`         | Per-flat billing and payment breakdown |
| `resident_wise`     | Per-resident payment history |
| `due`               | Outstanding dues |
| `expense`           | Monthly expense totals |
| `income_vs_expense` | Income and expense comparison |

Reports support current month, last 6/12 months, or a custom date range. Export any report to CSV from the Reports page.

---

## REST API

Namespace: `buildingcare-lite/v1`

All endpoints require an authenticated WordPress user with the appropriate capability.

| Method | Endpoint | Capability | Description |
|--------|----------|------------|-------------|
| GET    | `/dashboard` | `bc_view_reports` | Dashboard statistics |
| GET    | `/bills` | `bc_manage_payments` | List bills |
| POST   | `/bills/{id}/payment` | `bc_manage_payments` | Record a payment |
| GET    | `/flats` | `bc_view_reports` | List flats |
| GET    | `/residents` | `bc_view_reports` | List residents |
| GET    | `/expenses` | `bc_manage_expenses` | List expenses |
| GET    | `/reports/{type}` | `bc_view_reports` | Generate a report |
| GET    | `/audit-log` | `bc_manage_settings` | Activity log |

**Example:**

```
GET /wp-json/buildingcare-lite/v1/dashboard?month=2026-06
```

List endpoints support `page`, `per_page`, and `search` query parameters.

---

## Plugin Structure

```
buildingcare-lite/
├── buildingcare-lite.php      # Main plugin bootstrap
├── uninstall.php              # Cleanup on uninstall
├── README.md
├── assets/
│   ├── css/admin.css          # Admin UI styles
│   └── js/admin.js            # Admin interactions (vanilla JS)
├── includes/
│   ├── helpers.php            # Shared helper functions
│   ├── class-loader.php       # Singleton loader
│   ├── class-post-types.php   # CPT & taxonomy registration
│   ├── class-roles.php        # Roles & capabilities
│   ├── class-meta-boxes.php   # Admin meta boxes
│   ├── class-admin-pages.php  # Menus, dashboard, list tables
│   ├── class-posts-list-table.php
│   ├── class-billing.php      # Bill generation & payments
│   ├── class-expenses.php     # Recurring expense vouchers
│   ├── class-cron.php         # Monthly automation
│   ├── class-reports.php      # Report calculations
│   ├── class-export.php       # CSV export
│   └── class-rest-api.php     # REST endpoints
└── languages/                 # Translation files
```

---

## Development

Built with:

- PHP 8.2+ with strict types and namespaces (`BuildingCareLite\`)
- WordPress Coding Standards
- Vanilla JavaScript and CSS (no React, Vue, or build pipeline)
- `WP_List_Table` for admin list screens
- WordPress Settings API for configuration

### Local setup

```bash
git clone https://github.com/magepeopleteam/buildingcare-lite.git wp-content/plugins/buildingcare-lite
```

Activate the plugin in WordPress and assign the **Building Admin** role to a test user.

---

## Uninstall

When the plugin is deleted (not just deactivated), `uninstall.php` removes:

- `bcl_settings` and `bcl_audit_log` options
- BuildingCare transients

Custom post type data is **not** deleted automatically. Remove posts manually before uninstalling if you need a clean database.

---

## Support

For questions, feature requests, or commercial support, visit [MagePeople](https://mage-people.com/).

---

## Changelog

### 1.4.0

- **Tenant portal** — A front-end portal at `/tenant/` where residents log in to view their own flat's outstanding dues, bills, payment history, downloadable/printable receipts, and profile. Strictly scoped to the logged-in tenant's data.
- **Automatic tenant accounts** — When a resident with an email address is saved, a restricted `building_tenant` WordPress user is created (or an existing user is linked) and emailed a set-password link. Tenants cannot access wp-admin and are redirected to the portal.
- **Installable tenant app** — The portal ships its own PWA manifest and registers the service worker, so tenants can install it on their phone like an app.
- **Tenants can edit their contact details** (mobile, emergency contact) from the portal profile tab.
- Admin dashboard shows the tenant portal URL plus a **"Create logins for all residents"** button to bulk-provision accounts for existing residents.

### 1.3.0

- **Installable PWA** — The dashboard can be installed as an app on phones and desktops. Adds a web app manifest, a scoped service worker (served from query-string endpoints so it works with any permalink setup), app icons (including a maskable icon), an offline fallback page, and an **Install app** button that appears when installation is available.

### 1.2.0

- **Single-page tabbed admin** — Everything now lives under one **BuildingCare** menu item. Overview, Buildings, Flats, Residents, Bills & Payments, Expenses, Recurring Expenses, Reports, Settings, and Audit Log are tabs inside one page; the separate submenus were removed.
- Buildings, flats, residents, expenses, and recurring expenses are created, edited, and deleted **inside the dashboard** (searchable, paginated lists with inline add/edit forms) — no more jumping to native post screens. Saves reuse the existing validation/occupancy logic.
- Professional tabbed UI with refreshed list, form, and table styling.
- **Instant AJAX tab switching** — changing tabs, searching, filtering, paginating, and one-click payments update the panel in place (no full page reload), with browser back/forward support.
- **Fully mobile responsive** — scrollable tab bar, stacked headers/filters/forms, and horizontally scrollable tables on small screens.

### 1.1.0

**Correctness fixes**

- Fixed outstanding dues being double-counted: prior unpaid balances are now carried forward (and zeroed on the old bill) when a new bill rolls them in.
- Income is now tracked through an immutable payment ledger (`bc_payment`), so partial payments are attributed to their real payment dates instead of a single overwritten value.
- Centralized bill payment-state recalculation into one helper used by both manual edits and recorded payments.
- Fixed a `$wpdb->prepare()` call made without placeholders.
- Fixed a fatal in the Audit Log when an entry's user had been deleted.
- One-click collection now records the chosen payment method instead of always "Cash".
- Removed N+1 queries from the flat-wise and resident-wise reports (single aggregated query each).

**New features**

- Payment ledger with per-bill payment history shown on the bill screen.
- Configurable billing rules: vacant-flat charge percentage, payment due day, and late fees (fixed or percent of overdue).
- Automatic late fee applied when a new bill carries an unpaid balance forward.
- Email notifications: payment receipts to residents and a daily due/overdue reminder cron (both opt-in).
- Dashboard income-vs-expense chart for the last 6 months.
- Optional "delete all data on uninstall" setting.
- Version-gated upgrade routine that refreshes roles and cron after updates.

### 1.0.0

- Initial release
- Building, flat, resident, bill, and expense management
- Monthly billing automation with vacant-flat discount
- Dashboard, reports, CSV export, and REST API
- Building Admin and Manager roles
