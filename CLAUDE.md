# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Farmasi EMS** is a PHP-based Employee Management System for a hospital pharmacy department. It handles:
- Medical staff attendance (online/offline status with sessions)
- Pharmacy sales tracking and reporting
- Weekly salary calculations (40% bonus from sales)
- Recruitment/candidate management with AI psychometric scoring
- Event management and reimbursement processing
- Push notifications via Service Worker

## Architecture

### Directory Structure

```
├── auth/           # Authentication (login, session, CSRF)
├── actions/        # AJAX handlers for frontend interactions
├── ajax/           # Specialized AJAX endpoints
├── api/            # External API endpoints
├── config/         # Configuration files (database, helpers, date ranges)
├── cron/           # Scheduled tasks (salary generation, cleanup, status checks)
├── dashboard/      # Main application pages (protected)
├── helpers/        # Session helper functions
├── partials/       # Reusable UI components (header, footer, sidebar)
├── public/         # Public assets (images, uploaded files)
└── storage/        # Logs and uploaded documents
```

### Key Patterns

**Database Connection**: All pages include `config/database.php` which provides a PDO connection. Always use prepared statements.

**Authentication**: Protected pages use `auth/auth_guard.php` which:
- Starts session if not active
- Loads session via `helpers/session_helper.php`
- Validates "remember me" tokens from cookies
- Redirects to login if unauthorized

**User Session**: Stored in `$_SESSION['user_rh']` with keys:
- `id`, `name`/`full_name`, `role`, `position`
- `batch`, `tanggal_masuk`, `citizen_id`, `no_hp_ic`
- `jenis_kelamin`, `kode_nomor_induk_rs`

**Flash Messages**: Use `$_SESSION['flash_messages']`, `$_SESSION['flash_warnings']`, `$_SESSION['flash_errors']` arrays for temporary messages.

**Helper Functions** (in `config/helpers.php`):
- `initialsFromName()` - Generate avatar initials
- `avatarColorFromName()` - Generate consistent avatar color
- `formatTanggalID()` - Format dates in Indonesian
- `safeRegulation()` - Check medical regulation pricing

## Development Commands

### Composer Dependencies
```bash
composer install
```

### Cron Jobs
Located in `cron/` directory. Key tasks:
- `generate_weekly_salary.php` - Calculate weekly salaries from sales data
- `check_farmasi_online.php` - Monitor farmasi online status
- `cron_cleanup_identity_temp.php` - Clean temporary identity files
- `test_push_cron.php` - Test push notification system

### Testing Locally
This is a traditional PHP application. Use a local PHP server:
```bash
php -S localhost:8000
```

Or deploy to hosting with PHP + MySQL support.

## Core Business Logic

### Farmasi/Pharmacy Module
- Staff toggle online/offline via `actions/toggle_farmasi_status.php`
- Sessions tracked in `user_farmasi_sessions` table
- Sales recorded via `dashboard/ems_services.php`
- Reports generated in `dashboard/rekap_farmasi.php`

### Salary Calculation
- Weekly periods (Monday-Sunday)
- Calculated from `sales` table aggregation
- Bonus = 40% of total sales value
- Stored in `salary` table

### Recruitment Module
- AI psychometric scoring in `actions/ai_scoring_engine.php`
- Six dimensions: Focus, Social, Obedience, Consistency, Emotional Stability, Honesty
- Multi-interviewer hybrid scoring

## Important Notes

### Access Control
- Position "trainee" is blocked from accessing `rekap_farmasi.php`
- Always check user position before allowing sensitive operations

### Date/Time
- All times in `Asia/Jakarta` timezone
- Date range handling via `config/date_range.php`

### Push Notifications
- Service Worker: `sw.js`
- Subscription saved via `actions/save_push_subscription.php`
- Web Push library: `minishlink/web-push` (v10.0)

### Error Logging
- Production-safe error logging configured in dashboard pages
- Logs stored in `storage/error_log.txt`
- Never display errors to end users

### Security
- PIN-based authentication (password_verify)
- CSRF tokens in `auth/csrf.php`
- Remember me tokens with expiration
- Input validation via prepared statements
