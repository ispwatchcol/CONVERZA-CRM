-- ============================================================================
-- HARDENING DE PERMISOS (Postgres / Supabase) — NO CORRER AÚN.
-- ============================================================================
-- Hoy Converza usa el rol postgres (service role, permisos completos) tanto
-- para sus propias tablas en schema `converza` como para leer ispwatch en
-- schema `public`. Esto funciona pero es riesgoso: un bug en Converza podría
-- modificar/borrar datos de ispwatch.
--
-- Este script crea dos roles dedicados con permisos mínimos. Aplicar cuando
-- Converza esté estable y antes de subir clientes reales a producción:
--   1. Pegar y ejecutar este SQL en el SQL Editor de Supabase
--   2. En el .env de Converza, reemplazar DB_USERNAME/DB_PASSWORD por las del
--      rol converza_app, y ISPWATCH_DB_USERNAME/PASSWORD por converza_reader
--   3. Verificar que la app sigue funcionando (login, listar contactos, etc.)
-- ============================================================================

-- === Rol 1: converza_app ====================================================
-- Lo usa la app Converza para sus propias tablas. DDL + DML en schema converza,
-- nada de acceso a ispwatch (eso es trabajo del otro rol).

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'converza_app') THEN
        CREATE ROLE converza_app WITH LOGIN PASSWORD 'CAMBIA_ESTA_CLAVE_PROD';
    END IF;
END $$;

GRANT CONNECT ON DATABASE postgres TO converza_app;
GRANT USAGE, CREATE ON SCHEMA converza TO converza_app;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA converza TO converza_app;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA converza TO converza_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA converza
    GRANT ALL PRIVILEGES ON TABLES TO converza_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA converza
    GRANT ALL PRIVILEGES ON SEQUENCES TO converza_app;

-- === Rol 2: converza_reader =================================================
-- Lo usa la conexión `ispwatch` de Converza. SELECT-only sobre el schema
-- public de ispwatch. NO debe ver nada de converza (separación clara).

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'converza_reader') THEN
        CREATE ROLE converza_reader WITH LOGIN PASSWORD 'CAMBIA_ESTA_CLAVE_READER';
    END IF;
END $$;

GRANT CONNECT ON DATABASE postgres TO converza_reader;
GRANT USAGE ON SCHEMA public TO converza_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO converza_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO converza_reader;

-- Verificar permisos otorgados:
-- SELECT grantee, table_schema, COUNT(*) AS tables, string_agg(DISTINCT privilege_type, ', ')
-- FROM information_schema.role_table_grants
-- WHERE grantee IN ('converza_app', 'converza_reader')
-- GROUP BY grantee, table_schema
-- ORDER BY grantee, table_schema;
