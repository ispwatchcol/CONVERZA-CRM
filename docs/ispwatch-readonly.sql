-- Crea un rol Postgres con permisos SELECT-only para que Converza lea
-- la base de ispwatch sin riesgo de modificar datos.
--
-- Cómo correr: en el SQL Editor de Supabase de ispwatch (proyecto wusbteybfrmsjuuwzljl),
-- pega y ejecuta. Después usa estas credenciales en el .env de Converza:
--   ISPWATCH_DB_USERNAME=converza_reader
--   ISPWATCH_DB_PASSWORD=<la-clave-que-pongas-abajo>

-- 1. Crear el rol con su clave.
CREATE ROLE converza_reader WITH LOGIN PASSWORD 'CAMBIA_ESTA_CLAVE_FUERTE';

-- 2. Permitir conectarse a la base.
GRANT CONNECT ON DATABASE postgres TO converza_reader;

-- 3. Permitir ver el schema public (donde Laravel pone las tablas por defecto).
GRANT USAGE ON SCHEMA public TO converza_reader;

-- 4. Otorgar SELECT sobre todas las tablas existentes del schema public.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO converza_reader;

-- 5. Otorgar SELECT automáticamente sobre tablas futuras que cree ispwatch.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO converza_reader;

-- 6. (Opcional, recomendado) Si ispwatch tiene RLS (Row Level Security) activado
-- en alguna tabla, el rol converza_reader respeta esas políticas. Si quieres
-- bypass total para Converza:
-- ALTER ROLE converza_reader BYPASSRLS;

-- 7. Verificar.
-- SELECT * FROM information_schema.role_table_grants
-- WHERE grantee = 'converza_reader' LIMIT 10;
