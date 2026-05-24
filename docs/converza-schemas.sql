-- ============================================================================
-- Schemas de Converza dentro del mismo Postgres de Supabase de ispwatch.
-- Ya ejecutado en el proyecto wusbteybfrmsjuuwzljl el 2026-05-24.
-- Guardado aquí para reproducibilidad si hay que recrear el ambiente.
-- ============================================================================

-- converza:      schema productivo. Apunta DB_SEARCH_PATH=converza en .env de prod.
-- converza_dev:  schema de desarrollo local. Apunta DB_SEARCH_PATH=converza_dev en .env local.

CREATE SCHEMA IF NOT EXISTS converza;
CREATE SCHEMA IF NOT EXISTS converza_dev;

-- Verificar:
-- SELECT schema_name FROM information_schema.schemata
-- WHERE schema_name IN ('converza','converza_dev','public');
