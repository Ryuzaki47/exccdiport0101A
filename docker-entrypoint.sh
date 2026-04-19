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

    echo "  → Clearing stale caches..."
    php artisan config:clear 2>&1 || true
    php artisan route:clear 2>&1 || true
    php artisan view:clear 2>&1 || true

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
# Seed database — forced if FORCE_RESEED=true, otherwise only if empty
# ---------------------------------------------------------------------------
echo "🌱 Checking database seed status..."
USER_COUNT=$(php artisan tinker --no-interaction --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1)
FORCE_RESEED="${FORCE_RESEED:-false}"

if [ "$FORCE_RESEED" = "true" ] || [ "$USER_COUNT" = "0" ] || [ -z "$USER_COUNT" ]; then
    if [ "$FORCE_RESEED" = "true" ]; then
        echo "  → FORCE_RESEED=true — wiping and reseeding..."
        php artisan tinker --no-interaction --execute="
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('workflow_approvals')->delete();
            DB::table('workflow_instances')->delete();
            DB::table('workflows')->delete();
            DB::table('accounting_transactions')->delete();
            DB::table('payments')->delete();
            DB::table('transactions')->delete();
            DB::table('student_payment_terms')->delete();
            DB::table('student_assessments')->delete();
            DB::table('student_enrollments')->delete();
            DB::table('students')->delete();
            DB::table('accounts')->delete();
            DB::table('fees')->delete();
            DB::table('notifications')->delete();
            DB::table('users')->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            echo 'cleared';
        " 2>&1 | tail -1
    else
        echo "  → Database is empty — running full DatabaseSeeder..."
    fi

    php artisan db:seed --class=DatabaseSeeder --force 2>&1 && echo "✓ DatabaseSeeder complete" || {
        echo "⚠️  WARNING: DatabaseSeeder failed — check logs above." >&2
    }
else
    echo "  → Database already has ${USER_COUNT} users — skipping seed."
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