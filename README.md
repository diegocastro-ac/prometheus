# Prometheus

## ✨ Tech Stack

- PHP ^8.2
- Laravel ^12.0
- Filament ^3.3
- Livewire ^3.0

## 🛠️ Setup

1. Clone this repository

2. Copy `.env` file and update with your local values
   ```bash
   cp .env.example .env
   ```

3. Install Composer dependencies
   ```bash
   composer install
   ```

4. Install Node dependencies
   ```bash
   npm install
   ```

5. Run migrations
   ```bash
   php artisan migrate --seed
   ```

6. Get your local app key
   ```bash
   php artisan key:generate
   ```

7. Create symbolic link to access files
   ```bash
   php artisan storage:link
   ```

## 🏃‍♀️ Run the app

1. Open your terminal and run Vite
   ```bash
   npm run dev
   ```

2. In a different terminal window/tab run Laravel
   ```bash
   php artisan serve
   ```

3. Open your browser and go to http://127.0.0.1:8000/dashboard

4. You can log in with the following information:
   ```bash
   Email: admin@admin.com
   Password: admin
   ```
