#!/bin/bash
exec 1>&1
exec 2>&2

echo "🚀 Starting CCDI Account Portal..."

# ---------------------------------------------------------------------------
# Database connectivity check
# ---------------------------------------------------------------------------
DB_READY=false
echo "⏳ Waiting for database connection..."

for i in $(seq 1 30); do
    DB_CHECK_OUTPUT=$(php -r "
        \$host = getenv('DB_HOST') ?: '127.0.0.1';
        \$port = getenv('DB_PORT') ?: '3306';
        \$db   = getenv('DB_DATABASE') ?: 'forge';
        \$user = getenv('DB_USERNAME') ?: 'root';
        \$pass = getenv('DB_PASSWORD') ?: '';
        try {
            new PDO(\"mysql:host=\$host;port=\$port;dbname=\$db\", \$user, \$pass,
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            echo 'ok';
        } catch (Exception \$e) {
            fwrite(STDERR, \$e->getMessage() . PHP_EOL);
            exit(1);
        }
    " 2>&1)

    if [ "$DB_CHECK_OUTPUT" = "ok" ]; then
        echo "✓ Database is ready (attempt $i)"
        DB_READY=true
        break
    else
        echo "  Attempt $i/30 — database not ready: $DB_CHECK_OUTPUT"
        sleep 2
    fi
done

if [ "$DB_READY" = false ]; then
    echo "⚠️  WARNING: Database did not become ready after 30 attempts." >&2
    echo "⚠️  Continuing startup — the application will handle connection errors at runtime." >&2
fi

# ---------------------------------------------------------------------------
# Production Optimizations
# ---------------------------------------------------------------------------
if [ "$APP_ENV" = "production" ]; then
    echo "⚡ Production mode detected — optimizing caches..."

    echo "  → Caching configuration..."
    php artisan config:cache 2>&1 || {
        echo "⚠️  WARNING: config:cache failed, continuing anyway" >&2
    }

    echo "  → Caching routes..."
    php artisan route:cache 2>&1 || {
        echo "⚠️  WARNING: route:cache failed, continuing anyway" >&2
    }

    echo "  → Pre-compiling views..."
    php artisan view:cache 2>&1 || {
        echo "⚠️  WARNING: view:cache failed, continuing anyway" >&2
    }
fi

# ---------------------------------------------------------------------------
# Migrations
# ---------------------------------------------------------------------------
echo "📦 Running migrations..."
if php artisan migrate --force 2>&1; then
    echo "✓ Migrations complete"
else
    echo "⚠️  ERROR: Migrations failed. Check logs above." >&2
    echo "⚠️  Continuing startup — application may be degraded." >&2
fi

# ---------------------------------------------------------------------------
# Seed fee_settings if the table is empty (idempotent — safe on re-deploy)
# ---------------------------------------------------------------------------
echo "💰 Checking fee_settings seed..."
FEE_COUNT=$(php artisan tinker --no-interaction --execute="echo \App\Models\FeeSetting::count();" 2>/dev/null | tail -1)

if [ "$FEE_COUNT" = "0" ] || [ -z "$FEE_COUNT" ]; then
    echo "  → fee_settings is empty — running FeeSettingsSeeder..."
    php artisan db:seed --class=FeeSettingsSeeder --force 2>&1 && echo "✓ FeeSettingsSeeder complete" || {
        echo "⚠️  WARNING: FeeSettingsSeeder failed." >&2
    }
else
    echo "  → fee_settings already has ${FEE_COUNT} rows — skipping seed."
fi

# ---------------------------------------------------------------------------
# Storage link
# ---------------------------------------------------------------------------
echo "🔗 Creating storage link..."
php artisan storage:link 2>&1 || {
    echo "⚠️  Storage link already exists or failed (harmless)"
}

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

OCTANE_SERVER="${OCTANE_SERVER:-frankenphp}"
echo "✓ Setup complete! Starting Octane (${OCTANE_SERVER}) on port ${PORT:-8080}..."

# ---------------------------------------------------------------------------
# Start Octane
# ---------------------------------------------------------------------------
exec php artisan octane:start \
    --server="${OCTANE_SERVER}" \
    --host=0.0.0.0 \
    --port=${PORT:-8080}