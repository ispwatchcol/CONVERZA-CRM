-- ============================================================================
-- Schemas de Converza dentro del mismo Postgres de Supabase de ispwatch.
-- Ya ejecutado en el proyecto wusbteybfrmsjuuwzljl el 2026-05-24.
-- Guardado aquí para reproducibilidad si hay que recrear el ambiente.
-- ============================================================================

-- converza:      schema productivo. Apunta DB_SEARCH_PATH=converza en .env de prod.
-- converza_dev:  RETIRADO el 2026-11-19 (nadie lo usa; no se migra más). Era el
--                schema de desarrollo local. Tras la mudanza del 2026-11-02 al
--                Supabase propio quedó solo en el proyecto viejo. Si se recrea el
--                ambiente, basta con `converza`.

CREATE SCHEMA IF NOT EXISTS converza;
-- CREATE SCHEMA IF NOT EXISTS converza_dev;   -- retirado 2026-11-19

-- Verificar:
-- SELECT schema_name FROM information_schema.schemata
-- WHERE schema_name IN ('converza','converza_dev','public');
